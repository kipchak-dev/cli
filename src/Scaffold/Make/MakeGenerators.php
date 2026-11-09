<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Make;

use InvalidArgumentException;
use Kipchak\Cli\Scaffold\ChangeSet;
use Kipchak\Cli\Scaffold\FileChange;
use Kipchak\Cli\Scaffold\Project;
use Kipchak\Cli\Scaffold\SourceFile;

/**
 * The change sets of the make: commands (SR-2026-012 §3.3.3, as clarified in 1.3.2, and §3.3.4). Reads
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

        return new ChangeSet([FileChange::create($path, Templates::dto($names->namespace('DataTransferObjects'), $resource, $fields, $project->hasInputValidation()))]);
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
    public function resource(Project $project, Resource $resource, array $fields, string $version = 'v1', ?ResponseStyle $style = null): ChangeSet
    {
        $this->checkResourceFields($fields);
        $names = new Names($project, $version);
        $values = Templates::resourceValues($resource, $version) + self::responseValues($style ?? ResponseStyle::longForm(), $resource);
        $crud = $resource->crud;
        $input = $project->hasInputValidation();

        $dtoNamespace = $names->namespace('DataTransferObjects');
        $modelNamespace = $names->namespace('Models', versioned: false);
        $controllerNamespace = $names->namespace('Controllers');

        $model = Templates::render('model.php', $values + [
            'namespace' => $modelNamespace,
            'model' => $resource->controller,
            'dtoClass' => "{$dtoNamespace}\\{$resource->dto}",
            'crudUses' => $crud && !$input ? "use CuyZ\\Valinor\\Mapper\\MappingError;\n" : '',
            'rows' => implode("\n", [$this->row($fields, 1, '        ') . ',', $this->row($fields, 2, '        ') . ',']),
            'crudMethods' => $crud ? Templates::render($input ? 'model-crud-methods-input.php' : 'model-crud-methods.php', $values) : '',
        ]);

        $controller = Templates::render('controller.php', $values + [
            'namespace' => $controllerNamespace,
            'modelClass' => "{$modelNamespace}\\{$resource->controller}",
            'dtoUse' => $crud && $input ? "use {$dtoNamespace}\\{$resource->dto};\n" : '',
            'crudUsesBefore' => $crud && !$input ? "use CuyZ\\Valinor\\Mapper\\MappingError;\nuse JsonException;\n" : '',
            'crudUsesInput' => $crud && $input ? "use Kipchak\\Core\\Components\\Dto\\Input;\n" : '',
            'crudUsesAfter' => $crud && !$input ? "use Slim\\Exception\\HttpBadRequestException;\n" : '',
            'crudMethods' => $crud ? Templates::render($input ? 'controller-crud-methods-input.php' : 'controller-crud-methods.php', $values) : '',
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
            FileChange::create($names->directory('DataTransferObjects') . $resource->dto . '.php', Templates::dto($dtoNamespace, $resource, $fields, $input)),
            FileChange::create($names->directory('Models', versioned: false) . $resource->controller . '.php', $model),
            FileChange::create($names->directory('Controllers') . $resource->controller . '.php', $controller),
            FileChange::create("routes/{$version}/{$resource->segment}.php", $routes),
            FileChange::create("tests/{$resource->controller}Test.php", $test),
        ];

        return new ChangeSet($changes, $this->messages($project, $resource));
    }

    /**
     * The files make:endpoint reads: the controller, and every route file of the version, to find the one that routes
     * the controller already.
     *
     * @param list<string> $routeFiles paths of routes/<version>/*.php
     * @return list<string>
     */
    public static function endpointFiles(Project $project, Endpoint $endpoint, array $routeFiles): array
    {
        return [self::controllerPath(new Names($project, $endpoint->version), $endpoint->controller), ...$routeFiles];
    }

    /**
     * A method on an existing controller and a route in the version's route file (§3.3.4). The route goes in the file
     * that already routes the controller, or routes/<version>/<controller>.php, which is created when absent.
     */
    public function endpoint(Project $project, Endpoint $endpoint, ?ResponseStyle $style = null): ChangeSet
    {
        $names = new Names($project, $endpoint->version);
        $controllerPath = self::controllerPath($names, $endpoint->controller);
        $controllerClass = $names->namespace('Controllers') . '\\' . $endpoint->controller;
        $controllersNamespace = $names->namespace('Controllers', versioned: false);

        $controller = $project->file($controllerPath);
        if ($controller === null) {
            throw new InvalidArgumentException("{$controllerPath} does not exist. Create it with: kipchak make:controller {$endpoint->controller}"
                . ($endpoint->version === 'v1' ? '' : " --api-version={$endpoint->version}"));
        }
        $changes = [FileChange::modify($controllerPath, $controller, new ControllerMethods()->add(new SourceFile($controller), $controllerClass, $endpoint, $style ?? ResponseStyle::longForm())->content)];
        $messages = [];

        $routeGroups = new RouteGroups();
        $routePath = $this->routeFile($project, $endpoint, $controllerClass, $routeGroups);
        $routes = $project->file($routePath);
        if ($routes === null) {
            $changes[] = FileChange::create($routePath, Templates::render('routes-group.php', [
                'controllersNamespace' => $controllersNamespace,
                'version' => $endpoint->version,
                'routes' => '    ' . $routeGroups->route('group', $endpoint, $this->relativeName($controllerClass, $controllersNamespace)) . "\n",
            ]));
        } else {
            [$edited, $reason] = $routeGroups->add(new SourceFile($routes), $endpoint, $controllerClass, $controllersNamespace);
            if ($edited === null) {
                $messages[] = "{$routePath} was not changed, because {$reason}. Add the route by hand: "
                    . $routeGroups->route('group', $endpoint, '\\' . $controllerClass);
            } else {
                $changes[] = FileChange::modify($routePath, $routes, $edited->content);
            }
        }

        $messages[] = "{$controllerClass}::{$endpoint->action}() answers 501 Not Implemented until you write it.";

        return new ChangeSet($changes, [...$messages, ...$this->messages($project, null)]);
    }

    /**
     * api/Middlewares/<Name>.php and, with $global, its registration in middlewares/middlewares.php.
     */
    public function middleware(Project $project, string $name, bool $global): ChangeSet
    {
        Names::className($name, 'middleware');
        $names = new Names($project);
        $namespace = $names->namespace('Middlewares', versioned: false);
        $class = "{$namespace}\\{$name}";

        $changes = [FileChange::create($names->directory('Middlewares', versioned: false) . $name . '.php', Templates::render('middleware.php', [
            'namespace' => $namespace,
            'name' => $name,
            'registration' => $global
                ? 'middlewares/middlewares.php adds it for every request.'
                : "Add it to a route or a group with ->add(new {$name}()), or to every request in\n * middlewares/middlewares.php, between the two Error lines.",
        ]))];
        if (!$global) {
            return new ChangeSet($changes);
        }

        $registration = $project->file('middlewares/middlewares.php');
        if ($registration === null) {
            return new ChangeSet($changes, ["middlewares/middlewares.php was not found. Add this line to it: \$app->add(new \\{$class}());"]);
        }
        [$edited, $messages] = new GlobalMiddleware()->add(new SourceFile($registration), $class);
        if ($edited !== null) {
            $changes[] = FileChange::modify('middlewares/middlewares.php', $registration, $edited->content);
        }

        return new ChangeSet($changes, $messages);
    }

    /**
     * The responses of a resource's controller, in the project's style.
     *
     * @return array<string, string>
     */
    private static function responseValues(ResponseStyle $style, Resource $resource): array
    {
        $item = "ref: '#/components/schemas/{$resource->dto}'";

        return [
            'envelopeNote' => $style->controllerNote(),
            'listContent' => $style->content('OA', 200, "type: 'array', items: new OA\\Items({$item})"),
            'itemContent' => $style->content('OA', 200, $item),
            'createdContent' => $style->content('OA', 201, $item),
            'notFound' => $style->notFound('OA', "No {$resource->singularWords} has that ID."),
        ];
    }

    private static function controllerPath(Names $names, string $controller): string
    {
        return $names->directory('Controllers') . $controller . '.php';
    }

    /**
     * The route file for an endpoint: the one of its version that already routes the controller, preferring the one
     * named after it; otherwise routes/<version>/<controller>.php.
     */
    private function routeFile(Project $project, Endpoint $endpoint, string $controllerClass, RouteGroups $routeGroups): string
    {
        $named = "routes/{$endpoint->version}/" . str_replace(' ', '-', Field::words($endpoint->controller)) . '.php';
        $routing = [];
        foreach ($project->files as $path => $content) {
            if (str_starts_with($path, "routes/{$endpoint->version}/") && $routeGroups->refersTo(new SourceFile($content), $controllerClass)) {
                $routing[] = $path;
            }
        }
        sort($routing);

        return in_array($named, $routing, true) || $routing === [] ? $named : $routing[0];
    }

    private function relativeName(string $class, string $namespace): string
    {
        $alias = substr($namespace, (int) strrpos($namespace, '\\') + 1);

        return $alias . substr($class, strlen($namespace));
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
    private function messages(Project $project, ?Resource $resource): array
    {
        $messages = [];
        $openApiTest = $project->file('tests/OpenApiTest.php');
        if ($openApiTest !== null && !str_contains($openApiTest, 'getRouteCollector')) {
            $messages[] = 'tests/OpenApiTest.php lists the expected paths: add the new paths there, or replace it with the '
                . 'route-based test from starter 4.2.';
        }
        $apiTestCase = $project->file('tests/ApiTestCase.php');
        if ($resource !== null && $resource->crud && $apiTestCase !== null && !str_contains($apiTestCase, 'function app(')) {
            $messages[] = "tests/{$resource->controller}Test.php uses ApiTestCase::app(), which starter 4.1 added: copy it "
                . 'from https://github.com/kipchak-dev/starter/blob/master/tests/ApiTestCase.php.';
        }

        return $messages;
    }
}
