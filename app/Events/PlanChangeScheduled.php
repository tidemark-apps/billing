<?php

namespace App\Events;

class PlanChangeScheduled extends DomainEvent
{
    public static function eventName(): string
    {
        return 'service.plan_change_scheduled';
    }

    public static function description(): string
    {
        return 'A plan change was scheduled for the next renewal';
    }
}
