<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * An OpenAPI 3 spec's operations as connector tools.
 *
 * Each path and method is one tool. Its parameters become one JSON-schema
 * object, the shape a model's tool call fills in: path and query parameters
 * as properties marked with where they go (x-in), and a JSON request body as
 * the property "body". The engine reads x-in to build the request and strips
 * it before the model sees the schema.
 */
final class OpenApiImport
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    public static function tools(array $spec): array
    {
        if (!str_starts_with((string) ($spec['openapi'] ?? ''), '3.') || !is_array($spec['paths'] ?? null)) {
            throw new InvalidArgumentException('This is not an OpenAPI 3 document: it needs "openapi": "3.x" and "paths".');
        }

        $tools = [];
        $taken = [];

        foreach ($spec['paths'] as $path => $operations) {
            foreach (self::METHODS as $method) {
                $operation = $operations[$method] ?? null;
                if (!is_array($operation)) {
                    continue;
                }

                $name = self::slug((string) ($operation['operationId'] ?? '')) ?: self::slug("{$method}_{$path}");
                $base = substr($name, 0, 60);
                for ($n = 2; isset($taken[$name]); $n++) {
                    $name = "{$base}_{$n}";
                }
                $taken[$name] = true;

                $description = (string) ($operation['summary'] ?? $operation['description'] ?? '');

                $tools[] = [
                    'name' => $name,
                    'description' => mb_substr($description, 0, 1000),
                    'method' => $method,
                    'path' => (string) $path,
                    'parameters' => self::parameters($spec, array_merge(
                        $operations['parameters'] ?? [], $operation['parameters'] ?? []),
                        $operation['requestBody'] ?? null),
                    'writes' => $method !== 'get',
                ];
            }
        }

        return $tools;
    }

    private static function parameters(array $spec, array $parameters, ?array $requestBody): array
    {
        $properties = [];
        $required = [];

        foreach ($parameters as $parameter) {
            $parameter = self::resolve($spec, $parameter);
            $in = $parameter['in'] ?? '';
            if (!in_array($in, ['path', 'query'], true) || empty($parameter['name'])) {
                continue;
            }

            $schema = self::resolve($spec, $parameter['schema'] ?? ['type' => 'string']);
            if (!empty($parameter['description'])) {
                $schema['description'] = $parameter['description'];
            }
            $properties[$parameter['name']] = $schema + ['x-in' => $in];

            if ($in === 'path' || !empty($parameter['required'])) {
                $required[] = $parameter['name'];
            }
        }

        $body = $requestBody['content']['application/json']['schema'] ?? null;
        if (is_array($body)) {
            $properties['body'] = self::resolve($spec, $body);
            if (!empty($requestBody['required'])) {
                $required[] = 'body';
            }
        }

        $schema = ['type' => 'object', 'properties' => (object) []];
        if ($properties) {
            $schema['properties'] = $properties;
        }
        if ($required) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * A local $ref replaced by what it points at, one level deep.
     *
     * ponytail: one level only; a deeper $ref becomes a bare object, which a
     * model still fills. Resolve recursively if real specs need it.
     */
    private static function resolve(array $spec, array $node): array
    {
        $ref = $node['$ref'] ?? null;
        if (!is_string($ref)) {
            return $node;
        }

        $target = $spec;
        foreach (explode('/', ltrim(substr($ref, 1), '/')) as $key) {
            $target = is_array($target) ? ($target[$key] ?? null) : null;
        }

        return is_array($target) && !isset($target['$ref']) ? $target : ['type' => 'object'];
    }

    private static function slug(string $text): string
    {
        $slug = trim(preg_replace('/[^A-Za-z0-9_-]+/', '_', $text), '_');

        return substr($slug, 0, 64);
    }
}
