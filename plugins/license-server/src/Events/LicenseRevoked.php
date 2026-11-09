<?php

namespace Plugins\LicenseServer\Events;

use Plugins\LicenseServer\Models\License;

/** Fired whenever a licence is revoked. A plain Laravel event: any other plugin can listen to it. */
class LicenseRevoked
{
    public function __construct(public readonly License $license, public readonly string $reason = 'terminated') {}
}
