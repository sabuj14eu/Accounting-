<?php

declare(strict_types=1);

namespace Poland\Laravel\Support\Ksef;

use Illuminate\Support\Facades\DB;
use Poland\Ksef\Audit\KsefAuditActions;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\Fa3\ErpInvoiceMapper;
use Poland\Ksef\Fa3\Fa3Schema;
use Poland\Ksef\Fa3\Fa3SchemaValidator;
use Poland\Ksef\Fa3\Fa3SemanticChecks;
use Poland\Ksef\Fa3\Fa3XmlGenerator;
use Poland\Ksef\Fa3\MappingRefused;
use Poland\Ksef\Outgoing\KsefInvoiceSender;
use Poland\Ksef\Outgoing\KsefSubmissionState as State;
use Poland\Ksef\Outgoing\SubmissionIdentity;
use Poland\Ksef\Outgoing\SubmissionStatus;
use Poland\Laravel\Models\KsefCredentialModel;
use Poland\Laravel\Models\KsefInvoiceDocumentModel;
use Poland\Laravel\Models\KsefStatusEventModel;
use Poland\Laravel\Models\KsefSubmissionModel;
use Poland\Laravel\Models\TaxProfileModel;
use RuntimeException;

/**
 * Accounting invoice → FA(3) → validate → (explicit) send → status → UPO.
 *
 * Every transition goes through {@see transition()}, which records the
 * from/to pair, the KSeF code, the source and the actor as an append-only
 * event. Sending is an explicit action guarded by a row lock and the
 * READY→SUBMITTED transition, so two clicks cannot send twice; the UNIQUE
 * `active_key` guards against a second live submission of the same invoice
 * even across processes.
 */
final class KsefSubmissionService
{
    public const GENERATOR_VERSION = 'fa3-generator/1.0';

    public function __construct(
        private readonly KsefTransportFactory $transports,
        private readonly KsefSettingsService $settings,
        private readonly KsefTokenService $tokens,
        private readonly ErpInvoiceSnapshotBuilder $erp,
        private readonly LaravelKsefAuditSink $audit,
        private readonly KsefErrorRecorder $errors,
    ) {
    }

    // --- prepare -------------------------------------------------------------

