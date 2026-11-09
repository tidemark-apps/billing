<?php

namespace Plugins\Stripe;

use App\Plugins\Plugin;

class StripePlugin extends Plugin
{
    public function register(): void
    {
        $this->registerGateway(StripeGateway::class);
    }
}
