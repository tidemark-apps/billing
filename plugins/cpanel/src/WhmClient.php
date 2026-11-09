<?php

namespace Plugins\CPanel;

use App\Models\Server;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Minimal WHM API 1 client (https://api.docs.cpanel.net). Authenticates with an API token:
 * "Authorization: whm USER:TOKEN".
 *
 * Every WHM API 1 function is documented as GET only (api.docs.cpanel.net/openapi/whm/operation/<function>), with
 * parameters in the query string, so that is what we send. The two calls that carry a password (createacct, passwd)
 * therefore put it in the query string: it is TLS-protected in transit, but WHM's own access log will record it.
 * This client never logs URLs and strips them from connection error messages.
 */
class WhmClient
{
    public const DEFAULT_PORT = 2087;

    public function __construct(private readonly Server $server) {}

    /**
     * @param  array<string, scalar>  $params
     * @return array{data: array<string, mixed>, reason: string}
     *
     * @throws WhmException
     */
    public function call(string $function, array $params = []): array
    {
        $url = $this->server->baseUrl(self::DEFAULT_PORT).'/json-api/'.$function;
        $params['api.version'] = 1;

        $request = Http::withHeaders(['Authorization' => 'whm '.$this->server->username.':'.$this->server->secret])
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(30)
            ->withOptions(['verify' => (bool) $this->server->verify_ssl]);

        try {
            $response = $request->get($url, $params);
        } catch (ConnectionException $e) {
            $reason = preg_replace('~https?://\S+~', '[url]', $e->getMessage());
            throw new WhmException('Could not connect to '.$this->server->hostname.': '.mb_substr((string) $reason, 0, 160));
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new WhmException('Authentication failed. Check the WHM username and API token, and that this server\'s IP is allowed.');
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new WhmException('Unexpected response from WHM (HTTP '.$response->status().'). Check the hostname, port and SSL settings.');
        }

        if ($response->serverError() && ! is_array(data_get($json, 'metadata'))) {
            throw new WhmException('WHM returned HTTP '.$response->status().'. Check the server is healthy and cpsrvd is running.');
        }

        $result = data_get($json, 'metadata.result');
        $reason = (string) (data_get($json, 'metadata.reason') ?? data_get($json, 'error') ?? data_get($json, 'cpanelresult.error') ?? '');
        // Failures normally come back as HTTP 200 with metadata.result = 0 and the explanation in metadata.reason.
        if ((int) $result !== 1) {
            throw new WhmException($reason !== '' ? $reason : 'WHM reported a failure without a reason (HTTP '.$response->status().').');
        }

        return ['data' => (array) ($json['data'] ?? []), 'reason' => $reason];
    }
}