    public function prepare(TaxProfileModel $profile, int $erpInvoiceId, string $actor): KsefSubmissionModel
    {
        $credential = $this->settings->current($profile);
        if ($credential === null || ! $credential->isConfigured()) {
            throw new RuntimeException('KSeF nie jest skonfigurowany dla tego podatnika — skonfiguruj przed przygotowaniem faktury.');
        }
        $invoice = $this->erp->erpInvoice($erpInvoiceId);
        if ($invoice === null) {
            throw new RuntimeException($this->erp->erpAvailable() ? 'Nie znaleziono faktury księgowej #'.$erpInvoiceId.'.' : 'Moduł faktur ERP nie jest dostępny w tej instalacji.');
        }

        $environment = $this->transports->environment()->value;
        $number = (string) $invoice->invoice_number;
        $activeKey = SubmissionIdentity::activeKey($environment, (string) $credential->nip, 'VAT', $number);

        $existing = KsefSubmissionModel::query()->where('active_key', $activeKey)->first();
        if ($existing !== null) {
            return $existing; // idempotent: the live submission for this invoice
        }

        $now = now()->toDateTimeImmutable();
        $snapshot = $this->erp->build($profile, $credential, $invoice);
        $audit = $this->audit->forProfile((int) $profile->getKey());

        $submission = KsefSubmissionModel::create([
            'tax_profile_id' => $profile->getKey(),
            'credential_id' => $credential->getKey(),
            'environment' => $environment,
            'erp_invoice_id' => $erpInvoiceId,
            'erp_sales_order_id' => $invoice->sales_order_id ?? null,
            'invoice_number' => $number,
            'invoice_type' => 'VAT',
            'seller_nip' => (string) $credential->nip,
            'buyer_label' => $snapshot->buyer['name'] ?? null,
            'issue_date' => $snapshot->issueDate !== '' ? $snapshot->issueDate : null,
            'currency' => $snapshot->currency,
            'active_key' => $activeKey,
            'state' => State::Draft->value,
            'prepared_by' => $actor,
        ]);
        $this->event($submission, null, State::Draft, 'user', null, null, ['erp_invoice_id' => $erpInvoiceId], $actor);

        try {
            $fa3 = (new ErpInvoiceMapper())->map($snapshot);
        } catch (MappingRefused $e) {
            return $this->block($submission, $e->reasons, 'validator', $actor);
        }

        $problems = Fa3SemanticChecks::check($fa3, $now);
        if ($problems !== []) {
            return $this->block($submission, $problems, 'validator', $actor);
        }

        $totals = $fa3->totals();
        $xml = (new Fa3XmlGenerator((string) ($credential->system_info ?: config('poland.ksef.system_info', 'SignalMesh Accounts'))))->generate($fa3, $now);
        $validation = Fa3SchemaValidator::forFa3()->validate($xml, $now);

        $document = KsefInvoiceDocumentModel::create([
            'tax_profile_id' => $profile->getKey(),
            'kind' => KsefInvoiceDocumentModel::KIND_FA3,
            'xml' => $xml,
            'xml_hash' => hash('sha256', $xml),
            'xml_hash_base64' => base64_encode(hash('sha256', $xml, true)),
            'xml_size' => strlen($xml),
            'schema_system_code' => Fa3Schema::SYSTEM_CODE,
            'schema_version' => Fa3Schema::SCHEMA_VERSION,
            'generated_at' => $now,
            'validation_status' => $validation->valid ? 'VALID' : 'INVALID',
            'validated_at' => $validation->validatedAt,
            'validation_errors' => $validation->errors,
            'generator_version' => self::GENERATOR_VERSION,
        ]);
        $audit->record(KsefAuditActions::XML_GENERATED, [
            'environment' => $environment,
            'submission_id' => $submission->getKey(),
            'document_id' => $document->getKey(),
            'invoice_number' => $number,
            'xml_hash' => $document->xml_hash,
            'xml_size' => $document->xml_size,
            'schema' => Fa3Schema::identifier(),
        ]);

        $submission->forceFill([
            'document_id' => $document->getKey(),
            'net' => $totals->net->jsonSerialize(),
            'vat' => $totals->vat->jsonSerialize(),
            'gross' => $totals->gross->jsonSerialize(),
        ])->save();

        if (! $validation->valid) {
            $audit->record(KsefAuditActions::XML_VALIDATION_FAILED, ['submission_id' => $submission->getKey(), 'errors' => $validation->errors, 'stage' => $validation->stage], 'failed', 'XML VALIDATION FAILED');

            return $this->block($submission, array_merge(['XML VALIDATION FAILED ('.$validation->stage.')'], $validation->errors), 'validator', $actor);
        }
        $audit->record(KsefAuditActions::XML_VALIDATION_PASSED, ['submission_id' => $submission->getKey(), 'schema' => Fa3Schema::identifier(), 'xml_hash' => $document->xml_hash]);

        return $this->transition($submission, State::Validated, 'validator', null, 'FA(3) zgodna z '.Fa3Schema::identifier(), [], $actor);
    }

    public function markReady(KsefSubmissionModel $submission, string $actor): KsefSubmissionModel
    {
        return $this->transition($submission, State::Ready, 'user', null, 'Zatwierdzona do wysyłki przez '.$actor, [], $actor);
    }

    public function cancel(KsefSubmissionModel $submission, string $actor, string $reason): KsefSubmissionModel
    {
        $submission = $this->transition($submission, State::Cancelled, 'user', null, $reason, [], $actor);
        $submission->forceFill(['active_key' => null])->save();

        return $submission;
    }

    // --- send ----------------------------------------------------------------

