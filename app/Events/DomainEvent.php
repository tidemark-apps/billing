<?php

namespace App\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Base class for every business event. Plugins listen to these with Event::listen(), and the same
 * events feed the outbound webhooks, so anything a plugin can react to an API consumer can too.
 */
abstract class DomainEvent
{
    /** @param  array<string, mixed>  $context */
    public function __construct(public readonly Model $model, public readonly array $context = []) {}

    /** The public name used in webhooks, e.g. "invoice.paid". */
    abstract public static function eventName(): string;

    public function name(): string
    {
        return static::eventName();
    }

    /** @return array<string, string> every event name => human description (for the webhook settings UI) */
    public static function catalog(): array
    {
        $out = [];
        foreach (glob(__DIR__.'/*.php') as $file) {
            $class = __NAMESPACE__.'\\'.basename($file, '.php');
            if ($class !== self::class && is_subclass_of($class, self::class)) {
                $out[$class::eventName()] = $class::description();
            }
        }
        ksort($out);

        return $out;
    }

    public static function description(): string
    {
        return ucfirst(str_replace('.', ' ', static::eventName()));
    }
}
