<?php

declare(strict_types=1);

namespace Poland\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Poland\Laravel\Auth\AuthPageRedirects;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends the upstream ERP's own authentication pages to the customer panel.
 *
 * Registered on the `web` middleware group, so it runs for the upstream
 * routes (/, /login, /register, /forgot-password, /dashboard) and never for
 * the panel's own routes, whose paths do not match. GET only: a POST to an
 * upstream form is left to that form's own handling.
 */
final class RedirectLegacyAuthPages
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || ! (bool) config('poland.customer_panel.enabled', true)) {
            return $next($request);
        }

        $panelId = (string) config('poland.customer_panel.id', 'app');
        $panelPath = (string) config('poland.customer_panel.path', 'app');

        $target = AuthPageRedirects::targetFor(
            $request->path(),
            $request->user() !== null,
            static fn (string $name): ?string => Route::has($name) ? route($name) : null,
            url($panelPath),
            $panelId,
        );

        return $target === null ? $next($request) : redirect($target);
    }
}
