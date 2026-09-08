<?php

declare(strict_types=1);

namespace Poland\Laravel\Support\Ksef;

use Poland\Ksef\Http\CurlHttpClient;
use Poland\Ksef\KsefEndpoints;
use Poland\Ksef\KsefEnvironment;
use Poland\Ksef\Transport\DisabledKsefTransport;
use Poland\Ksef\Transport\FakeKsefTransport;
use Poland\Ksef\Transport\KsefTransport;
use Poland\Ksef\Transport\RealKsefTransport;
use Poland\Ksef\TransportGate;

/**
 * Builds THE transport for this deployment from configuration, through the
 * gate. There is exactly one environment per deployment (KSEF_ENVIRONMENT);
 * credentials for another environment are never used.
 */
final class KsefTransportFactory
{
    private ?KsefTransport $transport = null;

    /** @param array<string,mixed> $config the `poland.ksef` array */
    public function __construct(private readonly array $config)
    {
    }

    public function gate(): TransportGate
    {
        return TransportGate::fromConfig($this->config);
    }

    public function environment(): KsefEnvironment
    {
        return $this->gate()->environment();
    }

    public function apiVersion(): string
    {
        return (string) ($this->config['api_version'] ?? 'unknown');
    }

    public function transport(): KsefTransport
    {
        if ($this->transport !== null) {
            return $this->transport;
        }
        $gate = $this->gate();
        $environment = $gate->environment();

        if (! $gate->isEnabled()) {
            return $this->transport = new DisabledKsefTransport($environment, $gate->status()['detail']);
        }
        if ($gate->isFake()) {
            // Only reachable outside production: the gate threw otherwise.
            return $this->transport = new FakeKsefTransport($environment);
        }

        $endpoints = new KsefEndpoints((array) ($this->config['base_urls'] ?? []), $this->config['base_url'] ?? null);
        $caBundle = $this->config['ca_bundle'] ?? null;

        return $this->transport = new RealKsefTransport(
            new CurlHttpClient(is_string($caBundle) && $caBundle !== '' ? $caBundle : null),
            $environment,
            $endpoints->baseUrl($environment),
            (array) ($this->config['timeouts'] ?? []),
        );
    }

    /** Tests replace the transport (with a scripted fake) without touching config. */
    public function useTransport(KsefTransport $transport): void
    {
        if ($this->gate()->environment()->isProduction() && $transport->kind() !== KsefTransport::KIND_REAL) {
            throw new \RuntimeException('Produkcja może używać wyłącznie prawdziwego transportu KSeF.');
        }
        $this->transport = $transport;
    }
}
