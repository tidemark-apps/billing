<?php

namespace App\Http\Controllers\Api\Support;

use App\Http\Controllers\Admin\SettingsController as WebSettingsController;

/**
 * The settings the admin screen edits (Settings → General / Billing / Email / Security; "autopay" is the Automatic payments group of Billing), as field lists with the real, dotted
 * setting keys ("company.name").
 *
 * The field lists are owned by Admin\SettingsController (private groups()); this class reads them from there so the API
 * can never drift from the screen: a setting added to the UI is immediately readable and writable through the API, and
 * validated by the same rules. The web form names fields with "__" because PHP rewrites dots in form names; the API uses
 * the real keys. If that controller's method is renamed, AdminSettingsTest fails.
 * (A later cleanup could move the lists into a shared App\Support class and delete the reflection.)
 */
class SettingsSchema
{
    /** Sections that have a field list. "payments" (gateways) and "system" (read-only info) are handled separately. */
    public const EDITABLE = ['general', 'billing', 'email', 'autopay', 'security'];

    public const SECTIONS = ['general', 'billing', 'email', 'autopay', 'security', 'payments', 'system'];

    /** @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}> */
    public static function groups(string $section): array
    {
        if ($section === 'autopay') {
            // "Automatic payments" is a group of the billing screen; the API also offers it as a section of its own.
            return array_values(array_filter(self::groups('billing'), fn (array $g) => $g['fields'] !== [] && collect($g['fields'])->every(fn ($f) => str_starts_with($f['key'], 'autopay.'))));
        }
        $controller = app(WebSettingsController::class);
        $groups = (new \ReflectionMethod($controller, 'groups'))->invoke($controller, $section);

        return array_map(function (array $group) {
            $group['fields'] = array_map(fn (array $f) => ['key' => str_replace('__', '.', $f['key'])] + $f, $group['fields']);

            return $group;
        }, $groups);
    }

    /** @return array<int, array<string, mixed>> */
    public static function fields(string $section): array
    {
        return collect(self::groups($section))->pluck('fields')->flatten(1)->all();
    }
}
