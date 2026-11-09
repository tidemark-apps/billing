<?php

namespace App\Events;

class InvoiceVoided extends DomainEvent
{
    public static function eventName(): string
    {
        return 'invoice.voided';
    }

    public static function description(): string
    {
        return 'An invoice was voided';
    }
}
