<?php

namespace Plugins\Enhance;

use App\Models\Server;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Enhance panels are always served over HTTPS. Thin wrapper over the Enhance (orchd) REST API: https://apidocs.enhance.com. Bearer access token auth (spec v12.26: securitySchemes.bearerAuth). Error bodies follow the HttpError schema {code, message, detail}. */
class EnhanceClient
{
    public function __construct(private readonly Server $server) {}

    public function orgId(): string
    {
        return (string) $this->server->option('org_id');
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) $this->server->secret)
            ->acceptJson()
            ->asJson()
            ->timeout(45)
            ->withOptions(['verify' => (bool) $this->server->verify_ssl]);
    }

    private function url(string $path): string
    {
        $port = (int) ($this->server->port ?: 443);

        return 'https://'.$this->server->hostname.($port === 443 ? '' : ':'.$port).'/api/'.ltrim($path, '/');
    }

    /** @param  array<string, mixed>  $query */
    public function get(string $path, array $query = []): Response
    {
        return $this->check($this->http()->get($this->url($path), $query));
    }

    /** @param  array<string, mixed>  $body @param  array<string, mixed>  $query */
    public function post(string $path, array $body = [], array $query = []): Response
    {
        $url = $this->url($path).($query ? '?'.http_build_query($query) : '');

        return $this->check($this->http()->post($url, $body));
    }

    /** @param  array<string, mixed>  $body */
    public function patch(string $path, array $body): Response
    {
        return $this->check($this->http()->patch($this->url($path), $body));
    }

    /** @param  array<string, mixed>  $query */
    public function delete(string $path, array $query = []): Response
    {
        $url = $this->url($path).($query ? '?'.http_build_query($query) : '');

        return $this->check($this->http()->delete($url));
    }

    private function check(Response $response): Response
    {
        if ($response->failed()) {
            throw new \RuntimeException($this->describe($response));
        }

        return $response;
    }

    private function describe(Response $r): string
    {
        $json = $r->json();
        // Spec error body (HttpError): {code: string (required), message?: string, detail?: string}
        $msg = null;
        if (is_array($json)) {
            $msg = trim(implode(' - ', array_filter(array_map(
                fn ($k) => isset($json[$k]) && is_scalar($json[$k]) ? (string) $json[$k] : null,
                ['code', 'message', 'detail'],
            ))));
        }

        return 'Enhance API error (HTTP '.$r->status().')'.($msg ? ': '.$msg : '');
    }
}
