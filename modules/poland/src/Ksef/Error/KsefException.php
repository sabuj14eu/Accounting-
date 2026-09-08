<?php

declare(strict_types=1);

namespace Poland\Ksef\Error;

use DateTimeImmutable;
use RuntimeException;

/**
 * One classified failure of one KSeF operation.
 *
 * Carries everything an audit row, a retry policy and a screen need, and
 * nothing a log must not have: the message is built from status codes and the
 * API's own descriptions, already passed through the {@see Redactor}.
 *
 * `$outcomeKnown` is the field the whole submission path hinges on. A timeout
 * after a POST was sent means the server may have done the work; treating that
 * as "failed, try again" is how an invoice gets issued twice.
 */
class KsefException extends RuntimeException
{
    /**
     * @param list<string> $details
     * @param array<string,mixed> $extensions
     */
    public function __construct(
        public readonly KsefErrorCategory $category,
        public readonly string $operation,
        string $safeMessage,
        public readonly ?int $httpStatus = null,
        public readonly ?int $ksefCode = null,
        public readonly array $details = [],
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?string $referenceNumber = null,
        public readonly bool $outcomeKnown = true,
        public readonly ?string $environment = null,
        public readonly array $extensions = [],
        ?\Throwable $previous = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($safeMessage, $httpStatus ?? 0, $previous);
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable();
    }

    public readonly DateTimeImmutable $occurredAt;

    public function isRetryable(): bool
    {
        return $this->category->isRetryable();
    }

    /**
     * Whether the request definitely did not take effect on the KSeF side.
     *
     * Only when this is true may a caller repeat a state-changing request
     * without first asking KSeF what it has.
     */
    public function isSafeToRepeat(): bool
    {
        return $this->outcomeKnown && $this->isRetryable();
    }

    /** @return array<string,mixed> everything an audit or error row stores */
    public function toArray(): array
    {
        return [
            'category' => $this->category->value,
            'retryable' => $this->isRetryable(),
            'outcome_known' => $this->outcomeKnown,
            'operation' => $this->operation,
            'environment' => $this->environment,
            'http_status' => $this->httpStatus,
            'ksef_code' => $this->ksefCode,
            'message' => $this->getMessage(),
            'details' => $this->details,
            'retry_after_seconds' => $this->retryAfterSeconds,
            'reference_number' => $this->referenceNumber,
            'extensions' => $this->extensions,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }

    public static function disabled(string $operation, string $why): self
    {
        return new self(
            KsefErrorCategory::IntegrationDisabled,
            $operation,
            'Integracja KSeF jest wyłączona: '.$why.' Nic nie zostało wysłane ani pobrane. '
            .'To NIE jest informacja o braku faktur — system nie sprawdził KSeF.',
        );
    }

    public static function malformed(string $operation, string $what, ?\Throwable $previous = null, ?string $environment = null): self
    {
        return new self(
            KsefErrorCategory::MalformedResponse,
            $operation,
            'KSeF odpowiedział w sposób, którego system nie potrafi odczytać ('.$what.'). '
            .'Wynik operacji jest NIEZNANY — wymagany przegląd ręczny, nie ponowienie.',
            outcomeKnown: false,
            environment: $environment,
            previous: $previous,
        );
    }
}
