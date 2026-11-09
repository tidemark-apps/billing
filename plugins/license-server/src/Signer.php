<?php

namespace Plugins\LicenseServer;

use App\Support\Settings;

/** Ed25519 signatures so client software can verify a licence response offline with only the public key. */
class Signer
{
    private const SECRET = 'plugin.license-server.signing_secret';

    private const PUBLIC = 'plugin.license-server.signing_public';

    public static function ensureKeys(): void
    {
        if (Settings::get(self::SECRET) && Settings::get(self::PUBLIC)) {
            return;
        }
        $pair = sodium_crypto_sign_keypair();
        Settings::set(self::SECRET, base64_encode(sodium_crypto_sign_secretkey($pair)), true);
        Settings::set(self::PUBLIC, base64_encode(sodium_crypto_sign_publickey($pair)));
    }

    public static function publicKey(): string
    {
        self::ensureKeys();

        return (string) Settings::get(self::PUBLIC);
    }

    /**
     * The exact string that is signed. Client software rebuilds it from values it already knows plus the response.
     */
    public static function payload(string $key, string $product, string $status, ?string $expiresAt, ?string $identifier, int $issuedAt, bool $valid): string
    {
        return implode("\n", [
            'license.v1',
            'valid='.($valid ? '1' : '0'),
            'key='.$key,
            'product='.$product,
            'status='.$status,
            'expires='.($expiresAt ?? ''),
            'identifier='.($identifier ?? ''),
            'issued='.$issuedAt,
        ]);
    }

    public static function sign(string $payload): string
    {
        self::ensureKeys();
        $secret = base64_decode((string) Settings::get(self::SECRET), true);

        return base64_encode(sodium_crypto_sign_detached($payload, $secret));
    }
}
