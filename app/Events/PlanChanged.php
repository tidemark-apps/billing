<?php

namespace App\Events;

class PlanChanged extends DomainEvent
{
    public static function eventName(): string
    {
        return 'service.plan_changed';
    }

    public static function description(): string
    {
        return 'A service moved to a different plan or billing cycle';
    }
}
