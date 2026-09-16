<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Portal;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request): string {
            return Portal::loginUrl(Portal::rememberEntry($request));
        });

        $middleware->redirectUsersTo(function (Request $request): string {
            Portal::rememberEntry($request);

            return Portal::homeUrl($request);
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
