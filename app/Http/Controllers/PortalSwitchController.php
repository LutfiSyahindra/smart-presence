<?php

namespace App\Http\Controllers;

use App\Support\Portal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PortalSwitchController extends Controller
{
    public function __invoke(Request $request, string $portal): RedirectResponse
    {
        abort_unless(in_array($portal, Portal::all(), true), 404);
        abort_if($portal === Portal::ADMIN && ! Portal::canAccessAdmin($request), 403);

        Portal::remember($request, $portal);

        return to_route(Portal::homeRouteName($request));
    }
}
