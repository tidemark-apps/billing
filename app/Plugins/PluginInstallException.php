<?php

namespace App\Plugins;

/** A plugin archive was rejected or an install/remove step failed; the message is safe to show to the admin. */
class PluginInstallException extends \RuntimeException {}
