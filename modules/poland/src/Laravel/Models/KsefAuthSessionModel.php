<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Poland\Ksef\Auth\AuthenticatedContext;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\Dto\TokenInfo;

/**
 * One KSeF authentication and its token pair, encrypted at rest.
 *
 * Tokens are excluded from array/JSON forms and from debug output. The only
 * way to the values is {@see toContext()}, which wraps them in Secrets.
 */
class KsefAuthSessionModel extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'pl_ksef_auth_sessions';

    protected $guarded = [];

    protected $hidden = ['access_token_encrypted', 'refresh_token_encrypted'];

    protected $casts = [
        'access_token_encrypted' => 'encrypted',
        'refresh_token_encrypted' => 'encrypted',
        'access_token_valid_until' => 'datetime',
        'refresh_token_valid_until' => 'datetime',
        'permissions' => 'array',
        'authenticated_at' => 'datetime',
        'last_refreshed_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function toContext(): AuthenticatedContext
    {
        return new AuthenticatedContext(
            (string) $this->reference_number,
            (string) ($this->credential?->nip ?? ''),
            new TokenInfo(Secret::of((string) $this->access_token_encrypted), $this->access_token_valid_until->toDateTimeImmutable()),
            new TokenInfo(Secret::of((string) $this->refresh_token_encrypted), $this->refresh_token_valid_until->toDateTimeImmutable()),
            (array) ($this->permissions ?? []),
            $this->authentication_method,
            $this->authenticated_at->toDateTimeImmutable(),
            $this->last_refreshed_at?->toDateTimeImmutable(),
        );
    }

    public function credential()
    {
        return $this->belongsTo(KsefCredentialModel::class, 'credential_id');
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return collect($this->attributesToArray())->except(['access_token_encrypted', 'refresh_token_encrypted'])->all()
            + ['access_token_encrypted' => '[REDACTED]', 'refresh_token_encrypted' => '[REDACTED]'];
    }
}
