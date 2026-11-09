<?php

namespace App\Events;

class PaymentRefunded extends DomainEvent
{
    public static function eventName(): string
    {
        return 'payment.refunded';
    }

    public static function description(): string
    {
        return 'A payment was refunded';
    }
}
