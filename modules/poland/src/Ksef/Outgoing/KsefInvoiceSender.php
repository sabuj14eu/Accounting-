<?php

declare(strict_types=1);

namespace Poland\Ksef\Outgoing;

use DateTimeImmutable;
use Poland\Ksef\Audit\KsefAuditActions;
use Poland\Ksef\Audit\KsefAuditSink;
use Poland\Ksef\Crypto\KsefCryptography;
use Poland\Ksef\Error\KsefErrorCategory;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\Fa3\Fa3Schema;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\KsefTransport;
use Poland\Ksef\Upo\UpoDocument;
use Poland\Ksef\Upo\UpoParser;

/**
 * The interactive-session path of the pinned guide (`sesja-interaktywna.md`):
 * open a session with a fresh AES key wrapped for the Ministry, send the
 * encrypted FA(3), poll its status, fetch the UPO, close the session.
 *
 * The one rule that is not in the guide but is in this system's law: a send
 * whose outcome is unknown (timeout, 5xx) is never repeated blindly. The
 * session's invoice list is asked whether the document — identified by its
 * SHA-256 — already arrived. Found → adopt it. Not found → MANUAL_REVIEW,
 * because "not found yet" and "never arrived" look the same from here.
 */
final class KsefInvoiceSender
{
    /** @param \Closure(int):void|null $sleeper */
    public function __construct(
        private readonly KsefTransport $transport,
        private readonly KsefAuditSink $audit,
        private readonly ?\Closure $sleeper = null,
        private readonly ?DateTimeImmutable $now = null,
    ) {
    }

    public function openSession(Secret $accessToken): SessionHandle
    {
        $certificates = $this->transport->publicKeyCertificates();
        $certificate = KsefCryptography::select($certificates, KsefCryptography::USAGE_SYMMETRIC, $this->now());
        $material = KsefCryptography::newSessionMaterial($certificate);

        try {
            $opened = $this->transport->openOnlineSession($accessToken, Fa3Schema::formCode(), $material->encryptedSymmetricKeyBase64, $material->initializationVectorBase64, $material->publicKeyId);
        } catch (KsefException $e) {
            if ($e->ksefCode !== 21470) {
                throw $e;
            }
            $certificate = KsefCryptography::select($this->transport->publicKeyCertificates(), KsefCryptography::USAGE_SYMMETRIC, $this->now());
            $material = KsefCryptography::newSessionMaterial($certificate);
            $opened = $this->transport->openOnlineSession($accessToken, Fa3Schema::formCode(), $material->encryptedSymmetricKeyBase64, $material->initializationVectorBase64, $material->publicKeyId);
        }

        $this->audit->record(KsefAuditActions::SESSION_OPENED, [
            'environment' => $this->transport->environment()->value,
            'session_reference' => $opened->referenceNumber,
            'valid_until' => $opened->validUntil->format(DATE_ATOM),
            'form_code' => Fa3Schema::identifier(),
            'public_key_id' => $material->publicKeyId,
        ]);

        return new SessionHandle($opened->referenceNumber, $opened->validUntil, $material);
    }

    /**
     * @throws KsefException with outcomeKnown=false when the send happened but the
     *         reference could not be recovered — the caller must go to MANUAL_REVIEW
     */
    public function send(Secret $accessToken, SessionHandle $session, string $xml): SendReceipt
    {
        $invoiceHash = KsefCryptography::sha256Base64($xml);
        $encrypted = KsefCryptography::encryptInvoice($xml, $session->material);
        $encryptedHash = KsefCryptography::sha256Base64($encrypted);
        $environment = $this->transport->environment()->value;

        try {
            $reference = $this->transport->sendInvoice(
                $accessToken,
                $session->referenceNumber,
                $invoiceHash,
                strlen($xml),
                $encryptedHash,
                strlen($encrypted),
                base64_encode($encrypted),
            );
            $receipt = new SendReceipt($session->referenceNumber, $reference, $invoiceHash, strlen($xml), $encryptedHash, strlen($encrypted), $this->now());
            $this->audit->record(KsefAuditActions::INVOICE_SUBMITTED, [
                'environment' => $environment,
                'session_reference' => $session->referenceNumber,
                'invoice_reference' => $reference,
                'invoice_hash' => $invoiceHash,
                'invoice_size' => strlen($xml),
            ]);

            return $receipt;
        } catch (KsefException $e) {
            if ($e->outcomeKnown) {
                throw $e;
            }
            // The request may have reached KSeF. Ask before doing anything else.
            $recovered = $this->recover($accessToken, $session->referenceNumber, $invoiceHash);
            if ($recovered !== null) {
                $receipt = new SendReceipt($session->referenceNumber, $recovered, $invoiceHash, strlen($xml), $encryptedHash, strlen($encrypted), $this->now(), recovered: true);
                $this->audit->record(KsefAuditActions::INVOICE_SUBMISSION_RECOVERED, [
                    'environment' => $environment,
                    'session_reference' => $session->referenceNumber,
                    'invoice_reference' => $recovered,
                    'invoice_hash' => $invoiceHash,
                    'after' => $e->category->value,
                ]);

                return $receipt;
            }

            throw new KsefException(
                $e->category,
                'sendInvoice',
                $e->getMessage().' Lista faktur sesji nie zawiera tego dokumentu (skrót '.$invoiceHash.'), ale KSeF mógł go jeszcze nie zarejestrować. '
                .'System NIE wysyła ponownie automatycznie — wymagany przegląd ręczny.',
                $e->httpStatus,
                $e->ksefCode,
                $e->details,
                $e->retryAfterSeconds,
                $session->referenceNumber,
                outcomeKnown: false,
                environment: $environment,
                previous: $e,
            );
        }
    }

