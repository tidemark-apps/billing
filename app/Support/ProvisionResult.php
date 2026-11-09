<?php

namespace App\Support;

final class ProvisionResult
{
    /**
     * @param  array<string, mixed>  $data  stored on the service (visible to staff and client)
     * @param  array<string, mixed>  $secrets  stored encrypted on the service (e.g. a generated password)
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message = '',
        public readonly array $data = [],
        public readonly array $secrets = [],
        public readonly ?string $redirect = null,
    ) {}

    /** @param  array<string, mixed>  $data @param  array<string, mixed>  $secrets */
    public static function ok(string $message = '', array $data = [], array $secrets = []): self
    {
        return new self(true, $message, $data, $secrets);
    }

    public static function fail(string $message): self
    {
        return new self(false, $message);
    }

    /** Send the browser somewhere (e.g. a one-time panel login URL). */
    public static function redirect(string $url, string $message = ''): self
    {
        return new self(true, $message, [], [], $url);
    }
}
