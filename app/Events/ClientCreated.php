<?php

namespace App\Events;

class ClientCreated extends DomainEvent
{
    public static function eventName(): string
    {
        return 'client.created';
    }

    public static function description(): string
    {
        return 'A client account was created';
    }
}
