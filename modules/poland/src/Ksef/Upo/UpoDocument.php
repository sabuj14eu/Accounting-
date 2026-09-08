<?php

declare(strict_types=1);

namespace Poland\Ksef\Upo;

use DateTimeImmutable;

/**
 * An official acknowledgement (Urzędowe Poświadczenie Odbioru) as KSeF
 * issued it: the XML verbatim, plus the fields read out of it. Nothing here
 * is produced locally — a UPO exists only when KSeF returned one.
 */
final class UpoDocument implements \JsonSerializable
{
    /**
     * @param list<array{seller_nip: ?string, ksef_number: ?string, invoice_number: ?string, issue_date: ?string, sent_at: ?string, ksef_number_assigned_at: ?string, document_hash: ?string, mode: ?string}> $documents
     */
    public function __construct(
        public readonly string $xml,
        public readonly string $xmlHash,
        public readonly string $receivingEntity,
        public readonly ?string $sessionReference,
        public readonly ?string $contextNip,
        public readonly ?string $formCode,
        public readonly ?string $logicalStructure,
        public readonly array $documents,
        public readonly DateTimeImmutable $retrievedAt,
        public readonly bool $schemaValid,
        /** @var list<string> */
        public readonly array $schemaErrors,
    ) {
    }

    /** @return array<string,?string>|null */
    public function documentFor(string $ksefNumber): ?array
    {
        foreach ($this->documents as $document) {
            if (($document['ksef_number'] ?? null) === $ksefNumber) {
                return $document;
            }
        }

        return null;
    }

    public function jsonSerialize(): array
    {
        return [
            'xml_hash' => $this->xmlHash,
            'receiving_entity' => $this->receivingEntity,
            'session_reference' => $this->sessionReference,
            'context_nip' => $this->contextNip,
            'form_code' => $this->formCode,
            'logical_structure' => $this->logicalStructure,
            'documents' => $this->documents,
            'retrieved_at' => $this->retrievedAt->format(DATE_ATOM),
            'schema_valid' => $this->schemaValid,
            'schema_errors' => $this->schemaErrors,
        ];
    }
}
