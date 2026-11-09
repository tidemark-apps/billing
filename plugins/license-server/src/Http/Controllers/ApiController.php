<?php

namespace Plugins\LicenseServer\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\LicenseServer\LicenseManager;
use Plugins\LicenseServer\Signer;

class ApiController extends Controller
{
    public function __construct(private readonly LicenseManager $licenses) {}

    public function verify(Request $request): JsonResponse
    {
        return $this->respond($this->validated($request), fn () => $this->licenses->verify($request));
    }

    public function activate(Request $request): JsonResponse
    {
        return $this->respond($this->validated($request), fn () => $this->licenses->activate($request));
    }

    public function deactivate(Request $request): JsonResponse
    {
        return $this->respond($this->validated($request), fn () => $this->licenses->deactivate($request));
    }

    public function publicKey(): JsonResponse
    {
        return response()->json(['algorithm' => 'ed25519', 'public_key' => Signer::publicKey()]);
    }

    private function validated(Request $request): bool
    {
        $request->validate([
            'key' => ['required', 'string', 'max:80'],
            'product' => ['required', 'string', 'max:64'],
            'identifier' => ['nullable', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:64'],
        ]);

        return true;
    }

    private function respond(bool $ok, \Closure $run): JsonResponse
    {
        [$status, $body] = $run();

        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }
}
