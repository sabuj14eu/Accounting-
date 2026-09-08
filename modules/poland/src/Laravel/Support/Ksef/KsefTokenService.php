<?php

declare(strict_types=1);

namespace Poland\Laravel\Support\Ksef;

use Poland\Ksef\Auth\AuthenticatedContext;
use Poland\Ksef\Auth\KsefAuthenticator;
use Poland\Ksef\Error\KsefErrorCategory;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\KsefScope;
use Poland\Ksef\Secret;
use Poland\Laravel\Models\KsefAuthSessionModel;
use Poland\Laravel\Models\KsefCredentialModel;

/**
 * Hands out a valid access token for a credential, reusing a stored
 * authentication while it lives, refreshing while the refresh token lives,
 * and re-authenticating with the KSeF token only when it must — KSeF counts
 * authentications against its limits.
 */
final class KsefTokenService
{
    public function __construct(
        private readonly KsefTransportFactory $transports,
        private readonly LaravelKsefAuditSink $audit,
        private readonly KsefErrorRecorder $errors,
    ) {
    }

    public function context(KsefCredentialModel $credential, bool $fresh = false): AuthenticatedContext
    {
        $credential->scopeEnum()->assertAllowed();
        $now = now()->toDateTimeImmutable();

        if (! $fresh) {
            $stored = KsefAuthSessionModel::query()
                ->where('credential_id', $credential->getKey())
                ->where('status', KsefAuthSessionModel::STATUS_ACTIVE)
                ->orderByDesc('authenticated_at')
                ->first();

            if ($stored !== null) {
                $context = $stored->toContext();
                if ($context->isAccessTokenValid($now)) {
                    $stored->forceFill(['last_used_at' => now()])->save();

                    return $context;
                }
                if ($context->canRefresh($now)) {
                    try {
                        $refreshed = $this->authenticator($credential)->refresh($context);
                        $stored->forceFill([
                            'access_token_encrypted' => $refreshed->accessToken->token->reveal(),
                            'access_token_valid_until' => $refreshed->accessToken->validUntil,
                            'last_refreshed_at' => now(),
                            'last_used_at' => now(),
                        ])->save();

                        return $refreshed;
                    } catch (KsefException $e) {
                        $this->errors->record($e, (int) $credential->tax_profile_id);
                        if (! $e->isRetryable()) {
                            $stored->forceFill(['status' => KsefAuthSessionModel::STATUS_EXPIRED])->save();
                        } else {
                            throw $e;
                        }
                    }
                } else {
                    $stored->forceFill(['status' => KsefAuthSessionModel::STATUS_EXPIRED])->save();
                }
            }
        }

        $token = $credential->revealToken();
        if ($token === null || $token === '') {
            throw new KsefException(KsefErrorCategory::AuthenticationError, 'authenticate', 'Nie zapisano tokenu KSeF dla tego podatnika.', environment: $credential->environment);
        }

        try {
            $context = $this->authenticator($credential)->authenticate(Secret::of($token), (string) $credential->nip);
        } catch (KsefException $e) {
            $this->errors->record($e, (int) $credential->tax_profile_id);
            $credential->forceFill(['last_error' => $e->getMessage()])->save();

            throw $e;
        }

        KsefAuthSessionModel::query()
            ->where('credential_id', $credential->getKey())
            ->where('status', KsefAuthSessionModel::STATUS_ACTIVE)
            ->update(['status' => KsefAuthSessionModel::STATUS_EXPIRED]);

        KsefAuthSessionModel::create([
            'credential_id' => $credential->getKey(),
            'environment' => $credential->environment,
            'reference_number' => $context->referenceNumber,
            'access_token_encrypted' => $context->accessToken->token->reveal(),
            'access_token_valid_until' => $context->accessToken->validUntil,
            'refresh_token_encrypted' => $context->refreshToken->token->reveal(),
            'refresh_token_valid_until' => $context->refreshToken->validUntil,
            'authentication_method' => $context->authenticationMethod,
            'permissions' => $context->permissions,
            'status' => KsefAuthSessionModel::STATUS_ACTIVE,
            'authenticated_at' => $context->authenticatedAt,
            'last_used_at' => now(),
        ]);

        $credential->forceFill([
            'last_auth_at' => now(),
            'last_verified_at' => now(),
            'observed_permissions' => $context->permissions,
            'last_error' => null,
        ])->save();

        $missing = KsefScope::missingFrom($context->permissions);
        if ($context->permissions !== [] && $missing !== []) {
            // Authenticated, but the token cannot do everything the product
            // needs. Recorded loudly; the operation that needs the scope fails
            // with AUTHORIZATION_ERROR from KSeF itself.
            $credential->forceFill(['last_error' => 'Token nie ma uprawnień: '.implode(', ', $missing)])->save();
        }

        return $context;
    }

    public function revokeAll(KsefCredentialModel $credential): int
    {
        $count = 0;
        $sessions = KsefAuthSessionModel::query()
            ->where('credential_id', $credential->getKey())
            ->where('status', KsefAuthSessionModel::STATUS_ACTIVE)
            ->get();
        foreach ($sessions as $session) {
            try {
                $this->transports->transport()->revokeCurrentSession(Secret::of((string) $session->access_token_encrypted));
            } catch (KsefException $e) {
                $this->errors->record($e, (int) $credential->tax_profile_id);
            }
            $session->forceFill(['status' => KsefAuthSessionModel::STATUS_REVOKED, 'revoked_at' => now()])->save();
            $count++;
        }

        return $count;
    }

    private function authenticator(KsefCredentialModel $credential): KsefAuthenticator
    {
        return new KsefAuthenticator(
            $this->transports->transport(),
            $this->audit->forProfile((int) $credential->tax_profile_id),
            (int) config('poland.ksef.auth.poll_attempts', 20),
            (int) config('poland.ksef.auth.poll_delay_seconds', 1),
        );
    }
}
