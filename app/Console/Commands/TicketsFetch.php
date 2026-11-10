<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Services\Support\ImapPuller;
use App\Services\TicketService;
use Illuminate\Console\Command;

class TicketsFetch extends Command
{
    protected $signature = 'tickets:fetch {--department= : Only this department (slug)}';

    protected $description = 'Pull new support email from departments\' IMAP mailboxes into tickets';

    public function handle(ImapPuller $puller): int
    {
        if (! TicketService::enabled()) {
            return self::SUCCESS; // support is switched off: nothing to do, and no noise in the cron log
        }

        $departments = Department::query()
            ->where('is_active', true)->where('imap_enabled', true)
            ->when($this->option('department'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('id')->get();

        if ($departments->isEmpty() && $this->option('department')) {
            $this->warn('No active department with IMAP enabled has that slug.');

            return self::FAILURE;
        }

        $failed = false;
        foreach ($departments as $department) {
            if (! $department->hasMailbox()) {
                continue;
            }
            $count = $puller->pull($department);
            $department->refresh();
            if ($department->imap_error) {
                $failed = true;
                $this->error($department->slug.': '.$department->imap_error);
            } else {
                $this->line($department->slug.': '.$count.' message'.($count === 1 ? '' : 's').' processed.');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
