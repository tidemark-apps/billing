<?php

namespace App\Http\Controllers\Portal;

use App\Models\ApiToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ApiTokenController extends PortalController
{
    public function index(Request $request)
    {
        return view('portal.api.index', ['tokens' => $request->user()->apiTokens()->latest()->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        [, $plain] = ApiToken::issue($request->user(), $data['name']);

        return redirect()->route('portal.api-tokens')->with('new_token', $plain)->with('status', 'Token created. Copy it now; it will not be shown again.');
    }

    public function destroy(Request $request, string $token): RedirectResponse
    {
        $request->user()->apiTokens()->findOrFail($token)->delete();

        return redirect()->route('portal.api-tokens')->with('status', 'Token revoked.');
    }
}
