<?php

namespace App\Events;

class TrialEnding extends DomainEvent
{
    public static function eventName(): string
    {
        return 'service.trial_ending';
    }

    public static function description(): string
    {
        return 'A free trial is about to end and convert to a paid plan';
    }
}
