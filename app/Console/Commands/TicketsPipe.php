<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Services\Support\InboundEmail;
use App\Services\TicketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Receive one email on STDIN, for "pipe to a program" forwarders (cPanel, Exim, Postfix, qmail).
 *
 * Exit codes follow sysexits(3) so the mail server does the right thing:
 *   0   handled: a ticket or reply was created, or the mail was a duplicate, auto-reply or otherwise deliberately dropped
 *   67  EX_NOUSER: no department uses the recipient address (the sender gets a bounce)
 *   75  EX_TEMPFAIL: support is switched off or something failed: the mail server keeps the message and retries
 * Nothing from the message is ever printed.
 */
class TicketsPipe extends Command
{
    protected $signature = 'tickets:pipe {--department= : Department slug; when omitted the recipient headers decide}';

    protected $description = 'Create a ticket or reply from a raw email read on STDIN (for mail server pipes)';

    public function handle(InboundEmail $inbound): int
    {
        if (! TicketService::enabled()) {
            $this->line('Support is not enabled yet; try again later.');

            return 75;
        }

        $mailbox = null;
        if ($slug = $this->option('department')) {
            $mailbox = Department::where('slug', $slug)->first();
            if (! $mailbox) {
                $this->line('Unknown department.');

                return 67;
            }
        }

        try {
            $result = $inbound->process($this->raw(), $mailbox);
        } catch (\Throwable $e) {
            Log::error('tickets:pipe failed: '.$e->getMessage());
            $this->line('Temporary failure; try again later.');

            return 75;
        }

        if ($result['status'] === 'no_department') {
            $this->line('Unknown recipient.');

            return 67;
        }
        $this->line($result['status']);

        return self::SUCCESS;
    }

    private function raw(): string
    {
        if (app()->bound('tickets.stdin')) {
            return (string) app('tickets.stdin'); // tests
        }

        return (string) stream_get_contents(STDIN, 60 * 1024 * 1024);
    }
}
