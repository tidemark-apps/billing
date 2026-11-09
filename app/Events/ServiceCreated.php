<?php

namespace App\Events;

class ServiceCreated extends DomainEvent
{
    public static function eventName(): string
    {
        return 'service.created';
    }

    public static function description(): string
    {
        return 'A service was ordered';
    }
}
