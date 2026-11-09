<?php

namespace App\Console\Commands;

use App\Services\UsageService;
use Illuminate\Console\Command;

class UsagePullCommand extends Command
{
    protected $signature = 'billing:usage:pull';

    protected $description = 'Pull metered usage from provisioning modules that implement ReportsUsage';

    public function handle(UsageService $usage): int
    {
        $this->line('usage records stored  '.$usage->pullAll());

        return self::SUCCESS;
    }
}
