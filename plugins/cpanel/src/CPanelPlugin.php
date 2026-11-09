<?php

namespace Plugins\CPanel;

use App\Plugins\Plugin;

class CPanelPlugin extends Plugin
{
    public function register(): void
    {
        $this->registerModule(CPanelModule::class);
    }
}
