<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Make;

use InvalidArgumentException;
use Kipchak\Cli\Scaffold\ChangeSet;
use Kipchak\Cli\Scaffold\FileChange;
use Kipchak\Cli\Scaffold\Project;

/**
 * The change sets of make:dto, make:controller and make:resource (SR-2026-012 §3.3.3, as clarified in 1.3.2). Reads
 * nothing from disk except the templates.
 */
final class MakeGenerators
{
    /**
     * @param list<Field> $fields
     */
    public function dto(Project $project, Resource $resource, array $fields, string $version = 'v1'): ChangeSet
    {
        $names = new Names($project, $version);
        $path = $names->directory('DataTransferObjects') . $resource->dto . '.php';

        return new ChangeSet([FileChange::create($path, Templates::dto($names->namespace('DataTransferObjects'), $resource, $fields))]);
    }

    public function controller(Project $project, string $controller, string $version = 'v1'): ChangeSet
    {
        Names::className($controller, 'controller');
        $names = new Names($project, $version);

        return new ChangeSet([FileChange::create(
            $names->directory('Controllers') . $controller . '.php',
            Templates::render('controller-empty.php', [
                'namespace' => $names->namespace('Controllers'),
                'controller' => $controller,
                'tagDescription' => ucfirst(Field::words($controller)) . '.',
            ]),
        )]);
    }

    /**
     * The DTO, model, controller, route file and test of a resource.
     *
     * @param list<Field> $fields
     */
    public function resource(Project $project, Resource $resource, array $fields, string $version = 'v1'): ChangeSet
    {
        $this->checkResourceFields($fields);
        $names = new Names($project, $version);
        $values = Templates::resourceValues($resource, $version);
        $crud = $resource->crud;

        $dtoNamespace = $names->namespace('DataTransferObjects');
        $modelNamespace = $names->namespace('Models', versioned: false);
        $controllerNamespace = $names->namespace('Controllers');

        $model = Templates::render('model.php', $values + [
            'namespace' => $modelNamespace,
            'model' => $resource->controller,
            'dtoClass' => "{$dtoNamespace}\\{$resource->dto}",
            'crudUses' => $crud ? "use CuyZ\\Valinor\\Mapper\\MappingError;\n" : '',
            'rows' => implode("\n", [$this->row($fields, 1, '        ') . ',', $this->row($fields, 2, '        ') . ',']),
            'crudMethods' => $crud ? Templates::render('model-crud-methods.php', $values) : '',
        ]);

        $controller = Templates::render('controller.php', $values + [
            'namespace' => $controllerNamespace,
            'modelClass' => "{$modelNamespace}\\{$resource->controller}",
            'crudUsesBefore' => $crud ? "use CuyZ\\Valinor\\Mapper\\MappingError;\nuse JsonException;\n" : '',
            'crudUsesAfter' => $crud ? "use Slim\\Exception\\HttpBadRequestException;\n" : '',
            'crudMethods' => $crud ? Templates::render('controller-crud-methods.php', $values) : '',
        ]);

        $controllers = "Controllers\\{$version}\\{$resource->controller}::class";
        $routes = Templates::render('routes.php', $values + [
            'controllersNamespace' => $names->namespace('Controllers', versioned: false),
            'crudRoutes' => $crud ? implode('', [
                "    \$group->post('/{$resource->segment}', [{$controllers}, 'create']);\n",
                "    \$group->put('/{$resource->segment}/{id:[0-9]+}', [{$controllers}, 'update']);\n",
                "    \$group->delete('/{$resource->segment}/{id:[0-9]+}', [{$controllers}, 'delete']);\n",
            ]) : '',
        ]);

        $first = $this->firstNonIdField($fields);
        $test = Templates::render('test.php', $values + [
            'secondRow' => $this->row($fields, 2),
            'firstField' => $first?->name ?? 'id',
            'firstValue' => $first?->examplePhp(1) ?? '1',
            'crudUses' => $crud ? "\nuse Psr\\Http\\Message\\ResponseInterface;\nuse Slim\\Psr7\\Factory\\ServerRequestFactory;\nuse Slim\\Psr7\\Factory\\StreamFactory;\n" : '',
            'crudTests' => $crud ? Templates::render('test-crud.php', $values + ['newRow' => $this->row($fields, 3, withId: false)]) : '',
        ]);

        $changes = [
            FileChange::create($names->directory('DataTransferObjects') . $resource->dto . '.php', Templates::dto($dtoNamespace, $resource, $fields)),
            FileChange::create($names->directory('Models', versioned: false) . $resource->controller . '.php', $model),
            FileChange::create($names->directory('Controllers') . $resource->controller . '.php', $controller),
            FileChange::create("routes/{$version}/{$resource->segment}.php", $routes),
            FileChange::create("tests/{$resource->controller}Test.php", $test),
        ];

        return new ChangeSet($changes, $this->messages($project, $resource));
    }

    /**
     * @param list<Field> $fields
     */
    private function checkResourceFields(array $fields): void
    {
        $id = array_values(array_filter($fields, static fn (Field $field): bool => $field->name === 'id'));
        if ($id === [] || $id[0]->type !== 'int' || $id[0]->nullable) {
            throw new InvalidArgumentException('A resource needs an identifier: include id:int in --fields.');
        }
        foreach ($fields as $field) {
            if ($field->isDto() && !$field->list && !$field->nullable) {
                throw new InvalidArgumentException(
                    "{$field->name}: a resource's example rows cannot hold another DTO. Make it ?{$field->type} or {$field->type}[], or add it with make:dto."
                );
            }
        }
    }

    /**
     * One example row as a PHP array literal, from each field's example value.
     *
     * @param list<Field> $fields
     */
    private function row(array $fields, int $row, string $indent = '', bool $withId = true): string
    {
        $pairs = [];
        foreach ($fields as $field) {
            if ($field->name === 'id' && !$withId) {
                continue;
            }
            $pairs[] = var_export($field->name, true) . ' => ' . ($field->isDto() ? ($field->list ? '[]' : 'null') : $field->examplePhp($row));
        }

        return $indent . '[' . implode(', ', $pairs) . ']';
    }

    /**
     * @param list<Field> $fields
     */
    private function firstNonIdField(array $fields): ?Field
    {
        foreach ($fields as $field) {
            if ($field->name !== 'id' && !$field->isDto()) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function messages(Project $project, Resource $resource): array
    {
        $messages = [];
        $openApiTest = $project->file('tests/OpenApiTest.php');
        if ($openApiTest !== null && !str_contains($openApiTest, 'getRouteCollector')) {
            $messages[] = 'tests/OpenApiTest.php lists the expected paths: add the new paths there, or replace it with the '
                . 'route-based test from starter 4.2.';
        }
        $apiTestCase = $project->file('tests/ApiTestCase.php');
        if ($resource->crud && $apiTestCase !== null && !str_contains($apiTestCase, 'function app(')) {
            $messages[] = "tests/{$resource->controller}Test.php uses ApiTestCase::app(), which starter 4.1 added: copy it "
                . 'from https://1x.ax/mamluk/kipchak/starter/~files/master/tests/ApiTestCase.php.';
        }

        return $messages;
    }
}
