<?php

namespace App\Console\Commands;

use App\Services\AutoPayer;
use Illuminate\Console\Command;

class AutopayCommand extends Command
{
    protected $signature = 'billing:autopay';

    protected $description = 'Charge saved payment methods for invoices that are due, and retry failed automatic payments';

    public function handle(AutoPayer $autoPayer): int
    {
        foreach ($autoPayer->run() as $name => $count) {
            $this->line(str_pad(str_replace('_', ' ', $name), 24).$count);
        }

        return self::SUCCESS;
    }
}
