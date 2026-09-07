<?php

declare(strict_types=1);

namespace Shop\Ai;

/**
 * Everything a model is allowed to do here, and everything it is not — §22.
 *
 * Written as a closed enumeration rather than as a paragraph in a document,
 * because a paragraph cannot fail a test. The forbidden list is verbatim from
 * the specification; each case exists precisely so that asking for it throws.
 */
enum AiAction: string
{
    // ---- Permitted: reading and proposing.
    case READ_DOCUMENT = 'READ_DOCUMENT';
    case EXTRACT_DATA = 'EXTRACT_DATA';
    case CLASSIFY = 'CLASSIFY';
    case SUGGEST_MATCH = 'SUGGEST_MATCH';
    case IDENTIFY_ANOMALY = 'IDENTIFY_ANOMALY';
    case EXPLAIN = 'EXPLAIN';
    case SUMMARISE = 'SUMMARISE';

    // ---- Forbidden: acting.
    case CHANGE_TRANSACTION = 'CHANGE_TRANSACTION';
    case CHANGE_INVENTORY = 'CHANGE_INVENTORY';
    case CREATE_CASH_PAYMENT = 'CREATE_CASH_PAYMENT';
    case CREATE_REVENUE = 'CREATE_REVENUE';
    case CREATE_EXPENSE = 'CREATE_EXPENSE';
    case MARK_INVOICE_PAID = 'MARK_INVOICE_PAID';
    case CHANGE_ACCOUNTING_RECORD = 'CHANGE_ACCOUNTING_RECORD';
    case CALCULATE_OFFICIAL_TAX = 'CALCULATE_OFFICIAL_TAX';
    case SUBMIT_TO_GOVERNMENT = 'SUBMIT_TO_GOVERNMENT';
    case ACCESS_KSEF_CREDENTIALS = 'ACCESS_KSEF_CREDENTIALS';
    case MODIFY_ACCOUNTS_SYSTEM = 'MODIFY_ACCOUNTS_SYSTEM';

    public function isPermitted(): bool
    {
        return match ($this) {
            self::READ_DOCUMENT, self::EXTRACT_DATA, self::CLASSIFY, self::SUGGEST_MATCH,
            self::IDENTIFY_ANOMALY, self::EXPLAIN, self::SUMMARISE => true,
            default => false,
        };
    }

    public function refusalReason(): string
    {
        return match ($this) {
            self::CHANGE_TRANSACTION => 'A model may propose a change to a transaction; a person applies it.',
            self::CHANGE_INVENTORY => 'Stock moves when goods move, not when a model concludes they did.',
            self::CREATE_CASH_PAYMENT => 'Inventing a cash payment is how an unpaid invoice becomes invisible.',
            self::CREATE_REVENUE => 'Revenue is recorded from a till, a terminal, a platform or a count.',
            self::CREATE_EXPENSE => 'An expense needs a document or a bank line behind it.',
            self::MARK_INVOICE_PAID => 'Paid is a fact about money moving, not a conclusion from a document.',
            self::CHANGE_ACCOUNTING_RECORD => 'This application has no write access to the accounting system.',
            self::CALCULATE_OFFICIAL_TAX => 'Official tax is calculated by the accounting engine, not here and not by a model.',
            self::SUBMIT_TO_GOVERNMENT => 'Nothing in this application files anything with anybody.',
            self::ACCESS_KSEF_CREDENTIALS => 'KSeF tokens are bearer credentials for filing in the owner\'s name and live in the other application.',
            self::MODIFY_ACCOUNTS_SYSTEM => 'The relationship with the accounting system is read-only and one-way.',
            default => 'Permitted.',
        };
    }
}
