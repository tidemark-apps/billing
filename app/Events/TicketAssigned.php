<?php

namespace App\Events;

class TicketAssigned extends DomainEvent
{
    public static function eventName(): string
    {
        return 'ticket.assigned';
    }

    public static function description(): string
    {
        return 'A ticket was assigned to a staff member or unassigned';
    }
}
