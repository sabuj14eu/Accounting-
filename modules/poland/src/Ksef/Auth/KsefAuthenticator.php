<?php

declare(strict_types=1);

namespace Poland\Ksef\Auth;

use DateTimeImmutable;
use Poland\Ksef\Audit\KsefAuditActions;
use Poland\Ksef\Audit\KsefAuditSink;
use Poland\Ksef\Crypto\KsefCryptography;
use Poland\Ksef\Error\KsefErrorCategory;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\Dto\AuthStatus;
use Poland\Ksef\Transport\KsefTransport;

/**
 * The KSeF-token authentication flow of the pinned guide (`uwierzytelnianie.md` §2.2–§5):
 *
 *   GET public keys → POST /auth/challenge → RSA-OAEP({token}|{timestampMs})
 *   → POST /auth/ksef-token → poll GET /auth/{ref} → POST /auth/token/redeem
 *
 * Status codes are mapped to categories a screen can act on: 415 is a
 * permission problem the taxpayer fixes in the KSeF web application; 450 is
 * a token problem; 460 a certificate problem; 100 for too long is a timeout
 * with a known outcome (nothing was issued), so it may be tried again.
 */
final class KsefAuthenticator
{
    /**
     * @param \Closure(int):void|null $sleeper
     */
    public function __construct(
        private readonly KsefTransport $transport,
        private readonly KsefAuditSink $audit,
        private readonly int $pollAttempts = 20,
        private readonly int $pollDelaySeconds = 1,
        private readonly ?\Closure $sleeper = null,
        private readonly ?DateTimeImmutable $now = null,
    ) {
    }

    public function authenticate(Secret $ksefToken, string $contextNip): AuthenticatedContext
    {
        $nip = preg_replace('/\D/', '', $contextNip) ?? '';
        if (strlen($nip) !== 10) {
            throw new KsefException(KsefErrorCategory::ValidationError, 'authenticate', 'NIP kontekstu musi mieć 10 cyfr.');
        }
        $environment = $this->transport->environment()->value;

        try {
            $certificates = $this->transport->publicKeyCertificates();
            $certificate = KsefCryptography::select($certificates, KsefCryptography::USAGE_TOKEN, $this->now());

            $challenge = $this->transport->authChallenge();
            $encrypted = KsefCryptography::encryptKsefToken($ksefToken, $challenge->timestampMs, $certificate);

            try {
                $initiated = $this->transport->authWithKsefToken($challenge->challenge, $nip, $encrypted, $certificate->publicKeyId);
            } catch (KsefException $e) {
                if ($e->ksefCode !== 21470) {
                    throw $e;
                }
                // The key was rotated between the fetch and the call: the guide's
                // §4.4 says fetch again and repeat once with the new key.
                $certificate = KsefCryptography::select($this->transport->publicKeyCertificates(), KsefCryptography::USAGE_TOKEN, $this->now());
                $challenge = $this->transport->authChallenge();
                $encrypted = KsefCryptography::encryptKsefToken($ksefToken, $challenge->timestampMs, $certificate);
                $initiated = $this->transport->authWithKsefToken($challenge->challenge, $nip, $encrypted, $certificate->publicKeyId);
            }

            $status = $this->waitForCompletion($initiated->referenceNumber, $initiated->authenticationToken);
            $tokens = $this->transport->redeemTokens($initiated->authenticationToken);
        } catch (KsefException $e) {
            $this->audit->record(KsefAuditActions::AUTH_FAILED, [
                'environment' => $environment,
                'context_nip' => $nip,
                'token_fingerprint' => $ksefToken->fingerprint(),
            ] + $e->toArray(), 'failed', $e->getMessage());

            throw $e;
        } catch (\RuntimeException $e) {
            // Certificate selection or cryptography failed before any request.
            $wrapped = new KsefException(KsefErrorCategory::UnknownKsefError, 'authenticate', $e->getMessage(), environment: $environment, previous: $e);
            $this->audit->record(KsefAuditActions::AUTH_FAILED, ['environment' => $environment, 'context_nip' => $nip] + $wrapped->toArray(), 'failed', $wrapped->getMessage());

            throw $wrapped;
        }

        $claims = JwtClaims::decode($tokens->accessToken->token);
        $context = new AuthenticatedContext(
            $initiated->referenceNumber,
            $nip,
            $tokens->accessToken,
            $tokens->refreshToken,
            $claims->permissions(),
            $status->authenticationMethod,
            $this->now(),
        );

        $this->audit->record(KsefAuditActions::AUTH_SUCCEEDED, ['environment' => $environment] + $context->summary());

        return $context;
    }

