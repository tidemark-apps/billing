<?php

namespace App\Support;

/** The outcome of charging a saved payment method. */
final class ChargeResult
{
    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    public const REQUIRES_ACTION = 'requires_action';

    /** Accepted but not settled yet (e.g. bank debits). A webhook records the payment when it clears. */
    public const PENDING = 'pending';

    /** @param  array<string, mixed>  $meta */
    private function __construct(
        public readonly string $status,
        public readonly ?string $reference = null,
        public readonly string $message = '',
        public readonly int $fee = 0,
        public readonly ?int $amount = null,
        public readonly array $meta = [],
    ) {}

    /** @param  array<string, mixed>  $meta */
    public static function success(string $reference, ?int $amount = null, int $fee = 0, array $meta = []): self
    {
        return new self(self::SUCCESS, $reference, '', $fee, $amount, $meta);
    }

    public static function failed(string $reason): self
    {
        return new self(self::FAILED, null, $reason);
    }

    public static function requiresAction(string $message = 'The bank requires the customer to authenticate this payment.', ?string $reference = null): self
    {
        return new self(self::REQUIRES_ACTION, $reference, $message);
    }

    public static function pending(string $reference, string $message = ''): self
    {
        return new self(self::PENDING, $reference, $message);
    }

    public function succeeded(): bool
    {
        return $this->status === self::SUCCESS;
    }
}
