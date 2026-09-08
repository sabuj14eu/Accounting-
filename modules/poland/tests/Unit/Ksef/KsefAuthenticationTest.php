<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use PHPUnit\Framework\TestCase;
use Poland\Ksef\Audit\InMemoryAuditSink;
use Poland\Ksef\Audit\KsefAuditActions;
use Poland\Ksef\Auth\JwtClaims;
use Poland\Ksef\Auth\KsefAuthenticator;
use Poland\Ksef\Error\KsefErrorCategory;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\FakeKsefTransport;
use Poland\Tests\Support\KsefFixtures;

/** §5 authentication: the exact call sequence of the pinned guide, and every failure classified. */
final class KsefAuthenticationTest extends TestCase
{
    private function authenticator(FakeKsefTransport $fake, InMemoryAuditSink $audit, int $polls = 5): KsefAuthenticator
    {
        return new KsefAuthenticator($fake, $audit, $polls, 0, static fn (int $s) => null, KsefFixtures::now());
    }

    public function test_valid_credentials_follow_the_documented_sequence_and_yield_tokens(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $audit = new InMemoryAuditSink();
        $context = $this->authenticator($fake, $audit)->authenticate(Secret::of('valid-ksef-token'), KsefFixtures::SELLER_NIP);

        self::assertSame(
            ['publicKeyCertificates', 'authChallenge', 'authWithKsefToken', 'authStatus', 'redeemTokens'],
            array_map(static fn (array $c): string => $c['operation'], $fake->calls),
        );
        $init = $fake->calls[2]['args'];
        self::assertSame(KsefFixtures::SELLER_NIP, $init['contextNip']);
        self::assertStringStartsWith('FAKEKEY-TOKEN-', $init['publicKeyId'], 'the KsefTokenEncryption key, not the symmetric one');
        self::assertGreaterThan(300, $init['encryptedTokenLength']);

        self::assertSame(['InvoiceRead', 'InvoiceWrite'], $context->permissions);
        self::assertTrue($context->isAccessTokenValid(KsefFixtures::now()));
        self::assertTrue($context->canRefresh(KsefFixtures::now()));
        self::assertTrue($audit->has(KsefAuditActions::AUTH_SUCCEEDED));
        self::assertFalse($audit->has(KsefAuditActions::AUTH_FAILED));
    }

    public function test_status_100_is_polled_until_200(): void
    {
        $fake = (new FakeKsefTransport(now: KsefFixtures::now()))->authStatusSequence([100, 100, 200]);
        $this->authenticator($fake, new InMemoryAuditSink())->authenticate(Secret::of('valid-ksef-token'), KsefFixtures::SELLER_NIP);
        self::assertSame(3, $fake->callCount('authStatus'));
    }

    public function test_invalid_credentials_are_an_authentication_error_with_a_hint(): void
    {
        $fake = (new FakeKsefTransport(now: KsefFixtures::now()))->authStatusSequence([450]);
        $audit = new InMemoryAuditSink();
        try {
            $this->authenticator($fake, $audit)->authenticate(Secret::of('bad-token'), KsefFixtures::SELLER_NIP);
            self::fail('expected failure');
        } catch (KsefException $e) {
            self::assertSame(KsefErrorCategory::AuthenticationError, $e->category);
            self::assertSame(450, $e->ksefCode);
            self::assertFalse($e->isRetryable());
            self::assertStringContainsString('wygeneruj nowy token', $e->getMessage());
        }
        self::assertSame(0, $fake->callCount('redeemTokens'));
        self::assertTrue($audit->has(KsefAuditActions::AUTH_FAILED));
    }

    public function test_insufficient_permission_is_an_authorization_error(): void
    {
        $fake = (new FakeKsefTransport(now: KsefFixtures::now()))->authStatusSequence([415]);
        try {
            $this->authenticator($fake, new InMemoryAuditSink())->authenticate(Secret::of('token-without-grants'), KsefFixtures::SELLER_NIP);
            self::fail('expected failure');
        } catch (KsefException $e) {
            self::assertSame(KsefErrorCategory::AuthorizationError, $e->category);
            self::assertStringContainsString('InvoiceRead i InvoiceWrite', $e->getMessage());
        }
    }

