<?php

namespace App\Support;

/**
 * Tiny schema helper for settings forms. A field is an array:
 *   key, label, type (text|password|textarea|select|checkbox|number|url|email), options (for select), default,
 *   help, placeholder, required.
 * Plugins, gateways, modules and servers all describe their inputs this way, and the admin UI renders them.
 */
class Fields
{
    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, array<int, string>> Laravel validation rules keyed "{prefix}{key}"
     */
    public static function rules(array $fields, string $prefix = ''): array
    {
        $rules = [];
        foreach ($fields as $f) {
            $r = [];
            $type = $f['type'] ?? 'text';
            // password fields may stay blank to keep the stored value
            $r[] = ! empty($f['required']) && $type !== 'password' ? 'required' : 'nullable';
            $rule = match ($type) {
                'checkbox' => 'boolean',
                'number' => 'numeric',
                'url' => 'url',
                'email' => 'email',
                'select' => 'in:'.implode(',', array_keys($f['options'] ?? [])),
                default => ['string', 'max:5000'],
            };
            $rules[$prefix.$f['key']] = array_merge($r, (array) $rule);
        }

        return $rules;
    }

    /**
     * Pull the submitted values for a field list out of validated input, leaving blank passwords out.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function collect(array $fields, array $input): array
    {
        $out = [];
        foreach ($fields as $f) {
            $key = $f['key'];
            $type = $f['type'] ?? 'text';
            if ($type === 'checkbox') {
                $out[$key] = ! empty($input[$key]);

                continue;
            }
            if (! array_key_exists($key, $input)) {
                continue;
            }
            if ($type === 'password' && ($input[$key] === null || $input[$key] === '')) {
                continue;
            }
            $out[$key] = $input[$key];
        }

        return $out;
    }

    /** @param  array<int, array<string, mixed>>  $fields @return array<int, string> the keys of password fields */
    public static function secretKeys(array $fields): array
    {
        return array_values(array_map(fn ($f) => $f['key'], array_filter($fields, fn ($f) => ($f['type'] ?? '') === 'password')));
    }
}
