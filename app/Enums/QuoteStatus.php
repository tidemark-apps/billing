<?php

namespace App\Enums;

enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Converted = 'converted';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Accepted, self::Converted => 'good',
            self::Sent => 'brand',
            self::Declined => 'bad',
            self::Expired => 'warn',
            self::Draft => '',
        };
    }
}
