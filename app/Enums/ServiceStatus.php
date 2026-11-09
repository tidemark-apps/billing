<?php

namespace App\Enums;

enum ServiceStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Terminated = 'terminated';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'good',
            self::Pending => 'warn',
            self::Suspended => 'bad',
            self::Cancelled, self::Terminated => 'outline',
        };
    }

    public function isLive(): bool
    {
        return in_array($this, [self::Active, self::Suspended], true);
    }
}
