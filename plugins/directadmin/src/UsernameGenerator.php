<?php

namespace Plugins\DirectAdmin;

/**
 * Turns a domain into a DirectAdmin username: lowercase letters or digits, starting with a letter.
 * DirectAdmin's rule is "start with a-z, contain >= 3 characters and <= 10" (default max_username_length is 10; admins can
 * set 8–14 in directadmin.conf, see changelog 1.20.0). We never go below 4 characters and default to the 10-character limit.
 */
class UsernameGenerator
{
    public const MIN_LENGTH = 4;

    public const MAX_LENGTH = 10;

    private const RESERVED = [
        'root', 'admin', 'mail', 'ftp', 'www', 'nobody', 'named', 'apache', 'daemon', 'bin', 'sys', 'shell', 'test', 'user', 'users', 'nginx',
        'mysql', 'postgres', 'dovecot', 'majordomo', 'diradmin', 'webapps', 'default',
    ];

    /** @param  int  $attempt  0 for the first choice; 1, 2, … append a digit when the name is taken */
    public static function make(string $domain, int $attempt = 0, int $maxLength = self::MAX_LENGTH): string
    {
        $label = explode('.', ltrim(strtolower($domain), '.'))[0] ?? '';
        $base = preg_replace('/[^a-z0-9]/', '', $label) ?? '';
        if ($base === '') {
            $base = 'user';
        }
        if (ctype_digit($base[0])) {
            $base = 'u'.$base;
        }
        if (in_array($base, self::RESERVED, true)) {
            $base .= 'x';
        }
        $suffix = $attempt > 0 ? (string) $attempt : '';
        $name = substr($base, 0, max(self::MIN_LENGTH, $maxLength) - strlen($suffix)).$suffix;

        return str_pad($name, self::MIN_LENGTH, '0');
    }
}
