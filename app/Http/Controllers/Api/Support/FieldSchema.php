<?php

namespace App\Http\Controllers\Api\Support;

use App\Support\Fields;
use Illuminate\Validation\ValidationException;

/**
 * Presents and collects "field lists" (App\Support\Fields) for the API: server, plugin, gateway and settings forms.
 * Secrets (password-type fields) are write-only: they are never returned, only whether one is stored.
 */
class FieldSchema
{
    /**
     * Describe the fields and attach the current values. Password fields get no value, only has_value.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  callable(string): mixed  $current  key => stored value
     * @return array<int, array<string, mixed>>
     */
    public static function describe(array $fields, callable $current): array
    {
        return array_map(function (array $f) use ($current) {
            $secret = ($f['type'] ?? 'text') === 'password';
            $value = $current($f['key']);
            $out = [
                'key' => $f['key'],
                'label' => $f['label'] ?? $f['key'],
                'type' => $f['type'] ?? 'text',
                'required' => ! empty($f['required']) && ! $secret,
                'options' => isset($f['options']) ? $f['options'] : null,
                'default' => $f['default'] ?? null,
                'help' => $f['help'] ?? null,
                'secret' => $secret,
            ];
            if ($secret) {
                $out['has_value'] = $value !== null && $value !== '';
            } else {
                $out['value'] = $value;
            }

            return $out;
        }, array_values($fields));
    }

    /** Field definitions without values (for catalogues such as GET /admin/modules). @return array<int, array<string, mixed>> */
    public static function schema(array $fields): array
    {
        return array_map(function (array $f) {
            $d = self::describe([$f], fn () => null)[0];
            unset($d['value'], $d['has_value']);

            return $d;
        }, array_values($fields));
    }

    /**
     * Like Fields::collect, but only for keys that were actually sent. Fields::collect treats an absent checkbox
     * as "off", which suits a web form but would silently switch settings off in a partial API update.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function collectPresent(array $fields, array $input): array
    {
        $present = array_values(array_filter($fields, fn ($f) => array_key_exists($f['key'], $input)));

        return Fields::collect($present, $input);
    }

    /** Keys in $input that no field defines; the API rejects them rather than ignoring typos. @return array<int, string> */
    public static function unknownKeys(array $fields, array $input): array
    {
        return array_values(array_diff(array_keys($input), array_map(fn ($f) => $f['key'], $fields)));
    }

    /** @param  array<int, array<string, mixed>>  $fields */
    public static function rejectUnknown(array $fields, mixed $input, string $attribute): void
    {
        if (! is_array($input)) {
            throw ValidationException::withMessages([$attribute => 'Must be an object of field values.']);
        }
        $unknown = self::unknownKeys($fields, $input);
        if ($unknown) {
            throw ValidationException::withMessages(array_combine(array_map(fn ($k) => "$attribute.$k", $unknown), array_fill(0, count($unknown), ['Unknown field.'])));
        }
    }
}
