<?php

namespace App\Events;

class TrialStarted extends DomainEvent
{
    public static function eventName(): string
    {
        return 'service.trial_started';
    }

    public static function description(): string
    {
        return 'A free trial began';
    }
}
