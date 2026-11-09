<?php

namespace App\Events;

class PaymentReceived extends DomainEvent
{
    public static function eventName(): string
    {
        return 'payment.received';
    }

    public static function description(): string
    {
        return 'A payment was recorded';
    }
}
