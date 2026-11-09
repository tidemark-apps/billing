<?php

namespace App\Events;

class QuoteAccepted extends DomainEvent
{
    public static function eventName(): string
    {
        return 'quote.accepted';
    }

    public static function description(): string
    {
        return 'A client accepted a quote';
    }
}
