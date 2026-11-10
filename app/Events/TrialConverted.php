<?php

namespace App\Events;

class TrialConverted extends DomainEvent
{
    public static function eventName(): string
    {
        return 'service.trial_converted';
    }

    public static function description(): string
    {
        return 'A free trial ended and the first invoice was raised';
    }
}
