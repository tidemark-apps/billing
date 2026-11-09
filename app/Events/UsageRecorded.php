<?php

namespace App\Events;

/** A usage record was stored. The model is the App\Models\UsageRecord; context carries the meter key and service uuid. */
class UsageRecorded extends DomainEvent
{
    public static function eventName(): string
    {
        return 'usage.recorded';
    }

    public static function description(): string
    {
        return 'Metered usage was recorded for a service';
    }
}
