<?php

namespace App\Events;

class QuoteDeclined extends DomainEvent
{
    public static function eventName(): string
    {
        return 'quote.declined';
    }

    public static function description(): string
    {
        return 'A client declined a quote';
    }
}
