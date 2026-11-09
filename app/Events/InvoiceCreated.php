<?php

namespace App\Events;

class InvoiceCreated extends DomainEvent
{
    public static function eventName(): string
    {
        return 'invoice.created';
    }

    public static function description(): string
    {
        return 'An invoice was issued';
    }
}
