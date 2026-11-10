<?php

namespace App\Console\Commands;

use App\Support\Plan;
use Illuminate\Console\Command;

/** What a host meters: the plan, its limits and the current usage, as JSON on one line. */
class UsageReportCommand extends Command
{
    protected $signature = 'billing:usage';

    protected $description = 'Print the plan, its limits and current usage (clients, staff, invoices) as JSON';

    public function handle(): int
    {
        $this->line(json_encode(Plan::report(), JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
