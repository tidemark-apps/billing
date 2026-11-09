<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';
    case Uncollectible = 'uncollectible';

    public function label(): string
    {
        return $this === self::Uncollectible ? 'Uncollectible' : ucfirst($this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Paid => 'good',
            self::Open => 'brand',
            self::Draft => '',
            self::Void => 'outline',
            self::Uncollectible => 'bad',
        };
    }
}
