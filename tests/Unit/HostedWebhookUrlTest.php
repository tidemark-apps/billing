<?php

namespace Tests\Unit;

use App\Jobs\DeliverWebhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HostedWebhookUrlTest extends TestCase
{
    #[DataProvider('blocked')]
    public function test_private_and_reserved_addresses_are_refused(string $url): void
    {
        $this->assertFalse(DeliverWebhook::isPublicUrl($url));
    }

    public static function blocked(): array
    {
        return [
            ['http://127.0.0.1/hook'], ['http://10.0.0.5/hook'], ['https://192.168.1.1/'],
            ['http://169.254.169.254/latest/meta-data'], ['http://[::1]/'], ['http://172.16.0.1/'], ['not a url'],
        ];
    }

    public function test_a_public_address_is_allowed(): void
    {
        $this->assertTrue(DeliverWebhook::isPublicUrl('https://93.184.216.34/hook'));
    }
}
