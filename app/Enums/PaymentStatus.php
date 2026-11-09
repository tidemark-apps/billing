<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Completed = 'completed';
    case Pending = 'pending';
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Completed => 'good',
            self::Pending => 'warn',
            self::Failed => 'bad',
        };
    }
}
