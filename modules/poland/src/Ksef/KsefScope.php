<?php

declare(strict_types=1);

namespace Poland\Ksef;

/**
 * KSeF permissions this application is allowed to hold.
 *
 * Decision history, because widening a scope is never a config edit:
 *  - 2026-09-07: InvoiceRead only. Nothing issued invoices.
 *  - 2026-09-08: InvoiceWrite added by explicit specification (KSeF 2.0 /
 *    FA(3) integration, §12 "Sales Order → Invoice → KSeF"). Issuing an
 *    invoice in the taxpayer's name is now a product feature, and every
 *    submission is an explicit user action recorded in the audit trail.
 *
 * Everything else stays refused: managing credentials, introspection of
 * other people's sessions, sub-unit administration and enforcement
 * operations have no place in an accounting application. They are listed so
 * a refusal can name them.
 */
enum KsefScope: string
{
    case InvoiceRead = 'InvoiceRead';
    case InvoiceWrite = 'InvoiceWrite';

    // Present so the refusal can name them. NEVER add these to allowed().
    case CredentialsRead = 'CredentialsRead';
    case CredentialsManage = 'CredentialsManage';
    case Introspection = 'Introspection';
    case SubunitManage = 'SubunitManage';
    case EnforcementOperations = 'EnforcementOperations';
    case CollectiveIdentifierManage = 'CollectiveIdentifierManage';

    /** @return list<self> */
    public static function allowed(): array
    {
        return [self::InvoiceRead, self::InvoiceWrite];
    }

    /** The scopes a token must carry for the whole feature set to work. */
    public static function required(): array
    {
        return [self::InvoiceRead, self::InvoiceWrite];
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
            .'Rozszerzenie zakresu to świadoma zmiana w kodzie z uzasadnieniem, nie wpis w konfiguracji.',
            $this->value,
            implode(', ', array_map(static fn (self $s): string => $s->value, self::allowed())),
        ));
    }

    /**
     * Which of the required scopes a set of observed permissions lacks.
     *
     * @param list<string> $observed permission names as KSeF reports them
     * @return list<string>
     */
    public static function missingFrom(array $observed): array
    {
        $missing = [];
        foreach (self::required() as $scope) {
            if (! in_array($scope->value, $observed, true)) {
                $missing[] = $scope->value;
            }
        }

        return $missing;
    }
}
