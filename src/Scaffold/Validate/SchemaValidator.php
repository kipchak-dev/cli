<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Validate;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * OAS-001: the document is valid against the OpenAPI Initiative's published JSON Schema for its version, bundled in
 * resources/openapi/ as published (https://spec.openapis.org/oas/, Apache-2.0).
 *
 * The schemas are adapted for opis/json-schema when loaded, without changing what they accept:
 * - 3.0's schema is written in JSON Schema draft-04, which opis does not read. Its three draft-04 forms are rewritten
 *   in draft-07: `id` becomes `$id`, and multipleOf's `minimum: 0, exclusiveMinimum: true` becomes
 *   `exclusiveMinimum: 0`.
 * - 3.1's schema refers to a Schema Object with `$dynamicRef: "#meta"`, which opis resolves to the document's root.
 *   Its only `$dynamicAnchor: meta` is `$defs/schema`, so the references are made static `$ref`s to it.
 * - opis fills in defaults while validating; the 3.1 schema's defaults (style, explode) would then count as properties
 *   the document does not have, so defaults are turned off.
 */
final class SchemaValidator
{
    private const string DIRECTORY = __DIR__ . '/../../../resources/openapi/';
    private const int MAX_ERRORS = 50;

    /**
     * @return list<Finding>
     */
    public function validate(object $document): array
    {
        $version = is_string($document->openapi ?? null) ? $document->openapi : '';
        $file = match (true) {
            str_starts_with($version, '3.0.') => 'oas-3.0.json',
            str_starts_with($version, '3.1.') => 'oas-3.1.json',
            default => null,
        };
        if ($file === null) {
            return [new Finding('OAS-001', Severity::Error, '/openapi', "The document declares OpenAPI '{$version}'; openapi:validate checks 3.0 and 3.1.")];
        }

        $validator = new Validator();
        $validator->parser()->setOption('allowDefaults', false);
        $validator->setMaxErrors(self::MAX_ERRORS);
        $schema = $this->schema($file);
        $validator->resolver()->registerRaw($schema, $schema->{'$id'});

        $result = $validator->validate($document, $schema->{'$id'});
        if ($result->isValid()) {
            return [];
        }

        // opis reports every branch of a oneOf that failed, so the same message can appear several times, and a
        // failure at a node (a parameter that is neither a Parameter nor a Reference) above the specific failure
        // inside it. Only the most specific failures are kept.
        $errors = [];
        foreach (new ErrorFormatter()->format($result->error(), true) as $pointer => $messages) {
            $pointer = rawurldecode((string) $pointer);
            foreach ((array) $messages as $message) {
                $errors[$pointer . "\0" . $message] = [$pointer, (string) $message];
            }
        }
        $pointers = array_unique(array_column($errors, 0));

        $findings = [];
        foreach ($errors as [$pointer, $message]) {
            foreach ($pointers as $other) {
                if (str_starts_with($other, $pointer . '/')) {
                    continue 2;
                }
            }
            $value = $this->valueAt($document, $pointer);
            $found = is_scalar($value) ? ' (found ' . var_export($value, true) . ')' : '';
            $findings[] = new Finding('OAS-001', Severity::Error, $pointer === '' ? '/' : $pointer, $message . $found);
        }

        return $findings;
    }

    private function valueAt(object $document, string $pointer): mixed
    {
        $node = $document;
        foreach ($pointer === '' ? [] : explode('/', substr($pointer, 1)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            $node = match (true) {
                is_object($node) && property_exists($node, $segment) => $node->{$segment},
                is_array($node) && array_key_exists((int) $segment, $node) => $node[(int) $segment],
                default => null,
            };
        }

        return $node;
    }

    private function schema(string $file): object
    {
        $source = (string) file_get_contents(self::DIRECTORY . $file);
        if ($file === 'oas-3.1.json') {
            $source = str_replace('"$dynamicRef": "#meta"', '"$ref": "#/$defs/schema"', $source);
        }
        $schema = json_decode($source, flags: JSON_THROW_ON_ERROR);

        if ($file === 'oas-3.0.json') {
            $schema->{'$id'} = $schema->id;
            unset($schema->id);
            $schema->{'$schema'} = 'http://json-schema.org/draft-07/schema#';
            $multipleOf = $schema->definitions->Schema->properties->multipleOf;
            unset($multipleOf->minimum);
            $multipleOf->exclusiveMinimum = 0;
        }

        return $schema;
    }
}
