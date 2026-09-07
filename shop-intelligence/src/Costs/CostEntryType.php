<?php

declare(strict_types=1);

namespace Shop\Costs;

use Shop\Truth\EvidenceType;

/**
 * How a cost got into the system — §15.
 *
 * The type is not decoration. It decides the evidence, and the evidence decides
 * the certainty and the provenance, so a cost can never be entered by hand and
 * presented with the weight of an invoice.
 */
enum CostEntryType: string
{
    case INVOICE = 'INVOICE';
    case BANK = 'BANK';
    case USER_ENTERED = 'USER_ENTERED';
    case RECURRING = 'RECURRING';
    case ESTIMATE = 'ESTIMATE';

    public function evidence(): EvidenceType
    {
        return match ($this) {
            self::INVOICE => EvidenceType::SUPPLIER_INVOICE,
            self::BANK => EvidenceType::BANK_CONFIRMED,
            self::USER_ENTERED => EvidenceType::USER_DECLARED,
            self::RECURRING => EvidenceType::RECURRING_SCHEDULE,
            self::ESTIMATE => EvidenceType::ESTIMATED,
        };
    }

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
