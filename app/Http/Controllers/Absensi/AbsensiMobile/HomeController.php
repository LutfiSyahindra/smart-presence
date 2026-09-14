<?php

namespace App\Http\Controllers\Absensi\AbsensiMobile;

use App\Http\Controllers\Controller;
use App\Support\Portal;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('absensi/absensi-mobile/Home', [
            'canAccessAdmin' => Portal::canAccessAdmin($request),
        ]);
    }
}
