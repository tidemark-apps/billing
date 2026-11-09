<?php

namespace Plugins\DirectAdmin;

use App\Models\Server;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Small DirectAdmin client for the legacy /CMD_API_* commands. Uses HTTP Basic auth (reseller login plus password or
 * Login Key; recommended by the docs) on port 2222. Replies are URL-encoded; the CMD_API_ACCOUNT_* family answers
 * error=0|1&text=…&details=…, lookups answer arrays such as list[]=…, and several commands (SELECT_USERS, MODIFY_USER)
 * answer a 302 redirect on success. Redirects are therefore not followed and a 3xx counts as success.
 * Docs: https://docs.directadmin.com/developer/api/ and https://docs.directadmin.com/developer/api/legacy-api.html
 */
class DirectAdminClient
{
    public const DEFAULT_PORT = 2222;

    public function __construct(private readonly Server $server) {}

    /**
     * Call a legacy command as the server's reseller. Always POSTed so passwords stay out of URLs.
     *
     * @param  array<string, scalar>  $params
     * @return array<string, mixed> the decoded reply
     *
     * @throws DirectAdminException
     */
    public function command(string $command, array $params = [], bool $post = true, ?string $asUser = null, ?string $asPassword = null): array
    {
        $url = $this->server->baseUrl(self::DEFAULT_PORT).'/'.$command;
        $request = $this->request($asUser ?? $this->server->username, $asPassword ?? (string) $this->server->secret);

        try {
            $response = $post ? $request->asForm()->post($url, $params) : $request->get($url, $params);
        } catch (ConnectionException $e) {
            throw new DirectAdminException($this->connectionMessage($e));
        }

        return $this->parse($response);
    }

    public function panelUrl(): string
    {
        return $this->server->baseUrl(self::DEFAULT_PORT).'/';
    }

    private function request(string $user, string $password): PendingRequest
    {
        return Http::withBasicAuth($user, $password)->timeout(30)->withoutRedirecting()->withOptions(['verify' => (bool) $this->server->verify_ssl]);
    }

    /** @return array<string, mixed> */
    private function parse(Response $response): array
    {
        if (in_array($response->status(), [401, 403], true)) {
            throw new DirectAdminException('Authentication failed. Check the DirectAdmin login and password or Login Key (and its command and IP restrictions).');
        }
        if ($response->redirect()) {
            return []; // e.g. CMD_API_SELECT_USERS / CMD_API_MODIFY_USER redirect to a page on success
        }
        $body = trim($response->body());
        if ($body !== '' && ($body[0] === '<' || stripos($body, '<html') !== false)) {
            throw new DirectAdminException('Unexpected HTML reply from DirectAdmin (HTTP '.$response->status().'). Check the hostname, port and credentials.');
        }
        parse_str(html_entity_decode($body, ENT_QUOTES | ENT_HTML5), $out); // docs: unhtmlentities() then parse_str()
        if ((string) ($out['error'] ?? '0') !== '0') {
            $text = trim(strip_tags((string) ($out['text'] ?? 'Request failed')));
            $details = trim(strip_tags((string) ($out['details'] ?? '')));
            throw new DirectAdminException($details !== '' ? $text.': '.$details : $text);
        }
        if (! $response->successful()) {
            throw new DirectAdminException('DirectAdmin returned HTTP '.$response->status().'.');
        }

        return $out;
    }

    private function connectionMessage(ConnectionException $e): string
    {
        return 'Could not connect to '.$this->server->hostname.': '.mb_substr((string) preg_replace('~https?://\S+~', '[url]', $e->getMessage()), 0, 160);
    }
}
