<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Make;

use LogicException;

/**
 * Renders the templates in templates/: plain files written from the starter project's own (the Sultans resource),
 * with {{placeholders}}. CI generates the Sultans resource and compares it with the starter (SR-2026-012, objective 2).
 */
final class Templates
{
    /**
     * @param array<string, string> $values
     */
    public static function render(string $template, array $values): string
    {
        $file = dirname(__DIR__, 3) . '/templates/' . $template . '.tpl';
        $source = (string) file_get_contents($file);

        $replacements = [];
        foreach ($values as $key => $value) {
            $replacements['{{' . $key . '}}'] = $value;
        }
        $rendered = strtr($source, $replacements);

        if (preg_match('/\{\{(\w+)\}\}/', $rendered, $missing) === 1) {
            throw new LogicException("The template {$template} needs a value for {$missing[1]}.");
        }

        return $rendered;
    }

    /**
     * @param list<Field> $fields
     */
    public static function dto(string $namespace, Resource $resource, array $fields): string
    {
        $uses = ['use OpenApi\\Attributes as OA;'];

        // Required fields first: PHP allows no required parameter after an optional (nullable, defaulted) one.
        usort($fields, static fn (Field $a, Field $b): int => (int) $a->nullable <=> (int) $b->nullable);

        $docParams = [];
        $properties = [];
        foreach ($fields as $field) {
            if ($field->list) {
                $docParams[] = "     * @param list<{$field->type}> \${$field->name}";
            }
            $description = $field->name === 'id'
                ? "Identifier of the {$resource->singularWords}."
                : 'The ' . Field::words($field->name) . " of the {$resource->singularWords}.";
            $readOnly = $field->name === 'id' && $resource->crud ? ', readOnly: true' : '';
            $default = $field->nullable ? ' = null' : '';
            $properties[] = '        #[OA\\Property(description: ' . var_export($description, true) . ", {$field->openApiArguments()}{$readOnly})]\n"
                . "        public {$field->phpType()} \${$field->name}{$default},";
        }

        return self::render('dto.php', [
            'namespace' => $namespace,
            'uses' => implode("\n", array_unique($uses)),
            'dto' => $resource->dto,
            'schemaDescription' => "A {$resource->singularWords}.",
            'constructorDoc' => $docParams === [] ? '' : "    /**\n" . implode("\n", $docParams) . "\n     */\n",
            'properties' => implode("\n\n", $properties),
        ]);
    }

    /**
     * The values every resource template shares.
     *
     * @return array<string, string>
     */
    public static function resourceValues(Resource $resource, string $version): array
    {
        return [
            'controller' => $resource->controller,
            'dto' => $resource->dto,
            'singular' => $resource->singular,
            'singularWords' => $resource->singularWords,
            'pluralWords' => $resource->pluralWords,
            'variable' => $resource->variable,
            'constant' => $resource->constant,
            'segment' => $resource->segment,
            'version' => $version,
            'path' => "/{$version}/{$resource->segment}",
            'tagDescription' => ucfirst($resource->pluralWords) . '.',
        ];
    }
}
