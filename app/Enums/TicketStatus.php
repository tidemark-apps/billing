<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';                    // waiting for staff
    case Answered = 'answered';            // staff replied, waiting for the customer
    case CustomerReply = 'customer_reply'; // customer replied, waiting for staff
    case OnHold = 'on_hold';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::CustomerReply => 'Customer reply',
            self::OnHold => 'On hold',
            default => ucfirst($this->value),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open, self::CustomerReply => 'warn',
            self::Answered => 'good',
            self::OnHold => 'brand',
            self::Closed => 'outline',
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }

    /** Needs a staff response. */
    public function awaitsStaff(): bool
    {
        return in_array($this, [self::Open, self::CustomerReply], true);
    }
}
