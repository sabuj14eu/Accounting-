<?php

declare(strict_types=1);

namespace Poland\Laravel\Auth;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Panel;

/**
 * Gives the ERP's customer panel a complete authentication flow of its own:
 * sign in, create account, password reset, e-mail verification.
 *
 * Applied from the application's `booting` phase — after every panel provider
 * has registered its panel and before Filament has read the panels to define
 * their routes — so it holds whatever order the packages boot in. Upstream's
 * own provider is not edited.
 *
 * Fail-closed rule for registration: when e-mail verification is required
 * and the mailer cannot deliver (log, array, none), registration is switched
 * OFF rather than letting people register into a verification prompt no
 * e-mail will ever satisfy. ACCOUNT_REQUIRE_EMAIL_VERIFICATION=false is the
 * explicit, logged operator decision to run without verification.
 */
final class CustomerPanel
{
    public function apply(): void
    {
        if (! (bool) config('poland.customer_panel.enabled', true)) {
            return;
        }

        if (! class_exists(Filament::class)) {
            return;
        }

        $panel = Filament::getPanel((string) config('poland.customer_panel.id', 'app'), isStrict: false);

        if ($panel === null) {
            return;
        }

        $this->configure($panel);
    }

    public function configure(Panel $panel): void
    {
        $requireVerification = self::emailVerificationRequired();
        $registration = self::registrationEnabled();

        $panel
            ->brandName((string) config('poland.customer_panel.brand', 'SignalMesh Accounting'))
            ->login()
            ->passwordReset()
            ->emailVerification(isRequired: $requireVerification);

        if ($registration) {
            $panel->registration();
        }

        $panel->navigationItems([
            NavigationItem::make((string) config('poland.customer_panel.navigation_label', 'Rozliczenie miesiąca (PL)'))
                ->url(fn (): string => url((string) config('poland.routes.prefix', 'poland')))
                ->icon('heroicon-o-calculator')
                ->group('Tax & Compliance')
                ->sort(-1),
        ]);
    }

    public static function emailVerificationRequired(): bool
    {
        return (bool) config('poland.customer_panel.require_email_verification', true);
    }

    /** Can the configured mailer actually put a message in somebody's inbox? */
    public static function mailIsDeliverable(): bool
    {
        $mailer = (string) (config('mail.default') ?? '');

        return $mailer !== '' && ! in_array($mailer, ['log', 'array', 'null', 'failover-log'], true);
    }

    public static function registrationEnabled(): bool
    {
        if (! (bool) config('poland.customer_panel.registration', true)) {
            return false;
        }

        // Registration into an unsatisfiable verification prompt is a trap,
        // not a feature. Refuse rather than degrade silently.
        return ! self::emailVerificationRequired() || self::mailIsDeliverable();
    }
}