    public function send(KsefSubmissionModel $submission, string $actor): KsefSubmissionModel
    {
        $gate = $this->transports->gate();
        if (! $gate->isEnabled()) {
            throw new RuntimeException('Transport KSeF jest wyłączony ('.$gate->mode().') — nic nie wysłano.');
        }
        $credential = $this->settings->current($submission->profile);
        if ($credential === null || ! $credential->enabled) {
            throw new RuntimeException('Integracja KSeF nie jest włączona dla tego podatnika (wymagany udany test połączenia i włączenie).');
        }
        if ($submission->environment !== $gate->environment()->value) {
            throw new RuntimeException(sprintf('Zgłoszenie przygotowano dla środowiska %s, a bieżące to %s — nie wysłano.', $submission->environment, $gate->environment()->value));
        }

        // Claim the submission: READY → SUBMITTED under a row lock. A second
        // concurrent send sees SUBMITTED and is refused by the state machine.
        $submission = DB::transaction(function () use ($submission, $actor): KsefSubmissionModel {
            $locked = KsefSubmissionModel::query()->lockForUpdate()->findOrFail($submission->getKey());
            $locked->stateEnum()->assertTransition(State::Submitted);
            if ($locked->document === null || ! $locked->document->isValid()) {
                throw new RuntimeException('Brak zwalidowanego dokumentu FA(3) — nie można wysłać.');
            }
            $locked->forceFill(['attempt_count' => (int) $locked->attempt_count + 1, 'sent_by' => $actor, 'submitted_at' => now()])->save();

            return $this->transition($locked, State::Submitted, 'user', null, 'Wysyłka rozpoczęta przez '.$actor, ['attempt' => $locked->attempt_count], $actor);
        });

        $sender = $this->sender($submission->profile);
        try {
            $context = $this->tokens->context($credential);
            $session = $sender->openSession($context->bearer());
            $submission->forceFill(['session_reference' => $session->referenceNumber])->save();

            $receipt = $sender->send($context->bearer(), $session, (string) $submission->document->xml);
            $submission->forceFill(['invoice_reference' => $receipt->invoiceReference])->save();

            $submission = $this->transition($submission, State::Processing, 'api', 100, $receipt->recovered ? 'Numer referencyjny odzyskany z listy faktur sesji po niepewnej wysyłce' : 'Faktura przyjęta do przetwarzania', ['invoice_reference' => $receipt->invoiceReference, 'recovered' => $receipt->recovered], $actor);

            $status = $sender->waitForOutcome(
                $context->bearer(),
                $session->referenceNumber,
                $receipt->invoiceReference,
                (int) config('poland.ksef.submission.poll_attempts', 6),
                (int) config('poland.ksef.submission.poll_delay_seconds', 5),
            );

            return $this->apply($submission, $status, $credential, $actor);
        } catch (KsefException $e) {
            $error = $this->errors->record($e, (int) $submission->tax_profile_id, (int) $submission->getKey());
            $submission->forceFill(['last_error_id' => $error->getKey()])->save();

            if ($e->outcomeKnown && $submission->invoice_reference === null) {
                // Nothing reached KSeF: back to READY so a person may retry.
                return $this->transition($submission, State::Ready, 'system', $e->ksefCode, $e->getMessage(), $e->toArray(), $actor);
            }

            $submission->forceFill(['manual_review_reason' => $e->getMessage()])->save();
            $this->audit->forProfile((int) $submission->tax_profile_id)->record(KsefAuditActions::MANUAL_REVIEW_REQUIRED, ['submission_id' => $submission->getKey()] + $e->toArray(), 'failed', $e->getMessage());

            return $this->transition($submission, State::ManualReview, 'system', $e->ksefCode, $e->getMessage(), $e->toArray(), $actor);
        }
    }

    // --- status / UPO --------------------------------------------------------

    public function poll(KsefSubmissionModel $submission, string $actor = 'system'): KsefSubmissionModel
    {
        if ($submission->session_reference === null || $submission->invoice_reference === null) {
            throw new RuntimeException('To zgłoszenie nie ma numerów referencyjnych KSeF — nie było wysłane.');
        }
        $credential = $this->settings->current($submission->profile);
        if ($credential === null) {
            throw new RuntimeException('Brak konfiguracji KSeF.');
        }
        $sender = $this->sender($submission->profile);
        try {
            $context = $this->tokens->context($credential);
            $status = $sender->status($context->bearer(), (string) $submission->session_reference, (string) $submission->invoice_reference);
        } catch (KsefException $e) {
            $error = $this->errors->record($e, (int) $submission->tax_profile_id, (int) $submission->getKey());
            $submission->forceFill(['last_error_id' => $error->getKey()])->save();

            throw $e;
        }

        return $this->apply($submission, $status, $credential, $actor);
    }

    /** @return int how many were polled */
    public function pollPending(TaxProfileModel $profile): int
    {
        $count = 0;
        $pending = KsefSubmissionModel::query()->where('tax_profile_id', $profile->getKey())->pending()->orderBy('id')->get();
        foreach ($pending as $submission) {
            try {
                $this->poll($submission);
            } catch (KsefException) {
                // recorded on the submission; the next scheduled poll retries
            }
            $count++;
        }

        return $count;
    }

