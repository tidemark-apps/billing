<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Concerns\ManagesTwoFactor;
use Illuminate\Http\Request;

class SecurityController extends PortalController
{
    use ManagesTwoFactor;

    protected string $routePrefix = 'portal.security';

    public function index(Request $request)
    {
        return view('portal.security', $this->panel($request));
    }
}
