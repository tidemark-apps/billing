<?php

namespace App\Events;

class QuoteSent extends DomainEvent
{
    public static function eventName(): string
    {
        return 'quote.sent';
    }

    public static function description(): string
    {
        return 'A quote was sent to a client';
    }
}
