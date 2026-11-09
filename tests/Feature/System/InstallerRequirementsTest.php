<?php

namespace Tests\Feature\System;

use App\Support\Installer;
use Tests\TestCase;

class InstallerRequirementsTest extends TestCase
{
    public function test_requirements_have_the_shape_the_installer_page_renders(): void
    {
        $checks = Installer::requirements();

        $this->assertNotEmpty($checks);
        foreach ($checks as $check) {
            $this->assertIsString($check['label']);
            $this->assertIsBool($check['ok']);
            $this->assertIsString($check['hint']);
        }
    }
}
