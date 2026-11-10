<?php

// A tiny scripted IMAP server for tests: php fake-imap.php PORT MODE LOGFILE
// MODE: sasl (PLAIN with SASL-IR works), plain-bad (PLAIN is rejected, LOGIN works), login-only (no SASL), reject (everything is rejected)
[, $port, $mode, $log] = $argv;
$user = 'u@example.com';
$pass = 'p@ss"w\\ord é';
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $err);
touch($log.'.ready');
$conn = stream_socket_accept($server, 15);
$send = fn (string $s) => fwrite($conn, $s."\r\n");
$note = fn (string $s) => file_put_contents($log, $s."\n", FILE_APPEND);
$caps = $mode === 'login-only' ? 'IMAP4rev1' : 'IMAP4rev1 SASL-IR AUTH=PLAIN AUTH=LOGIN';
$send("* OK [CAPABILITY $caps] ready");
while (($line = fgets($conn)) !== false) {
    $line = rtrim($line, "\r\n");
    [$tag, $cmd] = array_pad(explode(' ', $line, 3), 3, '');
    $rest = explode(' ', $line, 3)[2] ?? '';
    switch (strtoupper($cmd)) {
        case 'CAPABILITY':
            $send("* CAPABILITY $caps");
            $send("$tag OK done");
            break;
        case 'AUTHENTICATE':
            [, $b64] = array_pad(explode(' ', $rest, 2), 2, '');
            $correct = base64_decode($b64) === "\0$user\0$pass";
            $note($correct ? 'PLAIN-CORRECT' : 'PLAIN-WRONG');
            $ok = $mode === 'sasl' && $correct;
            $send($ok ? "$tag OK Logged in" : "$tag NO [AUTHENTICATIONFAILED] Authentication failed.");
            break;
        case 'LOGIN':
            $expected = '"'.$user.'" "'.str_replace(['\\', '"'], ['\\\\', '\\"'], $pass).'"';
            $note($rest === $expected ? 'LOGIN-CORRECT' : 'LOGIN-WRONG '.$rest);
            $ok = in_array($mode, ['plain-bad', 'login-only'], true) && $rest === $expected;
            $send($ok ? "$tag OK Logged in" : "$tag NO [AUTHENTICATIONFAILED] Authentication failed.");
            break;
        case 'LOGOUT':
            $send('* BYE');
            $send("$tag OK bye");
            break 2;
        default:
            $send("$tag OK");
    }
}
