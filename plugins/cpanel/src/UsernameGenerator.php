<?php

namespace Plugins\CPanel;

/**
 * Turns a domain into a cPanel username: lowercase letters and digits, starting with a letter, at most 8 characters
 * (cPanel only guarantees uniqueness of the first eight; longer names collide on MySQL).
 */
class UsernameGenerator
{
    private const RESERVED = [
        'root', 'admin', 'administrator', 'mysql', 'mail', 'ftp', 'www', 'cpanel', 'nobody', 'postmaster', 'named', 'dovecot', 'apache', 'httpd',
        'daemon', 'bin', 'sys', 'shell', 'tmp', 'dns', 'nginx', 'postgres', 'cpses', 'cphulk', 'cpaneleximfilter', 'mailman', 'webmaster', 'hostmaster',
    ];

    public const MAX_LENGTH = 8;

    /** @param  int  $attempt  0 for the first choice; 1, 2, … append a digit when the name is taken */
    public static function make(string $domain, int $attempt = 0): string
    {
        $label = strtolower(explode('.', ltrim(strtolower($domain), '.'))[0] ?? '');
        $base = preg_replace('/[^a-z0-9]/', '', $label) ?? '';
        if ($base === '') {
            $base = 'user';
        }
        if (ctype_digit($base[0]) || str_starts_with($base, 'test') || in_array($base, self::RESERVED, true)) {
            $base = 'u'.$base;
        }
        $suffix = $attempt > 0 ? (string) $attempt : '';

        return substr($base, 0, self::MAX_LENGTH - strlen($suffix)).$suffix;
    }
}
