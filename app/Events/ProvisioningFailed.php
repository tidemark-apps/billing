<?php

namespace App\Events;

class ProvisioningFailed extends DomainEvent
{
    public static function eventName(): string
    {
        return 'service.provisioning_failed';
    }

    public static function description(): string
    {
        return 'Provisioning a service failed';
    }
}
