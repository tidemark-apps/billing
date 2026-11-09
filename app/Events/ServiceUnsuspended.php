<?php

namespace App\Events;

class ServiceUnsuspended extends DomainEvent
{
    public static function eventName(): string
    {
        return 'service.unsuspended';
    }

    public static function description(): string
    {
        return 'A service was unsuspended';
    }
}
