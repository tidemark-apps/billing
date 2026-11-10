<?php

namespace App\Services\Support;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;

/**
 * The library only signs in with the IMAP LOGIN command. Mail clients (Thunderbird, Apple Mail, Outlook) prefer SASL
 * AUTHENTICATE PLAIN, and some servers treat the two differently, so this tries PLAIN first when the server offers it
 * (with the initial response, RFC 4959) and falls back to LOGIN. If both fail, the error says what each one got back.
 */
class SaslClient extends Client
{
    protected function authenticate(): void
    {
        if ($this->authentication === 'oauth') {
            parent::authenticate();

            return;
        }
        $failures = [];
        $caps = $this->capabilities();

        if (in_array('AUTH=PLAIN', $caps, true) && in_array('SASL-IR', $caps, true)) {
            try {
                $this->connection->requestAndResponse('AUTHENTICATE', ['PLAIN', base64_encode("\0".$this->username."\0".$this->password)], true)->validatedData();

                return;
            } catch (\Throwable $e) {
                $failures[] = 'AUTHENTICATE PLAIN → '.$this->reason($e);
            }
        }
        if (in_array('LOGINDISABLED', $caps, true)) {
            $failures[] = 'LOGIN is disabled by the server';
        } else {
            try {
                parent::authenticate();

                return;
            } catch (\Throwable $e) {
                $failures[] = 'LOGIN → '.$this->reason($e);
            }
        }

        throw new AuthFailedException(implode('; ', $failures)
            .' [offered: '.(implode(' ', array_filter($caps, fn ($c) => str_starts_with($c, 'AUTH=') || in_array($c, ['LOGINDISABLED', 'SASL-IR', 'STARTTLS'], true))) ?: 'unknown').']');
    }

    /** @return array<int, string> the server's capabilities, upper-cased */
    private function capabilities(): array
    {
        try {
            return array_map(fn ($c) => strtoupper((string) $c), array_values((array) $this->connection->getCapabilities()->validatedData()));
        } catch (\Throwable) {
            return [];
        }
    }

    private function reason(\Throwable $e): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '');
        if ($text === '' || $text === 'failed to authenticate') {
            $text = trim(preg_replace('/\s+/', ' ', (string) $e->getPrevious()?->getMessage()) ?? '') ?: 'rejected';
        }

        return $text;
    }
}
