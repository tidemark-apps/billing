<?php

namespace App\Events;

class TicketStatusChanged extends DomainEvent
{
    public static function eventName(): string
    {
        return 'ticket.status_changed';
    }

    public static function description(): string
    {
        return 'A ticket changed status (including closed and reopened)';
    }
}
