<?php

declare(strict_types=1);

namespace Poland\Adapters\Null;

use Poland\Contracts\DocumentSubmitter;
use Poland\Contracts\FilingResult;
use Poland\Contracts\PreparedDocument;
use Poland\Reporting\FilingChannel;
use RuntimeException;

/**
 * The default submitter for every channel: one that refuses.
 *
 * Registered so that the container always resolves a submitter and the
 * application's shape is honest — the filing stage EXISTS and is not
 * implemented. It throws rather than returning a failed FilingResult, because a
 * failed result is something a caller can shrug off, and "we tried to file and
 * it didn't work" must never be indistinguishable from "there is no filing
 * integration at all".
 */
final class UnconfiguredSubmitter implements DocumentSubmitter
{
    public function __construct(private readonly FilingChannel $channel) {}

    public function channel(): FilingChannel
    {
        return $this->channel;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function submit(PreparedDocument $document): FilingResult
    {
        throw new RuntimeException($this->message());
    }

    public function checkStatus(string $reference): FilingResult
    {
        throw new RuntimeException($this->message());
    }

    private function message(): string
    {
        return sprintf(
            'Kanał "%s" nie jest zaimplementowany — ten system niczego nie wysyła do %s. '
            .'Dokument można przygotować i pobrać, a złożenie zarejestrować ręcznie wraz z '
            .'numerem referencyjnym otrzymanym od organu.',
            $this->channel->value,
            $this->channel->label(),
        );
    }
}
