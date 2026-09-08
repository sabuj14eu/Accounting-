<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use PHPUnit\Framework\TestCase;
use Poland\Ksef\Error\KsefErrorCategory;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\KsefRetryPolicy;

/** §18: controlled retries with backoff, a cap, Retry-After, and never on an unknown outcome. */
final class KsefRetryTest extends TestCase
{
    private function failure(KsefErrorCategory $category, bool $outcomeKnown = true, ?int $retryAfter = null): KsefException
    {
        return new KsefException($category, 'op', $category->value, retryAfterSeconds: $retryAfter, outcomeKnown: $outcomeKnown);
    }

    public function test_a_network_error_is_retried_with_exponential_backoff_until_the_cap(): void
    {
        $slept = [];
        $retried = [];
        $policy = new KsefRetryPolicy(4, 2, 60, static function (int $s) use (&$slept): void { $slept[] = $s; }, static function (KsefException $e, int $attempt, int $delay) use (&$retried): void { $retried[] = [$attempt, $delay]; });
        $calls = 0;
        try {
            $policy->execute(function () use (&$calls): never {
                $calls++;
                throw $this->failure(KsefErrorCategory::NetworkError);
            });
            self::fail('expected the last failure');
        } catch (KsefException $e) {
            self::assertSame(KsefErrorCategory::NetworkError, $e->category);
        }
        self::assertSame(4, $calls);
        self::assertSame([2, 4, 8], $slept);
        self::assertSame([[1, 2], [2, 4], [3, 8]], $retried, 'every retry is reported for the audit trail');
        self::assertCount(4, $policy->attempts());
    }

    public function test_success_after_a_retry_returns_the_result(): void
    {
        $policy = new KsefRetryPolicy(3, 1, 10, static fn (int $s) => null);
        $calls = 0;
        $result = $policy->execute(function () use (&$calls): string {
            if (++$calls < 3) {
                throw $this->failure(KsefErrorCategory::ServerError);
            }

            return 'ok';
        });
        self::assertSame('ok', $result);
        self::assertSame(3, $calls);
    }

    public function test_non_retryable_categories_are_never_retried(): void
    {
        foreach ([
            KsefErrorCategory::ValidationError, KsefErrorCategory::AuthenticationError, KsefErrorCategory::AuthorizationError,
            KsefErrorCategory::BusinessRejection, KsefErrorCategory::Duplicate, KsefErrorCategory::MalformedResponse, KsefErrorCategory::IntegrationDisabled,
        ] as $category) {
            $policy = new KsefRetryPolicy(5, 1, 10, static fn (int $s) => null);
            $calls = 0;
            try {
                $policy->execute(function () use (&$calls, $category): never {
                    $calls++;
                    throw $this->failure($category);
                });
            } catch (KsefException) {
            }
            self::assertSame(1, $calls, $category->value.' must not be retried');
        }
    }

    public function test_an_unknown_outcome_is_never_retried_even_when_the_category_is_retryable(): void
    {
        $policy = new KsefRetryPolicy(5, 1, 10, static fn (int $s) => null);
        $calls = 0;
        try {
            $policy->execute(function () use (&$calls): never {
                $calls++;
                throw $this->failure(KsefErrorCategory::Timeout, outcomeKnown: false);
            });
        } catch (KsefException $e) {
            self::assertFalse($e->outcomeKnown);
        }
        self::assertSame(1, $calls, 'repeating a request whose outcome is unknown could duplicate an invoice');
        self::assertStringContainsString('NIEZNANY', $policy->decide($this->failure(KsefErrorCategory::Timeout, false), 1)['reason']);
    }

    public function test_retry_after_from_a_rate_limit_is_honoured(): void
    {
        $policy = new KsefRetryPolicy(4, 2, 60, static fn (int $s) => null);
        $decision = $policy->decide($this->failure(KsefErrorCategory::RateLimit, true, 30), 1);
        self::assertTrue($decision['retry']);
        self::assertSame(30, $decision['delay']);
        // A Retry-After beyond the cap is still honoured: KSeF says when.
        self::assertSame(120, $policy->decide($this->failure(KsefErrorCategory::RateLimit, true, 120), 1)['delay']);
    }

    public function test_the_last_attempt_is_reported_as_exhausted(): void
    {
        $policy = new KsefRetryPolicy(2, 1, 10, static fn (int $s) => null);
        self::assertFalse($policy->decide($this->failure(KsefErrorCategory::NetworkError), 2)['retry']);
        self::assertStringContainsString('wyczerpano', $policy->decide($this->failure(KsefErrorCategory::NetworkError), 2)['reason']);
    }
}
