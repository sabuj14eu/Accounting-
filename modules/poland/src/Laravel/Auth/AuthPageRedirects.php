<?php

declare(strict_types=1);

namespace Poland\Laravel\Auth;

/**
 * Where the upstream ERP's authentication pages send people, so that the
 * application has ONE customer flow instead of three.
 *
 * Upstream ships a Jetstream login at /login, a registration at /register that
 * asks the customer to pick a property-management role, and a separate
 * Filament login per panel. Every one of those entry points now lands on the
 * customer panel's own pages. This class holds the decision and nothing else,
 * so it can be tested without a framework; the middleware next to it only
 * asks the question and issues the redirect.
 *
 * The administration panel is deliberately NOT here. It keeps its own login
 * at /admin/login, admits only super_admin, and never interferes with a
 * customer signing in.
 */
final class AuthPageRedirects
{
    /**
     * @param  callable(string): ?string  $routeUrl  URL for a named route, or null if the route does not exist
     */
    public static function targetFor(
        string $path,
        bool $authenticated,
        callable $routeUrl,
        string $panelUrl,
        string $panelId = 'app',
    ): ?string {
        $path = '/'.trim($path, '/');
        $panelUrl = rtrim($panelUrl, '/');
        $named = static fn (string $suffix): ?string => $routeUrl("filament.{$panelId}.auth.{$suffix}");

        return match ($path) {
            // The front door. The panel itself sends a guest to its login page.
            '/', '/home', '/dashboard' => $panelUrl,

            '/login' => $authenticated
                ? $panelUrl
                : ($named('login') ?? $panelUrl.'/login'),

            '/register' => $authenticated
                ? $panelUrl
                : ($named('register') ?? $named('login') ?? $panelUrl.'/login'),

            '/forgot-password' => $named('password-reset.request') ?? $panelUrl.'/password-reset/request',

            '/email/verify' => $named('email-verification.prompt') ?? $panelUrl,

            default => null,
        };
    }
}
