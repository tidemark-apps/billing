<?php

namespace App\Plugins;

/** The uploaded plugin is already installed: the admin must confirm the upgrade, reinstall or downgrade. */
class PluginConfirmationRequired extends PluginInstallException
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $from,
        public readonly string $to,
        public readonly string $relation,
        public readonly bool $bundled,
    ) {
        parent::__construct("$name is already installed ($from → $to). Confirmation required.");
    }
}
