<?php

namespace Plugins\PayPal;

use App\Plugins\Plugin;

class PayPalPlugin extends Plugin
{
    public function register(): void
    {
        $this->registerGateway(PayPalGateway::class);
    }
}
