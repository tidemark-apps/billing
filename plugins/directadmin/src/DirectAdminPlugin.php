<?php

namespace Plugins\DirectAdmin;

use App\Plugins\Plugin;

class DirectAdminPlugin extends Plugin
{
    public function register(): void
    {
        $this->registerModule(DirectAdminModule::class);
    }
}
