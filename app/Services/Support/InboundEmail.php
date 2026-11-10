<?php

namespace App\Services\Support;

use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketAttachments;
use App\Services\TicketService;
use App\Support\Settings;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * Turns one raw email into a ticket or a reply on an existing ticket.
 *
 * Everything that arrives here is hostile until proven otherwise: a reply is only accepted into an existing ticket
 * when the sender is the ticket's requester (or a user of the ticket's client). Anyone else who merely quotes a ticket
 * number gets a new ticket. Staff answer in the admin, never by email.
 *
 * Statuses: created | replied | duplicate | ignored | rejected | no_department.
 * Unexpected failures (database down, ...) are thrown so the caller can retry the message later.
 */
class InboundEmail
{
    public const MARKER = '##- Please type your reply above this line -##';

    private const MAX_BODY = 100000;

    private const MAX_ATTACHMENTS_SCANNED = 30;

    public function __construct(private readonly TicketService $tickets, private readonly TicketAttachments $attachments) {}

    /** @return array{status: string, ticket?: Ticket, reason?: string} */
    public function process(string $rawMime, ?Department $mailbox = null): array
    {
        $rawMime = ltrim($rawMime, "\r\n");
        if (trim($rawMime) === '' || ! str_contains($rawMime, ':')) {
            return ['status' => 'ignored', 'reason' => 'empty'];
        }
        $headers = $this->rawHeaders($rawMime);

        // 1. Auto-mail and loops never become tickets, whatever they are addressed to.
        if ($reason = $this->autoMailReason($headers)) {
            return ['status' => 'ignored', 'reason' => $reason];
        }

        // 2. Which department?
        $department = $mailbox ?? $this->departmentFromRecipients($headers);
        if (! $department) {
            return ['status' => 'no_department', 'reason' => 'No department uses any of the recipient addresses.'];
        }
        if (! $department->is_active) {
            return ['status' => 'no_department', 'reason' => 'The department is not active.'];
        }

        $message = $this->parse($rawMime);
        $from = $this->fromAddress($message, $headers);
        if ($from === null) {
            return ['status' => 'rejected', 'reason' => 'no_sender'];
        }
        [$fromEmail, $fromName] = $from;

        if ($this->isOwnAddress($fromEmail)) {
            return ['status' => 'ignored', 'reason' => 'own_address'];
        }
        if (preg_match('/^(mailer-daemon|postmaster|no-?reply|do-?not-?reply|bounce[sd]?|bounces\+.*)@/i', $fromEmail)) {
            return ['status' => 'ignored', 'reason' => 'automated_sender'];
        }

        // 3. Duplicates.
        $messageId = $this->messageId($headers['message-id'][0] ?? '');
        if ($messageId !== null && TicketMessage::where('message_id', $messageId)->exists()) {
            return ['status' => 'duplicate', 'reason' => 'message_id'];
        }

        // 4. Threading, then the sender check.
        $subjectRaw = $this->subject($message, $headers);
        $existing = $this->findTicket($headers, $subjectRaw);
        $reply = $existing && $this->mayReply($existing, $fromEmail) ? $existing : null;

        if ($reply && $reply->is_locked) {
            return ['status' => 'ignored', 'reason' => 'locked', 'ticket' => $reply];
        }

        // 5. Body and attachments.
        $body = $this->body($message, $reply !== null);
        [$files, $notes] = $this->files($message);
        if ($notes) {
            $body = trim($body."\n\n".implode("\n", $notes));
        }
        if (trim($body) === '' && ! $files) {
            $body = '(no content)';
        } elseif (trim($body) === '') {
            $body = '(attachment only)';
        }

        if ($reply) {
            $author = $this->clientUser($reply, $fromEmail);
            $this->tickets->reply($reply, $body, $author, false, $files, [
                'source' => 'email', 'message_id' => $messageId, 'name' => $fromName ?: $reply->requester_name, 'email' => $fromEmail,
            ]);

            return ['status' => 'replied', 'ticket' => $reply->refresh()];
        }

        // 6. A new ticket.
        $customer = User::query()->where('role', 'customer')->whereRaw('lower(email) = ?', [$fromEmail])->whereNotNull('client_id')->first();
        if (! $customer && Settings::bool('support.reject_unknown_senders', false)) {
            return ['status' => 'rejected', 'reason' => 'unknown_sender'];
        }

        $ticket = $this->tickets->open([
            'department' => $department,
            'subject' => $this->cleanSubject($subjectRaw),
            'body' => $body,
            'name' => $fromName ?: ($customer?->name ?? ''),
            'email' => $fromEmail,
            'client' => $customer?->client,
            'user' => $customer,
            'source' => 'email',
            'message_id' => $messageId,
            'files' => $files,
        ]);

        return ['status' => 'created', 'ticket' => $ticket];
    }

