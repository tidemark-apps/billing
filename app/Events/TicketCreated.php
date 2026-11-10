<?php

namespace App\Events;

class TicketCreated extends DomainEvent
{
    public static function eventName(): string
    {
        return 'ticket.created';
    }

    public static function description(): string
    {
        return 'A support ticket was opened';
    }
}
