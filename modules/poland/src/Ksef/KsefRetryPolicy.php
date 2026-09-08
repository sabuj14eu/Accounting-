<?php

declare(strict_types=1);

namespace Poland\Ksef;

use Poland\Ksef\Error\KsefException;

/**
 * Controlled retries: exponential backoff, a hard attempt limit, Retry-After
 * honoured, and — the rule that matters — a request is repeated only when
 * the failure proves it never took effect ({@see KsefException::isSafeToRepeat()}).
 *
 * A timeout after a POST is not retried here. It is handed back with
 * outcomeKnown=false so the caller can ask KSeF what it has, which is the
 * only way to know whether the invoice already exists there.
 *
 * Every retry is reported through `$onRetry`, so it lands in the audit trail.
 */
final class KsefRetryPolicy
{
    /** @var list<array{attempt: int, delay: int, category: string, message: string}> */
    private array $attempts = [];

    /**
     * @param \Closure(int):void|null $sleeper receives seconds; injected so tests do not wait
     * @param \Closure(KsefException,int,int):void|null $onRetry (exception, attempt, delaySeconds)
     */
    public function __construct(
        private readonly int $maxAttempts = 4,
        private readonly int $baseDelaySeconds = 2,
        private readonly int $maxDelaySeconds = 60,
        private readonly ?\Closure $sleeper = null,
        private readonly ?\Closure $onRetry = null,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be >= 1');
        }
    }

    /**
     * @template T
     * @param \Closure():T $operation
     * @return T
     * @throws KsefException the last failure, when retries are exhausted or not permitted
     */
    public function execute(\Closure $operation, string $name = 'operation'): mixed
    {
        $this->attempts = [];
        for ($attempt = 1; ; $attempt++) {
            try {
                return $operation();
            } catch (KsefException $e) {
                $decision = $this->decide($e, $attempt);
                $this->attempts[] = ['attempt' => $attempt, 'delay' => $decision['delay'], 'category' => $e->category->value, 'message' => $e->getMessage()];
                if (! $decision['retry']) {
                    throw $e;
                }
                if ($this->onRetry !== null) {
                    ($this->onRetry)($e, $attempt, $decision['delay']);
                }
                ($this->sleeper ?? static fn (int $s) => sleep($s))($decision['delay']);
            }
        }
    }

    /** @return array{retry: bool, delay: int, reason: string} */
    public function decide(KsefException $e, int $attempt): array
    {
        if (! $e->isRetryable()) {
            return ['retry' => false, 'delay' => 0, 'reason' => 'kategoria '.$e->category->value.' nie podlega ponowieniu'];
        }
        if (! $e->outcomeKnown) {
            return ['retry' => false, 'delay' => 0, 'reason' => 'wynik poprzedniej próby jest NIEZNANY — ponowienie mogłoby zdublować operację'];
        }
        if ($attempt >= $this->maxAttempts) {
            return ['retry' => false, 'delay' => 0, 'reason' => 'wyczerpano limit '.$this->maxAttempts.' prób'];
        }
        $delay = min($this->maxDelaySeconds, $this->baseDelaySeconds * (2 ** ($attempt - 1)));
        if ($e->retryAfterSeconds !== null) {
            $delay = min(max($delay, $e->retryAfterSeconds), max($this->maxDelaySeconds, $e->retryAfterSeconds));
        }

        return ['retry' => true, 'delay' => $delay, 'reason' => 'ponowienie za '.$delay.' s'];
    }

    /** @return list<array{attempt: int, delay: int, category: string, message: string}> */
    public function attempts(): array
    {
        return $this->attempts;
    }
}