    public function fetchUpo(KsefSubmissionModel $submission, string $actor = 'system'): KsefSubmissionModel
    {
        if ($submission->stateEnum() !== State::Accepted) {
            throw new RuntimeException('UPO istnieje tylko dla faktur przyjętych przez KSeF.');
        }
        if ($submission->upo_document_id !== null) {
            return $submission;
        }
        $credential = $this->settings->current($submission->profile);
        if ($credential === null) {
            throw new RuntimeException('Brak konfiguracji KSeF.');
        }
        $sender = $this->sender($submission->profile);
        $context = $this->tokens->context($credential);
        $upo = $sender->upo($context->bearer(), (string) $submission->session_reference, (string) $submission->invoice_reference);

        $document = KsefInvoiceDocumentModel::create([
            'tax_profile_id' => $submission->tax_profile_id,
            'kind' => KsefInvoiceDocumentModel::KIND_UPO,
            'xml' => $upo->xml,
            'xml_hash' => $upo->xmlHash,
            'xml_hash_base64' => base64_encode(hash('sha256', $upo->xml, true)),
            'xml_size' => strlen($upo->xml),
            'schema_system_code' => 'UPO',
            'schema_version' => 'v4-3',
            'generated_at' => $upo->retrievedAt,
            'validation_status' => $upo->schemaValid ? 'VALID' : 'INVALID',
            'validated_at' => $upo->retrievedAt,
            'validation_errors' => $upo->schemaErrors,
        ]);
        $submission->forceFill(['upo_document_id' => $document->getKey()])->save();
        $this->event($submission, $submission->stateEnum(), $submission->stateEnum(), 'api', null, 'UPO pobrane', ['upo_document_id' => $document->getKey(), 'schema_valid' => $upo->schemaValid], $actor);

        $sender->close($context->bearer(), (string) $submission->session_reference);

        return $submission->refresh();
    }

    /** MANUAL_REVIEW → a decision: 'recheck' | 'resend' | 'cancel' | 'accept_original'. */
    public function resolveReview(KsefSubmissionModel $submission, string $action, string $actor, ?string $note = null): KsefSubmissionModel
    {
        $submission->stateEnum() === State::ManualReview || throw new RuntimeException('Zgłoszenie nie jest w stanie PRZEGLĄD RĘCZNY.');

        return match ($action) {
            'recheck' => $submission->invoice_reference !== null
                ? $this->poll($submission, $actor)
                : throw new RuntimeException('Brak numeru referencyjnego — nie ma czego sprawdzić w KSeF; wybierz „wyślij ponownie” lub „anuluj”.'),
            'resend' => $this->transition($submission->forceFill(['session_reference' => null, 'invoice_reference' => null, 'manual_review_reason' => null]), State::Ready, 'user', null, 'Decyzja operatora: ponowna wysyłka. '.(string) $note, ['note' => $note], $actor),
            'cancel' => $this->cancel($submission, $actor, 'Decyzja operatora: anulowanie. '.(string) $note),
            'accept_original' => $this->acceptOriginal($submission, $actor, $note),
            default => throw new RuntimeException('Nieznana decyzja: '.$action),
        };
    }

    // --- internals -----------------------------------------------------------

    private function acceptOriginal(KsefSubmissionModel $submission, string $actor, ?string $note): KsefSubmissionModel
    {
        $original = (string) ($submission->ksef_status_details['extensions']['originalKsefNumber'] ?? '');
        if ($original === '' || ! \Poland\Ksef\KsefNumber::isValid($original)) {
            throw new RuntimeException('KSeF nie podał poprawnego numeru pierwotnej faktury — nie można przypisać.');
        }
        $submission->forceFill(['ksef_number' => $original, 'accepted_at' => now(), 'manual_review_reason' => null])->save();

        return $this->transition($submission, State::Accepted, 'user', 440, 'Operator potwierdził, że duplikat wskazuje tę samą fakturę; przypisano numer KSeF pierwotnej wysyłki. '.(string) $note, ['ksef_number' => $original], $actor);
    }

