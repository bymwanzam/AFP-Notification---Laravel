<?php

use App\Http\Middleware\Api;
use App\Http\Middleware\CsrfHeader;
use App\Http\Middleware\Page;
use App\Http\Middleware\WriteLock;
use App\Support\Access;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: fn () => \Illuminate\Support\Facades\Route::middleware([])->group(base_path('routes/install.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [CsrfHeader::class], append: [WriteLock::class]);
        $middleware->alias(['page' => Page::class, 'api.auth' => Api::class]);
        $middleware->redirectGuestsTo('/login');
        // cPanel's Apache/LiteSpeed sits in front of PHP: trust it to say the request came in over HTTPS.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $api = fn (Request $request) => $request->is('api/*');
        $exceptions->render(fn (TokenMismatchException $e, Request $request) => $api($request)
            ? Access::error(403, 'The page has expired. Reload it and try again.') : null);
        $exceptions->render(fn (PostTooLargeException $e, Request $request) => $api($request)
            ? Access::error(413, 'That file is over 10 MB.') : null);
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $api($request) || $request->expectsJson());
    })->create();