    public function refresh(AuthenticatedContext $context): AuthenticatedContext
    {
        $environment = $this->transport->environment()->value;
        if (! $context->canRefresh($this->now())) {
            throw new KsefException(
                KsefErrorCategory::AuthenticationError,
                'refresh',
                'Refresh token wygasł — wymagane ponowne uwierzytelnienie tokenem KSeF.',
                environment: $environment,
            );
        }
        try {
            $accessToken = $this->transport->refreshAccessToken($context->refreshToken->token);
        } catch (KsefException $e) {
            $this->audit->record(KsefAuditActions::AUTH_FAILED, ['environment' => $environment, 'phase' => 'refresh', 'reference_number' => $context->referenceNumber] + $e->toArray(), 'failed', $e->getMessage());

            throw $e;
        }
        $refreshed = $context->withAccessToken($accessToken, $this->now());
        $this->audit->record(KsefAuditActions::AUTH_REFRESHED, ['environment' => $environment] + $refreshed->summary());

        return $refreshed;
    }

    private function waitForCompletion(string $referenceNumber, Secret $authenticationToken): AuthStatus
    {
        $environment = $this->transport->environment()->value;
        $status = null;
        for ($attempt = 1; $attempt <= $this->pollAttempts; $attempt++) {
            $status = $this->transport->authStatus($referenceNumber, $authenticationToken);
            if ($status->isSuccess()) {
                return $status;
            }
            if (! $status->isInProgress()) {
                throw $this->failure($status, $referenceNumber, $environment);
            }
            if ($attempt < $this->pollAttempts) {
                ($this->sleeper ?? static fn (int $s) => sleep($s))($this->pollDelaySeconds);
            }
        }

        throw new KsefException(
            KsefErrorCategory::Timeout,
            'authStatus',
            sprintf('Uwierzytelnianie nadal w toku po %d sprawdzeniach (status 100). Nie wydano tokena — można spróbować ponownie.', $this->pollAttempts),
            referenceNumber: $referenceNumber,
            outcomeKnown: true,
            environment: $environment,
        );
    }

    private function failure(AuthStatus $status, string $referenceNumber, string $environment): KsefException
    {
        $category = match (true) {
            $status->code === 415 => KsefErrorCategory::AuthorizationError,
            in_array($status->code, [425, 450, 460], true) => KsefErrorCategory::AuthenticationError,
            default => KsefErrorCategory::UnknownKsefError,
        };
        $hint = match ($status->code) {
            415 => ' Osoba lub token nie ma żadnego uprawnienia w kontekście tego NIP — nadaj uprawnienia InvoiceRead i InvoiceWrite w aplikacji podatnika KSeF.',
            425 => ' Uwierzytelnienie zostało unieważnione przez użytkownika.',
            450 => ' Token KSeF jest nieprawidłowy, nieaktywny, unieważniony lub wydany dla innego środowiska — wygeneruj nowy token w aplikacji podatnika KSeF i zapisz go ponownie.',
            460 => ' Błąd certyfikatu po stronie KSeF.',
            default => '',
        };

        return new KsefException(
            $category,
            'authStatus',
            sprintf('Uwierzytelnienie w KSeF nie powiodło się: %d %s%s.%s', $status->code, $status->description, $status->details !== [] ? ' ('.implode('; ', $status->details).')' : '', $hint),
            null,
            $status->code,
            $status->details,
            referenceNumber: $referenceNumber,
            environment: $environment,
        );
    }

    private function now(): DateTimeImmutable
    {
        return $this->now ?? new DateTimeImmutable();
    }
}
