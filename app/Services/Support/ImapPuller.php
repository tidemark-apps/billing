<?php

namespace App\Services\Support;

use App\Models\Department;
use Illuminate\Support\Facades\Log;

/**
 * Pulls new mail for a department from its IMAP mailbox into tickets.
 *
 * Every message is marked seen (or deleted) once it has been dealt with, including ignored and duplicate ones, so it is
 * never looked at twice. A message whose processing throws is left unread and the error is recorded for the admin.
 */
class ImapPuller
{
    public const BATCH = 50;

    private readonly MailboxConnector $connector;

    public function __construct(private readonly InboundEmail $inbound, ?MailboxConnector $connector = null)
    {
        $this->connector = $connector ?? new WebklexConnector;
    }

    /** Connect and report, without reading any mail. @return array{ok: bool, message: string} */
    public function test(Department $department): array
    {
        if (! $department->imap_host || ! $department->imap_username) {
            return ['ok' => false, 'message' => 'Enter the IMAP host and username first.'];
        }
        try {
            $mailbox = $this->connector->open($department);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->describe($e, $department)];
        }
        try {
            $count = $mailbox->unseenCount();
            $folder = $department->imap_folder ?: 'INBOX';

            return ['ok' => true, 'message' => 'Connected. '.$count.' unread message'.($count === 1 ? '' : 's').' in '.$folder.'.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->describe($e, $department)];
        } finally {
            $mailbox->close();
        }
    }

    /** Fetch unseen mail and turn it into tickets and replies. @return int messages processed */
    public function pull(Department $department): int
    {
        if (! $department->is_active) {
            return 0;
        }
        try {
            $mailbox = $this->connector->open($department);
        } catch (\Throwable $e) {
            $this->record($department, $this->describe($e, $department));

            return 0;
        }

        $done = 0;
        $failed = 0;
        $firstError = null;
        $delete = $department->imap_after === 'delete';
        $deleted = false;
        try {
            foreach ($mailbox->unseenIds(self::BATCH) as $id) {
                try {
                    $raw = $mailbox->fetch($id);
                    $this->inbound->process($raw, $department);
                } catch (\Throwable $e) {
                    $failed++;
                    $firstError ??= $this->describe($e, $department);
                    Log::warning('Ticket email could not be processed: '.$this->describe($e, $department));
                    $this->quietly(fn () => $mailbox->markUnseen($id));

                    continue;
                }
                try {
                    if ($delete) {
                        $mailbox->delete($id);
                        $deleted = true;
                    } else {
                        $mailbox->markSeen($id);
                    }
                    $done++;
                } catch (\Throwable $e) {
                    $failed++;
                    $firstError ??= $this->describe($e, $department);
                }
            }
            if ($deleted) {
                $mailbox->expunge();
            }
        } catch (\Throwable $e) {
            $failed++;
            $firstError ??= $this->describe($e, $department);
        } finally {
            $mailbox->close();
        }

        $this->record($department, $failed ? ($failed > 1 ? $failed.' messages failed. First: ' : '').$firstError : null);

        return $done;
    }

    private function record(Department $department, ?string $error): void
    {
        $department->forceFill(['imap_checked_at' => now(), 'imap_error' => $error ? mb_substr($error, 0, 500) : null])->saveQuietly();
    }

    private function quietly(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable) {
        }
    }

    /** A short message with no secrets in it. */
    private function describe(\Throwable $e, Department $department): string
    {
        $parts = [];
        for ($t = $e; $t && count($parts) < 3; $t = $t->getPrevious()) {
            $m = trim(preg_replace('/\s+/', ' ', $t->getMessage()) ?? '');
            if ($m !== '' && ! in_array($m, $parts, true)) {
                $parts[] = $m;
            }
        }
        $text = implode(': ', $parts) ?: class_basename($e);
        foreach (array_filter([(string) $department->imap_password]) as $secret) {
            $text = str_replace($secret, '***', $text);
            $text = str_replace(urlencode($secret), '***', $text);
            $text = str_replace(base64_encode($secret), '***', $text);
        }

        return mb_substr($text, 0, 300);
    }
}
