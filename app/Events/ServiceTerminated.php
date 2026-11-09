<?php

namespace App\Events;

class ServiceTerminated extends DomainEvent
{
    public static function eventName(): string
    {
        return 'service.terminated';
    }

    public static function description(): string
    {
        return 'A service was terminated';
    }
}
