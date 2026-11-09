<?php

namespace App\Http\Controllers\Api\Support\OpenApi;

/**
 * A tiny shorthand for JSON Schema, so the spec metadata stays readable.
 *
 *   'string' 'integer' 'number' 'boolean' 'object' 'array'     primitives
 *   'date' 'datetime' 'email' 'uri' 'uuid'                       formatted strings
 *   '?string'                                                    nullable
 *   'string[]'  'Client[]'                                       arrays
 *   'a|b|c'                                                      string enum
 *   'Client'                                                     $ref to components.schemas.Client
 *   ['string', 'description']                                    with a description
 *   [...raw JSON schema with "type" or "$ref"...]                used as is
 *
 * In Dsl::obj() a property name ending in "!" is required: ['name!' => 'string'].
 */
class Dsl
{
    private const PRIMITIVES = ['string', 'integer', 'number', 'boolean', 'object', 'array'];

    private const FORMATS = ['date' => 'date', 'datetime' => 'date-time', 'email' => 'email', 'uri' => 'uri', 'uuid' => 'uuid'];

    /** @param  string|array<string, mixed>  $spec @return array<string, mixed> */
    public static function type(string|array $spec): array
    {
        if (is_array($spec)) {
            if (isset($spec['type']) || isset($spec['$ref']) || isset($spec['allOf']) || isset($spec['oneOf'])) {
                return $spec;
            }
            [$type, $description] = [$spec[0], $spec[1] ?? null];

            return self::type($type) + ($description ? ['description' => $description] : []);
        }
        if (str_starts_with($spec, '?')) {
            $inner = self::type(substr($spec, 1));

            return isset($inner['$ref']) ? ['oneOf' => [$inner, ['type' => 'null']]] : ['type' => [$inner['type'], 'null']] + array_diff_key($inner, ['type' => 1]);
        }
        if (str_ends_with($spec, '[]')) {
            return ['type' => 'array', 'items' => self::type(substr($spec, 0, -2))];
        }
        if (str_contains($spec, '|')) {
            return ['type' => 'string', 'enum' => explode('|', $spec)];
        }
        if (isset(self::FORMATS[$spec])) {
            return ['type' => 'string', 'format' => self::FORMATS[$spec]];
        }
        if (in_array($spec, self::PRIMITIVES, true)) {
            return ['type' => $spec];
        }

        return ['$ref' => '#/components/schemas/'.$spec];
    }

    /** @param  array<string, string|array<string, mixed>>  $props @return array<string, mixed> */
    public static function obj(array $props, ?string $description = null): array
    {
        $out = ['type' => 'object', 'properties' => []];
        $required = [];
        foreach ($props as $name => $spec) {
            if (str_ends_with($name, '!')) {
                $name = substr($name, 0, -1);
                $required[] = $name;
            }
            $out['properties'][$name] = self::type($spec);
        }
        if ($required) {
            $out['required'] = $required;
        }

        return $description ? ['description' => $description] + $out : $out;
    }

    /** @param  array<string, mixed>  $schema @return array<string, mixed> */
    public static function ref(string $name): array
    {
        return ['$ref' => '#/components/schemas/'.$name];
    }
}