    /** Looks for an invoice by hash in the session; null when it is not there. */
    public function recover(Secret $accessToken, string $sessionReference, string $invoiceHashBase64): ?string
    {
        $continuation = null;
        $pages = 0;
        do {
            $page = $this->transport->sessionInvoices($accessToken, $sessionReference, $continuation, 50);
            foreach ($page->invoices as $invoice) {
                if (hash_equals($invoice->invoiceHash, $invoiceHashBase64)) {
                    return $invoice->referenceNumber;
                }
            }
            $continuation = $page->continuationToken;
        } while ($continuation !== null && ++$pages < 200);

        return null;
    }

    public function status(Secret $accessToken, string $sessionReference, string $invoiceReference): SubmissionStatus
    {
        $raw = $this->transport->sessionInvoiceStatus($accessToken, $sessionReference, $invoiceReference);
        $status = SubmissionStatus::fromKsef($raw);
        $this->audit->record(KsefAuditActions::INVOICE_STATUS_CHECKED, [
            'environment' => $this->transport->environment()->value,
            'session_reference' => $sessionReference,
        ] + $status->toArray());

        return $status;
    }

    /** Polls until the status is final or the attempts run out; returns the last status either way. */
    public function waitForOutcome(Secret $accessToken, string $sessionReference, string $invoiceReference, int $maxPolls = 12, int $delaySeconds = 5): SubmissionStatus
    {
        $status = null;
        for ($attempt = 1; $attempt <= $maxPolls; $attempt++) {
            $status = $this->status($accessToken, $sessionReference, $invoiceReference);
            if ($status->isFinal()) {
                return $status;
            }
            if ($attempt < $maxPolls) {
                ($this->sleeper ?? static fn (int $s) => sleep($s))($delaySeconds);
            }
        }

        return $status;
    }

    public function upo(Secret $accessToken, string $sessionReference, string $invoiceReference): UpoDocument
    {
        $xml = $this->transport->sessionInvoiceUpo($accessToken, $sessionReference, $invoiceReference);
        $upo = (new UpoParser())->parse($xml, $this->now());
        $this->audit->record(KsefAuditActions::UPO_RETRIEVED, [
            'environment' => $this->transport->environment()->value,
            'session_reference' => $sessionReference,
            'invoice_reference' => $invoiceReference,
            'upo_hash' => $upo->xmlHash,
            'schema_valid' => $upo->schemaValid,
            'documents' => count($upo->documents),
        ], $upo->schemaValid ? 'ok' : 'partial', $upo->schemaValid ? null : implode('; ', $upo->schemaErrors));

        return $upo;
    }

    public function close(Secret $accessToken, string $sessionReference): void
    {
        try {
            $this->transport->closeOnlineSession($accessToken, $sessionReference);
            $this->audit->record(KsefAuditActions::SESSION_CLOSED, ['environment' => $this->transport->environment()->value, 'session_reference' => $sessionReference]);
        } catch (KsefException $e) {
            // Closing is housekeeping; KSeF closes idle sessions itself after 12 h.
            $this->audit->record(KsefAuditActions::SESSION_CLOSED, ['environment' => $this->transport->environment()->value, 'session_reference' => $sessionReference] + $e->toArray(), 'failed', $e->getMessage());
        }
    }

    private function now(): DateTimeImmutable
    {
        return $this->now ?? new DateTimeImmutable();
    }
}
