<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum BillingCycle: string
{
    case OneTime = 'one_time';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnually = 'semi_annually';
    case Annually = 'annually';
    case Biennially = 'biennially';
    case Triennially = 'triennially';

    public function months(): int
    {
        return match ($this) {
            self::OneTime => 0,
            self::Monthly => 1,
            self::Quarterly => 3,
            self::SemiAnnually => 6,
            self::Annually => 12,
            self::Biennially => 24,
            self::Triennially => 36,
        };
    }

    public function isRecurring(): bool
    {
        return $this !== self::OneTime;
    }

    public function label(): string
    {
        return match ($this) {
            self::OneTime => 'One time',
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::SemiAnnually => 'Every 6 months',
            self::Annually => 'Annually',
            self::Biennially => 'Every 2 years',
            self::Triennially => 'Every 3 years',
        };
    }

    public function suffix(): string
    {
        return match ($this) {
            self::OneTime => '',
            self::Monthly => '/ mo',
            self::Quarterly => '/ qtr',
            self::SemiAnnually => '/ 6 mo',
            self::Annually => '/ yr',
            self::Biennially => '/ 2 yr',
            self::Triennially => '/ 3 yr',
        };
    }

    /** The date one billing period after $from (never overflows past month end). */
    public function advance(CarbonInterface $from): CarbonInterface
    {
        return $this->isRecurring() ? $from->copy()->addMonthsNoOverflow($this->months()) : $from->copy();
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
