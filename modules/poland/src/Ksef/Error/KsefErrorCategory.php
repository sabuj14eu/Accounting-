<?php

declare(strict_types=1);

namespace Poland\Ksef\Error;

/**
 * Every failure the integration can report, classified by what a caller may
 * do about it. The category decides retryability; the message never does.
 */
enum KsefErrorCategory: string
{
    case AuthenticationError = 'AUTHENTICATION_ERROR';
    case AuthorizationError = 'AUTHORIZATION_ERROR';
    case NetworkError = 'NETWORK_ERROR';
    case Timeout = 'TIMEOUT';
    case RateLimit = 'RATE_LIMIT';
    case ServerError = 'SERVER_ERROR';
    case ValidationError = 'VALIDATION_ERROR';
    case BusinessRejection = 'BUSINESS_REJECTION';
    case Duplicate = 'DUPLICATE';
    case CursorError = 'CURSOR_ERROR';
    case MalformedResponse = 'MALFORMED_RESPONSE';
    case IntegrationDisabled = 'INTEGRATION_DISABLED';
    case UnknownKsefError = 'UNKNOWN_KSEF_ERROR';

    /**
     * Whether repeating the same request, unchanged, can reasonably succeed.
     *
     * Invalid input, a rejected invoice, missing permissions and a malformed
     * answer do not get better with repetition; a lost connection might.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::NetworkError, self::Timeout, self::RateLimit, self::ServerError => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::AuthenticationError => 'Błąd uwierzytelnienia',
            self::AuthorizationError => 'Brak uprawnień',
            self::NetworkError => 'Błąd sieci',
            self::Timeout => 'Przekroczony czas oczekiwania',
            self::RateLimit => 'Limit żądań KSeF',
            self::ServerError => 'Błąd po stronie KSeF',
            self::ValidationError => 'Błąd walidacji',
            self::BusinessRejection => 'Odrzucenie merytoryczne',
            self::Duplicate => 'Duplikat',
            self::CursorError => 'Błąd kursora synchronizacji',
            self::MalformedResponse => 'Niezrozumiała odpowiedź KSeF',
            self::IntegrationDisabled => 'Integracja wyłączona',
            self::UnknownKsefError => 'Nieznany błąd KSeF',
        };
    }
}
