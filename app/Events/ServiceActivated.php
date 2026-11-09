<?php

namespace App\Events;

class ServiceActivated extends DomainEvent
{
    public static function eventName(): string
    {
        return 'service.activated';
    }

    public static function description(): string
    {
        return 'A service was provisioned and activated';
    }
}
