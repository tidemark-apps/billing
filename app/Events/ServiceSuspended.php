<?php

namespace App\Events;

class ServiceSuspended extends DomainEvent
{
    public static function eventName(): string
    {
        return 'service.suspended';
    }

    public static function description(): string
    {
        return 'A service was suspended';
    }
}
