<?php

declare(strict_types=1);

namespace Poland\Laravel\Support;

use Poland\Ksef\Contracts\KsefClient;
use Poland\Ksef\FakeKsefClient;
use Poland\Ksef\Http\CurlTransport;
use Poland\Ksef\Http\HttpTransport;
use Poland\Ksef\Http\KsefTransportException;
use Poland\Ksef\HttpKsefClient;
use Poland\Ksef\KsefEndpoint;
use Poland\Ksef\KsefEnvironment;
use Poland\Ksef\Parsing\FaInvoiceParser;
use Poland\Ksef\TransportGate;
use Poland\Ksef\UnconfiguredKsefClient;
use Poland\Laravel\Models\KsefCredentialModel;
use Poland\Laravel\Models\TaxProfileModel;
use RuntimeException;

/**
 * Builds the KSeF client for one taxpayer from configuration + the stored
 * credential. This is the ONE place that calls
 * {@see KsefCredentialModel::revealToken()} and the one place that reads the
 * egress registry — both are greppable, and both refuse before any request:
 *
 *  - transport disabled            → UnconfiguredKsefClient (refuses, says so)
 *  - transport fake (never in prod) → FakeKsefClient over local FA fixtures
 *  - transport real                → HttpKsefClient, only when the credential
 *                                     is enabled, InvoiceRead, unexpired and for
 *                                     the SAME environment the app is configured
 *                                     for. A test token is never sent to
 *                                     production, nor the other way round.
 */
final class KsefClientFactory
{
    /**
     * @param array<string,mixed> $ksef   the `poland.ksef` config section
     * @param array<string,mixed> $egress the `poland.egress.ksef` config section
     */
    public function __construct(
        private readonly array $ksef,
        private readonly array $egress,
        private readonly FaInvoiceParser $parser,
        private readonly ?HttpTransport $transport = null,
    ) {
    }

    public function gate(): TransportGate
    {
        return TransportGate::fromConfig($this->ksef);
    }

    public function environment(): KsefEnvironment
    {
        return $this->gate()->environment();
    }

    /**
     * @throws RuntimeException with an operator-readable reason, never with
     *         credential material
     */
    public function forProfile(TaxProfileModel $profile, ?int $pageSize = null, ?string $tokenOverride = null): KsefClient
    {
        $gate = $this->gate();

        if (! $gate->isEnabled()) {
            return new UnconfiguredKsefClient();
        }

        if ($gate->kind() === TransportGate::FAKE) {
            $dir = (string) ($this->ksef['fake_fixtures_dir'] ?? '');
            $files = $dir !== '' ? (glob(rtrim($dir, '/').'/fa*.xml') ?: []) : [];

            return FakeKsefClient::fromXmlFiles(array_values($files), $this->parser, $pageSize ?? 2);
        }

        if ($gate->kind() !== TransportGate::REAL) {
            throw KsefTransportException::config(sprintf('Nieznany rodzaj transportu KSeF "%s".', $gate->kind()));
        }

        $credential = $this->credentialFor($profile, $gate->environment());
        $nip = preg_replace('/\D/', '', (string) ($credential->nip ?: $profile->nip)) ?? '';

        $endpoint = KsefEndpoint::fromEgressRegistry(
            $this->egress,
            $gate->environment(),
            $nip,
            $credential->base_url ?: ($this->ksef['base_url'] ?? null),
        );

        $token = $tokenOverride ?? (string) $credential->revealToken();

        return new HttpKsefClient(
            $this->transport(),
            $endpoint,
            $token,
            $pageSize ?? (int) ($this->ksef['page_size'] ?? 100),
            (int) ($this->ksef['max_retries'] ?? 3),
        );
    }

    /** Why a client cannot be built, in words — null when it can. */
    public function unavailableReason(TaxProfileModel $profile): ?string
    {
        try {
            $client = $this->forProfile($profile);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        if (! $client->isConfigured()) {
            return 'Transport KSeF jest wyłączony (KSEF_TRANSPORT=disabled lub KSEF_TRANSPORT_ENABLED=false). '
                .'System nie widzi faktur — to nie znaczy, że ich nie ma.';
        }

        return null;
    }

    public function transport(): HttpTransport
    {
        return $this->transport ?? new CurlTransport(
            (int) ($this->ksef['connect_timeout_seconds'] ?? 10),
            (int) ($this->ksef['timeout_seconds'] ?? 60),
            ($this->ksef['ca_bundle'] ?? null) ?: null,
        );
    }

    public function credentialFor(TaxProfileModel $profile, ?KsefEnvironment $environment = null): KsefCredentialModel
    {
        $environment ??= $this->environment();

        $credential = KsefCredentialModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->where('environment', $environment->value)
            ->first();

        if ($credential === null) {
            $other = KsefCredentialModel::query()->where('tax_profile_id', $profile->getKey())->pluck('environment')->all();
            throw KsefTransportException::config(sprintf(
                'Brak zapisanego tokenu KSeF dla środowiska "%s"%s. Zapisz go poleceniem `php artisan poland:ksef-token`.',
                $environment->value,
                $other !== [] ? ' (są tokeny dla: '.implode(', ', $other).' — token z jednego środowiska nigdy nie jest wysyłany do drugiego)' : '',
            ));
        }

        $reason = $credential->unusableReason();
        if ($reason !== null) {
            throw KsefTransportException::config($reason);
        }

        return $credential;
    }
}
