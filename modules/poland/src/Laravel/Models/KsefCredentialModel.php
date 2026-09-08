<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Poland\Ksef\KsefEnvironment;
use Poland\Ksef\KsefScope;

/**
 * KSeF access for one taxpayer.
 *
 * The token is encrypted at rest by the cast, hidden from array and JSON forms,
 * and redacted in debug output. Reading the real value goes through
 * {@see self::revealToken()} — one method, easy to grep for, so any new call
 * site is visible in review.
 *
 * The user's ordinary KSeF password is never stored: authentication uses a token
 * the taxpayer generates in KSeF for this application, which they can revoke
 * without changing anything else.
 */
class KsefCredentialModel extends Model
{
    protected $table = 'pl_ksef_credentials';

    protected $guarded = [];

    protected $hidden = ['token_encrypted'];

    protected $casts = [
        'token_encrypted' => 'encrypted',
        'enabled' => 'bool',
        'token_valid_until' => 'datetime',
        'last_verified_at' => 'datetime',
        'token_permissions' => 'array',
        'observed_permissions' => 'array',
        'last_connection_test_at' => 'datetime',
        'last_connection_ok' => 'bool',
        'last_auth_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'last_sync_ok' => 'bool',
        'production_enabled_at' => 'datetime',
    ];

    /** Wizard steps, in order. `setup_step` is the highest step completed. */
    public const STEP_ENVIRONMENT = 1;

    public const STEP_SELLER = 2;

    public const STEP_TOKEN = 3;

    public const STEP_INVOICE_DEFAULTS = 4;

    public const STEP_TESTED = 5;

    /** Everything a real connection needs, regardless of whether it was tested. */
    public function isConfigured(): bool
    {
        return $this->hasToken()
            && preg_match('/^\d{10}$/', (string) $this->nip) === 1
            && trim((string) $this->seller_address_line1) !== '';
    }

    /** @return list<string> what is still missing, in words a screen can show */
    public function missingConfiguration(): array
    {
        $missing = [];
        if (preg_match('/^\d{10}$/', (string) $this->nip) !== 1) {
            $missing[] = 'NIP podatnika';
        }
        if (trim((string) $this->seller_address_line1) === '') {
            $missing[] = 'adres sprzedawcy na fakturze';
        }
        if (! $this->hasToken()) {
            $missing[] = 'token KSeF';
        }

        return $missing;
    }

    /** @return list<string> */
    public function permissionsMissing(): array
    {
        $observed = (array) ($this->observed_permissions ?? []);

        return $observed === [] ? [] : KsefScope::missingFrom($observed);
    }

    public function environmentEnum(): KsefEnvironment
    {
        return KsefEnvironment::from($this->environment);
    }

    public function scopeEnum(): KsefScope
    {
        return KsefScope::from($this->scope);
    }

    /**
     * The only accessor for the token.
     *
     * Refuses to hand out a token for a scope this application may not hold, so
     * a row edited in the database to say "InvoiceWrite" cannot be used.
     */
    public function revealToken(): ?string
    {
        $this->scopeEnum()->assertAllowed();

        return $this->token_encrypted;
    }

    /** Store a new KSeF token: encrypted value plus a display fingerprint, never the value anywhere else. */
    public function storeToken(string $token): void
    {
        $this->token_encrypted = $token;
        $this->token_fingerprint = substr(hash('sha256', $token), 0, 12);
        $this->last_verified_at = null;
        $this->observed_permissions = null;
    }

    public function hasToken(): bool
    {
        return $this->token_encrypted !== null && $this->token_encrypted !== '';
    }

    public function isUsable(): bool
    {
        return $this->enabled
            && $this->hasToken()
            && $this->scopeEnum()->isAllowed()
            && ($this->token_valid_until === null || $this->token_valid_until->isFuture());
    }

    /** Why it cannot be used, for a screen that must not just say "error". */
    public function unusableReason(): ?string
    {
        if (! $this->enabled) {
            return 'Integracja KSeF jest wyłączona dla tego podatnika.';
        }
        if (! $this->hasToken()) {
            return 'Nie zapisano tokenu KSeF.';
        }
        if (! $this->scopeEnum()->isAllowed()) {
            return sprintf('Zakres "%s" nie jest dozwolony — wymagany InvoiceRead.', $this->scope);
        }
        if ($this->token_valid_until !== null && $this->token_valid_until->isPast()) {
            return 'Token KSeF wygasł '.$this->token_valid_until->format('d.m.Y').'.';
        }

        return null;
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return array_merge(
            collect($this->attributesToArray())->except('token_encrypted')->all(),
            ['token_encrypted' => $this->hasToken() ? '[REDACTED]' : null],
        );
    }
}