    private function apply(KsefSubmissionModel $submission, SubmissionStatus $status, KsefCredentialModel $credential, string $actor): KsefSubmissionModel
    {
        $current = $submission->stateEnum();
        $details = $status->toArray();
        $submission->forceFill([
            'ksef_status_code' => $status->code,
            'ksef_status_description' => $status->description,
            'ksef_status_details' => $details,
        ])->save();

        if ($status->state === State::Processing) {
            if ($current === State::Processing) {
                return $submission->refresh(); // no change, no noise
            }

            return $this->transition($submission, State::Processing, 'api', $status->code, $status->description, $details, $actor);
        }

        if ($status->state === State::Accepted) {
            $submission->forceFill([
                'ksef_number' => $status->ksefNumber,
                'accepted_at' => now(),
                'acquisition_date' => $status->acquisitionDate,
                'permanent_storage_date' => $status->permanentStorageDate,
                'manual_review_reason' => null,
            ])->save();
            $submission = $this->transition($submission, State::Accepted, 'api', $status->code, $status->description, $details, $actor);
            $this->audit->forProfile((int) $submission->tax_profile_id)->record(KsefAuditActions::INVOICE_ACCEPTED, ['submission_id' => $submission->getKey(), 'ksef_number' => $status->ksefNumber, 'invoice_number' => $submission->invoice_number]);
            try {
                $submission = $this->fetchUpo($submission, $actor);
            } catch (\Throwable $e) {
                // The acceptance stands; the UPO is fetched by the next poll.
                $this->audit->forProfile((int) $submission->tax_profile_id)->record(KsefAuditActions::UPO_RETRIEVED, ['submission_id' => $submission->getKey(), 'error' => $e->getMessage()], 'failed', $e->getMessage());
            }

            return $submission;
        }

        if ($status->state === State::Rejected) {
            $reason = $status->description.($status->details !== [] ? ' — '.implode('; ', $status->details) : '');
            $submission->forceFill(['rejected_at' => now(), 'rejection_reason' => $reason, 'active_key' => null])->save();
            $submission = $this->transition($submission, State::Rejected, 'api', $status->code, $reason, $details, $actor);
            $this->audit->forProfile((int) $submission->tax_profile_id)->record(KsefAuditActions::INVOICE_REJECTED, ['submission_id' => $submission->getKey(), 'ksef_code' => $status->code, 'reason' => $reason], 'failed', $reason);

            return $submission;
        }

        // MANUAL_REVIEW
        $submission->forceFill(['manual_review_reason' => $status->manualReviewReason])->save();
        if ($current === State::ManualReview) {
            return $submission->refresh();
        }
        $this->audit->forProfile((int) $submission->tax_profile_id)->record(KsefAuditActions::MANUAL_REVIEW_REQUIRED, ['submission_id' => $submission->getKey()] + $details, 'failed', $status->manualReviewReason);

        return $this->transition($submission, State::ManualReview, 'api', $status->code, (string) $status->manualReviewReason, $details, $actor);
    }

    /** @param list<string> $reasons */
    private function block(KsefSubmissionModel $submission, array $reasons, string $source, string $actor): KsefSubmissionModel
    {
        $submission->forceFill(['blocked_reason' => implode("\n", $reasons)])->save();

        return $this->transition($submission, State::Blocked, $source, null, 'BLOCKED: '.implode(' | ', $reasons), ['reasons' => $reasons], $actor);
    }

    /** @param array<string,mixed> $details */
    private function transition(KsefSubmissionModel $submission, State $to, string $source, ?int $code, ?string $description, array $details, string $actor): KsefSubmissionModel
    {
        $from = $submission->stateEnum();
        if ($from !== $to) {
            $from->assertTransition($to);
        }
        $submission->forceFill(['state' => $to->value])->save();
        $this->event($submission, $from, $to, $source, $code, $description, $details, $actor);

        return $submission->refresh();
    }

    /** @param array<string,mixed> $details */
    private function event(KsefSubmissionModel $submission, ?State $from, State $to, string $source, ?int $code, ?string $description, array $details, string $actor): void
    {
        KsefStatusEventModel::create([
            'submission_id' => $submission->getKey(),
            'from_state' => $from?->value,
            'to_state' => $to->value,
            'ksef_code' => $code,
            'ksef_description' => $description,
            'details' => $details,
            'source' => $source,
            'reference' => $submission->invoice_reference ?? $submission->session_reference,
            'actor' => $actor,
            'occurred_at' => now(),
        ]);
    }

    private function sender(TaxProfileModel $profile): KsefInvoiceSender
    {
        return new KsefInvoiceSender($this->transports->transport(), $this->audit->forProfile((int) $profile->getKey()));
    }
}
