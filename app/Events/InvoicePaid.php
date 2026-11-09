<?php

namespace App\Events;

class InvoicePaid extends DomainEvent
{
    public static function eventName(): string
    {
        return 'invoice.paid';
    }

    public static function description(): string
    {
        return 'An invoice was paid in full';
    }
}
