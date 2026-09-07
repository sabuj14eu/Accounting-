<?php

declare(strict_types=1);

namespace Poland\Certainty;

/**
 * Who can clear a caveat.
 *
 * The audit's §3: a status must tell the user what kind of problem exists AND
 * who can resolve it. Without this the interface can only say "something is
 * wrong", and a taxpayer goes looking for a document that does not exist while
 * the real fix is an operator task.
 */
enum ResolvedBy: string
{
    /** The taxpayer, by supplying something they have. */
    case User = 'user';

    /** Whoever runs the system — configuration, deployment, a retry. */
    case Operator = 'operator';

    /** A qualified person confirming figures against official sources. */
    case Accountant = 'accountant';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Podatnik — wgraj brakujący dokument',
            self::Operator => 'Administrator systemu — konfiguracja lub ponowne uruchomienie',
            self::Accountant => 'Księgowy — potwierdzenie w źródle urzędowym',
        };
    }

    public function englishLabel(): string
    {
        return match ($this) {
            self::User => 'TAXPAYER',
            self::Operator => 'SYSTEM OPERATOR',
            self::Accountant => 'ACCOUNTANT',
        };
    }
}
