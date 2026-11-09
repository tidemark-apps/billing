<?php

namespace Plugins\Enhance;

use App\Plugins\Plugin;

class EnhancePlugin extends Plugin
{
    public function register(): void
    {
        $this->registerModule(EnhanceModule::class);
    }
}
