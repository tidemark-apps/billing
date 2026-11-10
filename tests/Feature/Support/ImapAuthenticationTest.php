<?php

namespace Tests\Feature\Support;

use App\Models\Department;
use App\Services\Support\SaslClient;
use App\Services\Support\WebklexConnector;
use Tests\TestCase;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;

/** Signing in against a scripted IMAP server over a real socket: SASL PLAIN first (like mail clients), LOGIN as the fallback. */
class ImapAuthenticationTest extends TestCase
{
    private string $log;

    /** @var resource|null */
    private $process = null;

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        @unlink($this->log ?? '');
        @unlink(($this->log ?? '').'.ready');
        parent::tearDown();
    }

    /** Start the scripted server and try to connect with the department's credentials. */
    private function attempt(string $mode, string $password = 'p@ss"w\\ord é'): ?\Throwable
    {
        $this->log = tempnam(sys_get_temp_dir(), 'imap');
        $port = random_int(20000, 60000);
        $this->process = proc_open([PHP_BINARY, base_path('tests/Fixtures/fake-imap.php'), (string) $port, $mode, $this->log], [], $pipes);
        for ($i = 0; $i < 100 && ! file_exists($this->log.'.ready'); $i++) {
            usleep(50000);
        }
        $department = new Department(['imap_host' => '127.0.0.1', 'imap_port' => $port, 'imap_encryption' => 'none', 'imap_validate_cert' => false, 'imap_username' => 'u@example.com']);
        $department->imap_password = $password;

        try {
            $client = (new WebklexConnectorProbe)->client($department);
            $client->connect();
            $client->disconnect();

            return null;
        } catch (\Throwable $e) {
            return $e;
        }
    }

    /** @return array<int, string> */
    private function commands(): array
    {
        return array_values(array_filter(explode("\n", (string) file_get_contents($this->log))));
    }

    public function test_it_signs_in_with_sasl_plain_when_the_server_offers_it(): void
    {
        $this->assertNull($this->attempt('sasl'));

        $this->assertSame(['PLAIN-CORRECT'], $this->commands(), 'PLAIN succeeded, so LOGIN was never needed');
    }

    public function test_it_falls_back_to_login_when_plain_is_rejected(): void
    {
        $this->assertNull($this->attempt('plain-bad'));

        $this->assertSame(['PLAIN-CORRECT', 'LOGIN-CORRECT'], $this->commands());
    }

    public function test_it_uses_login_when_the_server_has_no_sasl(): void
    {
        $this->assertNull($this->attempt('login-only'));

        $this->assertSame(['LOGIN-CORRECT'], $this->commands());
    }

    public function test_the_error_says_what_each_mechanism_got_back_and_never_the_password(): void
    {
        $e = $this->attempt('reject', 'wrong-password');

        $this->assertInstanceOf(AuthFailedException::class, $e);
        $this->assertStringContainsString('AUTHENTICATE PLAIN → NO [AUTHENTICATIONFAILED] Authentication failed.', $e->getMessage());
        $this->assertStringContainsString('LOGIN → NO [AUTHENTICATIONFAILED]', $e->getMessage());
        $this->assertStringContainsString('offered: SASL-IR AUTH=PLAIN AUTH=LOGIN', $e->getMessage());
        $this->assertStringNotContainsString('wrong-password', $e->getMessage());
    }
}

/** Exposes the configured client without opening a folder. */
class WebklexConnectorProbe extends WebklexConnector
{
    public function client(Department $department): SaslClient
    {
        return $this->build($department);
    }
}
