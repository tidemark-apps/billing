<?php

namespace App\Support;

final class RefundResult
{
    public function __construct(public readonly bool $success, public readonly ?string $reference = null, public readonly string $message = '') {}

    public static function ok(?string $reference = null, string $message = ''): self
    {
        return new self(true, $reference, $message);
    }

    public static function fail(string $message): self
    {
        return new self(false, null, $message);
    }
}
