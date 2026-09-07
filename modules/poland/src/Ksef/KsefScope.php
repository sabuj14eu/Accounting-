<?php

declare(strict_types=1);

namespace Poland\Ksef;

/**
 * KSeF permissions this application is allowed to hold.
 *
 * InvoiceRead only. Issuing invoices in a taxpayer's name is a materially
 * different act from reading their inbox, and nothing here needs it. The write
 * scopes are listed so that granting one is a deliberate code change with a
 * reason, rather than a config value somebody widens quietly.
 */
enum KsefScope: string
{
    case InvoiceRead = 'InvoiceRead';

    // Present so the refusal can name them. NEVER add these to allowed().
    case InvoiceWrite = 'InvoiceWrite';
    case CredentialsManage = 'CredentialsManage';

    /** @return list<self> */
    public static function allowed(): array
    {
        return [self::InvoiceRead];
    }

    public function isAllowed(): bool
    {
        return in_array($this, self::allowed(), true);
    }

    public function assertAllowed(): void
    {
        if ($this->isAllowed()) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Zakres KSeF "%s" nie jest dozwolony w tej aplikacji. Dozwolone: %s. '
            .'Wystawianie faktur w imieniu podatnika to inne uprawnienie niż odczyt '
            .'jego skrzynki i wymaga świadomej zmiany w kodzie, nie w konfiguracji.',
            $this->value,
            implode(', ', array_map(static fn (self $s): string => $s->value, self::allowed())),
        ));
    }
}