    // ---- parsing -------------------------------------------------------------------------------------------------

    private function parse(string $raw): Message
    {
        $manager = new ClientManager;

        return Message::fromString($raw, $manager->getConfig());
    }

    /**
     * Header block as lowercase name => values (unfolded, not RFC 2047 decoded).
     *
     * @return array<string, array<int, string>>
     */
    private function rawHeaders(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");
        $block = $pos === false ? $raw : substr($raw, 0, $pos);
        $block = preg_replace("/\n[ \t]+/", ' ', $block) ?? $block;
        $out = [];
        foreach (explode("\n", $block) as $line) {
            if (preg_match('/^([!-9;-~]+):[ \t]*(.*)$/', $line, $m)) {
                $out[strtolower($m[1])][] = trim($m[2]);
            }
        }

        return $out;
    }

    private function messageId(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/<([^<>\s]+)>/', $value, $m)) {
            $value = $m[1];
        }
        $value = trim($value, '<> ');

        return $value === '' ? null : mb_substr($value, 0, 250);
    }

    /** @return array{0: string, 1: string}|null [lowercase email, name] */
    private function fromAddress(Message $message, array $headers): ?array
    {
        $email = '';
        $name = '';
        try {
            $addr = $message->getFrom()->first();
            $email = strtolower(trim((string) ($addr->mail ?? '')));
            $name = trim((string) ($addr->personal ?? ''));
        } catch (\Throwable) {
        }
        if ($email === '' && preg_match('/[A-Z0-9._%+\-\']+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $headers['from'][0] ?? '', $m)) {
            $email = strtolower($m[0]);
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return [$email, $this->clean(mb_substr(trim($this->decodeHeader($name), " \t\"'"), 0, 120), false)];
    }

    private function subject(Message $message, array $headers): string
    {
        return $this->clean($this->decodeHeader($headers['subject'][0] ?? ''), false);
    }

    /** RFC 2047 "=?UTF-8?Q?...?=" words to plain UTF-8. */
    private function decodeHeader(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return $value;
        }
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        if ($decoded === false || $decoded === '') {
            $decoded = @mb_decode_mimeheader($value); // musl iconv (Alpine) knows fewer charsets
        }

        return $decoded === false || $decoded === '' ? $value : $decoded;
    }

    /** @return array<int, string> every address found in the headers that name where the mail was delivered */
    private function recipientAddresses(array $headers): array
    {
        $found = [];
        // The most specific (what the MTA actually delivered to) first, then the visible To/Cc.
        foreach (['delivered-to', 'x-original-to', 'envelope-to', 'x-envelope-to', 'x-delivered-to', 'to', 'cc'] as $name) {
            foreach ($headers[$name] ?? [] as $value) {
                if (preg_match_all('/[A-Z0-9._%+\-\']+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $m)) {
                    foreach ($m[0] as $address) {
                        $found[] = strtolower($address);
                    }
                }
            }
        }

        return array_values(array_unique($found));
    }

    private function departmentFromRecipients(array $headers): ?Department
    {
        $addresses = $this->recipientAddresses($headers);
        if (! $addresses) {
            return null;
        }
        $byEmail = Department::query()->where('is_active', true)->whereNotNull('email')->get()->keyBy(fn ($d) => strtolower(trim($d->email)));
        foreach ($addresses as $address) {
            if ($byEmail->has($address)) {
                return $byEmail->get($address);
            }
        }

        return null;
    }

    private function isOwnAddress(string $email): bool
    {
        return Department::query()->whereNotNull('email')->whereRaw('lower(email) = ?', [$email])->exists();
    }

    private function autoMailReason(array $headers): ?string
    {
        $auto = strtolower(trim($headers['auto-submitted'][0] ?? ''));
        if ($auto !== '' && ! str_starts_with($auto, 'no')) {
            return 'auto_submitted';
        }
        if (preg_match('/^(bulk|junk|list|auto_reply)\b/i', trim($headers['precedence'][0] ?? ''))) {
            return 'precedence';
        }
        foreach (['x-autoreply', 'x-autorespond'] as $h) {
            if (isset($headers[$h])) {
                return 'auto_reply';
            }
        }
        if (isset($headers['list-id'])) {
            return 'mailing_list';
        }
        if (isset($headers['return-path']) && preg_match('/^<\s*>$/', trim($headers['return-path'][0]))) {
            return 'null_return_path'; // bounces and most automatic replies
        }

        return null;
    }

    // ---- threading -----------------------------------------------------------------------------------------------

    private function findTicket(array $headers, string $subject): ?Ticket
    {
        $refs = [];
        foreach (array_merge($headers['in-reply-to'] ?? [], $headers['references'] ?? []) as $value) {
            if (preg_match_all('/<([^<>\s]+)>/', $value, $m)) {
                array_push($refs, ...$m[1]);
            }
        }
        $refs = array_slice(array_values(array_unique($refs)), -20);

        if ($refs) {
            // Our own outgoing ids carry the ticket's uuid.
            foreach ($refs as $ref) {
                if (preg_match('/^tkt-([0-9a-f-]{36})-\d+@/i', $ref, $m) && ($ticket = Ticket::where('uuid', strtolower($m[1]))->first())) {
                    return $ticket;
                }
            }
            $row = TicketMessage::whereIn('message_id', $refs)->orderByDesc('id')->first();
            if ($row) {
                return $row->ticket;
            }
        }

        $prefix = (string) Settings::get('support.ticket_prefix', 'TKT-');
        if (preg_match('/\['.preg_quote($prefix, '/').'(\d+)\]/i', $subject, $m)) {
            return Ticket::where('number', $prefix.$m[1])->first();
        }

        return null;
    }

    /** Only the requester, or a user of the ticket's client, may add to a ticket by email. */
    private function mayReply(Ticket $ticket, string $email): bool
    {
        if (strtolower($ticket->requester_email) === $email) {
            return true;
        }

        return $this->clientUser($ticket, $email) !== null;
    }

    private function clientUser(Ticket $ticket, string $email): ?User
    {
        if (! $ticket->client_id) {
            return null;
        }

        return User::query()->where('client_id', $ticket->client_id)->where('role', 'customer')->whereRaw('lower(email) = ?', [$email])->first();
    }

    private function cleanSubject(string $subject): string
    {
        $prefix = (string) Settings::get('support.ticket_prefix', 'TKT-');
        $subject = preg_replace('/\s*\['.preg_quote($prefix, '/').'\d+\]\s*/i', ' ', $subject) ?? $subject;
        do {
            $before = $subject;
            $subject = preg_replace('/^\s*(re|fwd?|aw|sv|antw|vs|tr|rv|enc)\s*(\[\d+\])?\s*:\s*/i', '', $subject) ?? $subject;
        } while ($subject !== $before);
        $subject = trim(preg_replace('/\s+/', ' ', $subject) ?? $subject);

        return $subject === '' ? '(no subject)' : mb_substr($subject, 0, 200);
    }

    // ---- body ----------------------------------------------------------------------------------------------------

    private function body(Message $message, bool $isReply): string
    {
        $text = '';
        try {
            $text = trim((string) $message->getTextBody());
        } catch (\Throwable) {
        }
        if ($text === '') {
            try {
                $html = (string) $message->getHTMLBody();
            } catch (\Throwable) {
                $html = '';
            }
            if ($html !== '') {
                $text = $this->htmlToText($isReply ? $this->withoutBlockquotes($html) : $html);
            }
        }
        $text = $this->clean($text, true);

        // Everything from our marker down is the quoted earlier conversation.
        $marker = strpos($text, '##- Please type your reply above');
        if ($marker !== false) {
            $text = substr($text, 0, $marker);
        }
        if ($isReply) {
            $stripped = $this->stripQuotes($text);
            $text = trim($stripped) !== '' ? $stripped : $text;
        }
        $text = trim(preg_replace("/\n{4,}/", "\n\n\n", $text) ?? $text);

        return mb_substr($text, 0, self::MAX_BODY);
    }

    private function stripQuotes(string $text): string
    {
        $lines = explode("\n", $text);
        $cut = null;
        foreach ($lines as $i => $line) {
            $t = trim($line);
            if (preg_match('/^-{2,}\s*(original message|forwarded message)\s*-{2,}$/i', $t)) {
                $cut = $i;
                break;
            }
            // "On Tue, 3 Nov 2026 at 10:00, Jane <jane@x> wrote:" which clients may wrap over two lines.
            $joined = $t.' '.trim($lines[$i + 1] ?? '');
            if (preg_match('/^(on|el|le|am)\s.{4,300}\s(wrote|escribi\S*|a\s+\S+\s*:|schrieb)\s*:?\s*$/iu', $t) || (str_starts_with(strtolower($t), 'on ') && preg_match('/^on\s.{4,300}\swrote:\s*$/i', $joined))) {
                $cut = $i;
                break;
            }
        }
        if ($cut !== null) {
            $lines = array_slice($lines, 0, $cut);
        }
        $lines = array_filter($lines, fn ($l) => ! str_starts_with(ltrim($l), '>'));

        return implode("\n", $lines);
    }

    private function withoutBlockquotes(string $html): string
    {
        return preg_replace('#<blockquote\b.*?</blockquote>#is', '', $html) ?? $html;
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|head|title)\b.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<!--.*?-->#s', '', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|tr|li|h[1-6]|table|ul|ol|blockquote|pre)\s*>#i', "\n", $html) ?? $html;
        $html = preg_replace('#<(li)\b[^>]*>#i', '- ', $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ ?\n ?/', "\n", $text) ?? $text;

        return trim($text);
    }

    /** Valid UTF-8, no NULs or control characters (newlines and tabs stay when $multiline). */
    private function clean(string $text, bool $multiline): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);
        $pattern = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';

        return preg_replace($pattern, '', $text) ?? $text;
    }

    // ---- attachments ---------------------------------------------------------------------------------------------

    /** @return array{0: array<int, array{name: string, content: string}>, 1: array<int, string>} accepted files, and notes for the rejected */
    private function files(Message $message): array
    {
        $good = [];
        $notes = [];
        try {
            $all = $message->hasAttachments() ? $message->getAttachments() : [];
        } catch (\Throwable) {
            return [[], ['[Some attachments could not be read.]']];
        }
        $max = $this->attachments->maxFiles();
        $i = 0;
        foreach ($all as $attachment) {
            if (++$i > self::MAX_ATTACHMENTS_SCANNED) {
                $notes[] = '[More attachments were not accepted.]';
                break;
            }
            try {
                $content = (string) $attachment->getContent();
                $name = $this->attachments->cleanName((string) ($attachment->name ?: $attachment->filename ?: 'attachment'));
            } catch (\Throwable) {
                $notes[] = '[An attachment could not be read and was not accepted.]';

                continue;
            }
            if ($content === '') {
                continue;
            }
            $file = ['name' => $name, 'content' => $content];
            if (count($good) >= $max) {
                $notes[] = "[Attachment '{$name}' was not accepted: too many attachments (limit {$max}).]";

                continue;
            }
            $problem = $this->attachments->problems([$file])[0] ?? null;
            if ($problem !== null) {
                $notes[] = "[Attachment '{$name}' was not accepted: ".$this->reason($problem, $name).']';

                continue;
            }
            $good[] = $file;
        }

        return [$good, $notes];
    }

    /** The checker words its problems as sentences about the file; trim the file name off the front. */
    private function reason(string $problem, string $name): string
    {
        $problem = str_starts_with($problem, $name) ? ltrim(substr($problem, strlen($name))) : $problem;

        return rtrim($problem, '.');
    }
}
