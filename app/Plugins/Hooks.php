<?php

namespace App\Plugins;

/**
 * WordPress-style filters for the few places where a plugin needs to change a value rather than react to an event.
 *
 *   Hooks::filter('invoice.pdf.footer', fn (string $html, Invoice $invoice) => $html.'…');
 *   $html = Hooks::apply('invoice.pdf.footer', $html, $invoice);
 *
 * For "something happened" notifications use the DomainEvent classes in App\Events instead.
 */
class Hooks
{
    /** @var array<string, array<int, array<int, callable>>> */
    private static array $filters = [];

    public static function filter(string $name, callable $callback, int $priority = 10): void
    {
        self::$filters[$name][$priority][] = $callback;
    }

    public static function apply(string $name, mixed $value, mixed ...$args): mixed
    {
        if (empty(self::$filters[$name])) {
            return $value;
        }
        $byPriority = self::$filters[$name];
        ksort($byPriority);
        foreach ($byPriority as $callbacks) {
            foreach ($callbacks as $callback) {
                $value = $callback($value, ...$args);
            }
        }

        return $value;
    }

    public static function has(string $name): bool
    {
        return ! empty(self::$filters[$name]);
    }

    public static function flush(): void
    {
        self::$filters = [];
    }
}
