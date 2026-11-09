<?php

namespace App\Events;

class ServiceCancelled extends DomainEvent
{
    public static function eventName(): string
    {
        return 'service.cancelled';
    }

    public static function description(): string
    {
        return 'A service was cancelled';
    }
}
