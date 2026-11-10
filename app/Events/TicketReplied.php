<?php

namespace App\Events;

class TicketReplied extends DomainEvent
{
    public static function eventName(): string
    {
        return 'ticket.replied';
    }

    public static function description(): string
    {
        return 'A customer or staff member replied to a ticket';
    }
}
