<?php

namespace App\Support;

final class CheckoutResult
{
    private function __construct(public readonly ?string $redirectUrl, public readonly ?string $error = null) {}

    public static function redirect(string $url): self
    {
        return new self($url);
    }

    public static function error(string $message): self
    {
        return new self(null, $message);
    }

    public function failed(): bool
    {
        return $this->redirectUrl === null;
    }
}
