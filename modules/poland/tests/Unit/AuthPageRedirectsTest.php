<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poland\Laravel\Auth\AuthPageRedirects;

/**
 * One customer flow: every upstream entry point lands on the customer panel,
 * and nothing here ever points at the administration panel.
 */
final class AuthPageRedirectsTest extends TestCase
{
    /** Named routes as the running application would report them. */
    private function routes(): callable
    {
        $known = [
            'filament.app.auth.login' => 'https://account.example/app/login',
            'filament.app.auth.register' => 'https://account.example/app/register',
            'filament.app.auth.password-reset.request' => 'https://account.example/app/password-reset/request',
            'filament.app.auth.email-verification.prompt' => 'https://account.example/app/email-verification/prompt',
        ];

        return static fn (string $name): ?string => $known[$name] ?? null;
    }

    public function test_the_front_door_and_the_old_dashboard_lead_to_the_panel(): void
    {
        foreach (['/', '', '/home', '/dashboard', 'dashboard/'] as $path) {
            $this->assertSame(
                'https://account.example/app',
                AuthPageRedirects::targetFor($path, false, $this->routes(), 'https://account.example/app/'),
                "path '{$path}'",
            );
        }
    }

    public function test_a_guest_is_sent_to_the_panel_s_own_sign_in_and_sign_up(): void
    {
        $this->assertSame(
            'https://account.example/app/login',
            AuthPageRedirects::targetFor('/login', false, $this->routes(), 'https://account.example/app'),
        );
        $this->assertSame(
            'https://account.example/app/register',
            AuthPageRedirects::targetFor('/register', false, $this->routes(), 'https://account.example/app'),
        );
        $this->assertSame(
            'https://account.example/app/password-reset/request',
            AuthPageRedirects::targetFor('/forgot-password', false, $this->routes(), 'https://account.example/app'),
        );
        $this->assertSame(
            'https://account.example/app/email-verification/prompt',
            AuthPageRedirects::targetFor('/email/verify', false, $this->routes(), 'https://account.example/app'),
        );
    }

    public function test_a_signed_in_person_who_opens_login_or_register_goes_into_the_application(): void
    {
        foreach (['/login', '/register'] as $path) {
            $this->assertSame(
                'https://account.example/app',
                AuthPageRedirects::targetFor($path, true, $this->routes(), 'https://account.example/app'),
            );
        }
    }

    /** Registration switched off (no mailer): the sign-up link falls back to sign-in, never to upstream's form. */
    public function test_without_a_registration_route_sign_up_falls_back_to_sign_in(): void
    {
        $routes = static fn (string $name): ?string => $name === 'filament.app.auth.login'
            ? 'https://account.example/app/login'
            : null;

        $this->assertSame(
            'https://account.example/app/login',
            AuthPageRedirects::targetFor('/register', false, $routes, 'https://account.example/app'),
        );
    }

    public function test_every_other_path_is_left_alone(): void
    {
        foreach (['/poland', '/app', '/app/login', '/admin', '/admin/login', '/billing/premium', '/up'] as $path) {
            $this->assertNull(
                AuthPageRedirects::targetFor($path, false, $this->routes(), 'https://account.example/app'),
                "path '{$path}'",
            );
        }
    }

    /** The administration panel is never a redirect target: it is separate by design. */
    public function test_nothing_ever_redirects_to_the_administration_panel(): void
    {
        foreach (['/', '/login', '/register', '/forgot-password', '/dashboard', '/email/verify'] as $path) {
            foreach ([true, false] as $authenticated) {
                $target = (string) AuthPageRedirects::targetFor($path, $authenticated, $this->routes(), 'https://account.example/app');
                $this->assertStringNotContainsString('/admin', $target, "path '{$path}'");
            }
        }
    }

    public function test_the_panel_id_selects_the_route_names(): void
    {
        $routes = static fn (string $name): ?string => $name === 'filament.konto.auth.login'
            ? 'https://account.example/konto/login'
            : null;

        $this->assertSame(
            'https://account.example/konto/login',
            AuthPageRedirects::targetFor('/login', false, $routes, 'https://account.example/konto', 'konto'),
        );
    }
}
