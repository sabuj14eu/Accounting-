<?php

declare(strict_types=1);

namespace Poland\Ksef\Transport\Dto;

final class SessionInvoicesPage
{
    /** @param list<SessionInvoiceStatus> $invoices */
    public function __construct(
        public readonly array $invoices,
        public readonly ?string $continuationToken,
    ) {
    }
}
