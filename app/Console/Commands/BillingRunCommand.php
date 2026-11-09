<?php

namespace App\Console\Commands;

use App\Services\BillingRunner;
use Illuminate\Console\Command;

class BillingRunCommand extends Command
{
    protected $signature = 'billing:run';

    protected $description = 'Generate renewal invoices, send reminders, and suspend/terminate overdue services';

    public function handle(BillingRunner $runner): int
    {
        foreach ($runner->run() as $task => $count) {
            $this->line(str_pad(str_replace('_', ' ', $task), 24).$count);
        }

        return self::SUCCESS;
    }
}