    public function test_a_revoked_authentication_is_reported_as_such(): void
    {
        $fake = (new FakeKsefTransport(now: KsefFixtures::now()))->authStatusSequence([425]);
        $this->expectException(KsefException::class);
        $this->expectExceptionMessageMatches('/unieważnion/u');
        $this->authenticator($fake, new InMemoryAuditSink())->authenticate(Secret::of('revoked'), KsefFixtures::SELLER_NIP);
    }

    public function test_an_authentication_still_pending_after_all_polls_is_a_timeout_that_is_safe_to_retry(): void
    {
        $fake = (new FakeKsefTransport(now: KsefFixtures::now()))->authStatusSequence([100]);
        try {
            $this->authenticator($fake, new InMemoryAuditSink(), 3)->authenticate(Secret::of('slow'), KsefFixtures::SELLER_NIP);
            self::fail('expected timeout');
        } catch (KsefException $e) {
            self::assertSame(KsefErrorCategory::Timeout, $e->category);
            self::assertTrue($e->outcomeKnown, 'no token was issued; repeating is safe');
            self::assertTrue($e->isSafeToRepeat());
        }
        self::assertSame(3, $fake->callCount('authStatus'));
    }

    public function test_a_rotated_public_key_is_refetched_once_per_the_guide(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $fake->failNext('authWithKsefToken', new KsefException(KsefErrorCategory::ValidationError, 'authWithKsefToken', 'unknown key', 400, 21470));
        $this->authenticator($fake, new InMemoryAuditSink())->authenticate(Secret::of('valid-ksef-token'), KsefFixtures::SELLER_NIP);
        self::assertSame(2, $fake->callCount('publicKeyCertificates'));
        self::assertSame(2, $fake->callCount('authWithKsefToken'));
    }

    public function test_an_expired_refresh_token_requires_full_reauthentication(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $authenticator = new KsefAuthenticator($fake, new InMemoryAuditSink(), 5, 0, static fn (int $s) => null, KsefFixtures::now()->modify('+8 days'));
        $context = $this->authenticator($fake, new InMemoryAuditSink())->authenticate(Secret::of('valid-ksef-token'), KsefFixtures::SELLER_NIP);
        try {
            $authenticator->refresh($context);
            self::fail('expected refusal');
        } catch (KsefException $e) {
            self::assertSame(KsefErrorCategory::AuthenticationError, $e->category);
            self::assertStringContainsString('ponowne uwierzytelnienie', $e->getMessage());
        }
    }

    public function test_refresh_replaces_only_the_access_token(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $audit = new InMemoryAuditSink();
        $auth = $this->authenticator($fake, $audit);
        $context = $auth->authenticate(Secret::of('valid-ksef-token'), KsefFixtures::SELLER_NIP);
        $refreshed = $auth->refresh($context);
        self::assertNotSame($context->accessToken->token->reveal(), $refreshed->accessToken->token->reveal());
        self::assertSame($context->refreshToken->token->reveal(), $refreshed->refreshToken->token->reveal());
        self::assertSame($context->referenceNumber, $refreshed->referenceNumber);
        self::assertNotNull($refreshed->lastRefreshedAt);
        self::assertTrue($audit->has(KsefAuditActions::AUTH_REFRESHED));
    }

    public function test_a_malformed_nip_is_refused_before_any_request(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        try {
            $this->authenticator($fake, new InMemoryAuditSink())->authenticate(Secret::of('t'), '12345');
            self::fail('expected refusal');
        } catch (KsefException $e) {
            self::assertSame(KsefErrorCategory::ValidationError, $e->category);
        }
        self::assertSame([], $fake->calls);
    }

    public function test_jwt_claims_are_read_for_display_only(): void
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode(['exp' => 1800000000, 'permissions' => ['InvoiceRead'], 'roles' => [['name' => 'x']]])), '+/', '-_'), '=');
        $claims = JwtClaims::decode(Secret::of('eyJhbGciOiJSUzI1NiJ9.'.$payload.'.sig'));
        self::assertSame(['InvoiceRead'], $claims->permissions());
        self::assertSame(1800000000, $claims->expiresAt()?->getTimestamp());
        self::assertTrue(JwtClaims::decode(Secret::of('not-a-jwt'))->isEmpty());
    }
}
