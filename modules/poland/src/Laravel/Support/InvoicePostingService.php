<?php

declare(strict_types=1);

namespace Poland\Laravel\Support;

use Illuminate\Support\Facades\DB;
use Poland\Domain\Enums\VatSettlementFrequency;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\PurchaseRegister;
use Poland\Inventory\StockMovement;
use Poland\Ksef\Parsing\FaInvoiceParser;
use Poland\Ksef\Parsing\ParsedInvoice;
use Poland\Laravel\Models\InventoryMovementModel;
use Poland\Laravel\Models\KsefDocumentModel;
use Poland\Laravel\Models\ProductModel;
use Poland\Laravel\Models\PurchasePostingModel;
use Poland\Laravel\Models\SupplierModel;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Purchases\ApprovalAssessment;
use Poland\Purchases\ApprovalGate;
use Poland\Purchases\ApprovalStatus;
use Poland\Purchases\CostCategory;
use Poland\Purchases\PostingPlan;
use Poland\Rates\RateRepository;
use RuntimeException;

/**
 * Review, approve, post.
 *
 * Approval writes the posting and the stock movements in ONE transaction. A
 * failure anywhere rolls back everything, the invoice stays where it was, and
 * the failure is reported as FAILED — never as "no cost". The database's
 * unique keys (one posting per document, one movement per line) are what stop
 * a double tap or a retried job from booking twice; this class merely reads
 * their refusal and says so.
 */
final class InvoicePostingService
{
    public function __construct(
        private readonly FaInvoiceParser $parser,
        private readonly ProductCatalogService $catalog,
        private readonly RateRepository $rates,
        private readonly AuditRecorder $audit,
    ) {
    }

    /** The document as parsed from its immutable XML. Always re-derived, never cached. */
    public function parsed(KsefDocumentModel $document): ParsedInvoice
    {
        return $this->parser->parse(
            (string) $document->original_xml,
            (string) $document->ksef_number,
            \DateTimeImmutable::createFromInterface($document->retrieved_at),
        );
    }

    /** How many periods after receipt the VAT may still be deducted — from the rate table, by filing frequency. */
    public function allowedFollowingPeriods(TaxProfileModel $profile): int
    {
        $vat = $this->rates->vat();
        $quarterly = $profile->toDomain()->vatSettlement === VatSettlementFrequency::Quarterly;
        $key = $quarterly ? 'input_vat_deduction_following_periods_quarterly' : 'input_vat_deduction_following_periods';

        return (int) $vat->constant($key);
    }

    public function defaultCostCategory(KsefDocumentModel $document, array $resolutions): CostCategory
    {
        foreach ($resolutions as $resolution) {
            if ($resolution->isMapped()) {
                return $resolution->mapping->costCategory;
            }
        }

        $supplier = $this->supplierFor($document);
        if ($supplier?->last_cost_category !== null) {
            $category = CostCategory::tryFrom((string) $supplier->last_cost_category);
            if ($category !== null) {
                return $category;
            }
        }

        return CostCategory::OtherExpenses;
    }

    /**
     * @return array{parsed: ParsedInvoice, resolutions: list<\Poland\Purchases\LineResolution>, plan: PostingPlan, assessment: ApprovalAssessment}
     */
    public function review(
        KsefDocumentModel $document,
        ?Period $vatPeriod = null,
        ?CostCategory $costCategory = null,
        float $deductibleShare = 1.0,
    ): array {
        $profile = TaxProfileModel::query()->findOrFail($document->tax_profile_id);
        $parsed = $this->parsed($document);
        $resolutions = $this->catalog->resolveLines($document, $parsed);

        $plan = PostingPlan::build(
            $parsed,
            $resolutions,
            $document->receivedAt(),
            $this->allowedFollowingPeriods($profile),
            $costCategory ?? $this->defaultCostCategory($document, $resolutions),
            $deductibleShare,
            $vatPeriod,
        );

        $assessment = (new ApprovalGate())->assess(
            $parsed,
            $plan,
            correctionLinked: $document->corrects_document_id !== null,
            possibleDuplicate: $document->hasUndecidedDuplicate(),
            alreadyPosted: $document->approvalStatus() === ApprovalStatus::Posted,
            firstTimeSupplier: $this->supplierFor($document)?->isFirstTime() ?? true,
        );

        return ['parsed' => $parsed, 'resolutions' => $resolutions, 'plan' => $plan, 'assessment' => $assessment];
    }

    /**
     * Approve and post. One transaction: posting + movements + status + audit.
     *
     * A correction invoice posts as a SIGNED ADJUSTMENT linked to its original
     * (FA correction amounts are differences); the original stays posted and
     * unchanged, and its stock moves by the correction's own signed lines.
     */
    public function approve(
        KsefDocumentModel $document,
        string $actor,
        ?Period $vatPeriod = null,
        ?CostCategory $costCategory = null,
        float $deductibleShare = 1.0,
        ?string $note = null,
    ): PurchasePostingModel {
        $document->approvalStatus()->assertTransitionTo(ApprovalStatus::Approved);

        $review = $this->review($document, $vatPeriod, $costCategory, $deductibleShare);
        /** @var ApprovalAssessment $assessment */
        $assessment = $review['assessment'];
        /** @var PostingPlan $plan */
        $plan = $review['plan'];

        if (! $assessment->canApprove()) {
            throw new RuntimeException("Nie można zatwierdzić:\n  - ".implode("\n  - ", $assessment->blockers));
        }

        return DB::transaction(function () use ($document, $actor, $plan, $note, $review): PurchasePostingModel {
            $document->forceFill([
                'approval_status' => ApprovalStatus::Approved->value,
                'approved_by' => $actor,
                'approved_at' => now(),
                'decision_note' => $note,
            ])->save();

            $adjusts = null;
            if ($document->corrects_document_id !== null) {
                $adjusts = PurchasePostingModel::query()
                    ->where('ksef_document_id', $document->corrects_document_id)
                    ->first();
            }

            $byRate = [];
            foreach ($plan->byRate as $label => $amounts) {
                $byRate[$label] = ['net' => $amounts['net']->jsonSerialize(), 'vat' => $amounts['vat']->jsonSerialize()];
            }

            $posting = PurchasePostingModel::create([
                'tax_profile_id' => $document->tax_profile_id,
                'ksef_document_id' => $document->getKey(),
                'booking_period' => $plan->bookingPeriod?->toString(),
                'vat_period' => $plan->vatPeriod?->toString(),
                'receipt_period' => $plan->receiptPeriod->toString(),
                'deductible_net' => $plan->deductibleNet->jsonSerialize(),
                'deductible_input_vat' => $plan->deductibleInputVat->jsonSerialize(),
                'non_deductible_net' => $plan->nonDeductibleNet->jsonSerialize(),
                'non_deductible_vat' => $plan->nonDeductibleVat->jsonSerialize(),
                'deductible_share' => $plan->deductibleShare,
                'by_rate' => $byRate,
                'cost_category' => $plan->costCategory->value,
                'kpir_column' => $plan->costCategory->kpirColumn(),
                'status' => PurchasePostingModel::STATUS_POSTED,
                'adjusts_posting_id' => $adjusts?->getKey(),
                'plan' => json_decode(json_encode($plan, JSON_THROW_ON_ERROR), true),
                'posted_by' => $actor,
                'posted_at' => now(),
                'reason' => $note,
            ]);

            foreach ($plan->movements as $movement) {
                $this->recordMovement($document, $movement, $actor);
            }

            $document->approvalStatus()->assertTransitionTo(ApprovalStatus::Posted);
            $document->forceFill(['approval_status' => ApprovalStatus::Posted->value])->save();

            $supplier = $this->supplierFor($document);
            $supplier?->forceFill(['last_cost_category' => $plan->costCategory->value])->save();

            $this->audit->record(
                AuditRecorder::KSEF_INVOICE_APPROVED,
                (int) $document->tax_profile_id,
                $document,
                $plan->bookingPeriod?->toString(),
                ['approval_status' => ApprovalStatus::AwaitingReview->value],
                ['approval_status' => ApprovalStatus::Posted->value, 'by' => $actor, 'note' => $note, 'will_do' => $plan->describe()],
            );

            $this->audit->record(
                AuditRecorder::PURCHASE_POSTED,
                (int) $document->tax_profile_id,
                $posting,
                $plan->bookingPeriod?->toString(),
                null,
                [
                    'ksef_number' => $document->ksef_number,
                    'deductible_net' => $plan->deductibleNet->jsonSerialize(),
                    'deductible_input_vat' => $plan->deductibleInputVat->jsonSerialize(),
                    'vat_period' => $plan->vatPeriod?->toString(),
                    'cost_category' => $plan->costCategory->value,
                    'adjusts_posting_id' => $adjusts?->getKey(),
                    'movements' => count($plan->movements),
                    'by' => $actor,
                ],
            );

            $this->flagAffectedReport($document, $plan);

            return $posting;
        });
    }

    private function recordMovement(KsefDocumentModel $document, StockMovement $movement, string $actor): void
    {
        $product = ProductModel::query()
            ->where('tax_profile_id', $document->tax_profile_id)
            ->where('code', $movement->productCode)
            ->firstOrFail();

        $row = InventoryMovementModel::create([
            'tax_profile_id' => $document->tax_profile_id,
            'product_id' => $product->getKey(),
            'quantity_thousandths' => $movement->quantity->thousandths,
            'unit' => $movement->quantity->unit->value,
            'movement_type' => $movement->type->value,
            'source_type' => 'purchase_invoice_line',
            'source_id' => $document->getKey(),
            'source_line_no' => (int) ($movement->sourceLineNo ?? 0),
            'occurred_on' => ($document->sale_date ?? $document->invoice_date ?? now())->format('Y-m-d'),
            'recorded_by' => $actor,
            'recorded_at' => now(),
            'reason' => $movement->note,
        ]);

        $this->audit->record(
            AuditRecorder::INVENTORY_MOVEMENT_RECORDED,
            (int) $document->tax_profile_id,
            $row,
            $document->period,
            null,
            ['product' => $movement->productCode, 'quantity' => $movement->quantity->jsonSerialize(), 'ksef_number' => $document->ksef_number, 'line_no' => $movement->sourceLineNo],
        );
    }

    public function reject(KsefDocumentModel $document, string $reason, string $actor): KsefDocumentModel
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Odrzucenie faktury wymaga podania przyczyny.');
        }
        $document->approvalStatus()->assertTransitionTo(ApprovalStatus::Rejected);

        $document->forceFill([
            'approval_status' => ApprovalStatus::Rejected->value,
            'approved_by' => $actor,
            'approved_at' => now(),
            'decision_note' => $reason,
        ])->save();

        $this->audit->record(
            AuditRecorder::KSEF_INVOICE_REJECTED,
            (int) $document->tax_profile_id,
            $document,
            $document->period,
            ['approval_status' => ApprovalStatus::AwaitingReview->value],
            ['approval_status' => ApprovalStatus::Rejected->value, 'reason' => $reason, 'by' => $actor],
        );

        return $document;
    }

    public function awaitingReviewCount(TaxProfileModel $profile, ?Period $period = null): int
    {
        $query = KsefDocumentModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->awaitingReview();

        if ($period !== null) {
            $query->where('period', $period->toString());
        }

        return $query->count();
    }

    /**
     * The purchase register a month gets from POSTED documents only, or null
     * when none exist. Costs by booking period, input VAT by VAT period.
     */
    public function registerFromPostings(TaxProfileModel $profile, Period $period): ?PurchaseRegister
    {
        $costs = PurchasePostingModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->posted()
            ->where('booking_period', $period->toString())
            ->get();

        $vat = PurchasePostingModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->posted()
            ->where('vat_period', $period->toString())
            ->get();

        if ($costs->isEmpty() && $vat->isEmpty()) {
            return null;
        }

        $documents = $costs->pluck('ksef_document_id')->merge($vat->pluck('ksef_document_id'))->unique();

        return new PurchaseRegister(
            $period,
            Money::sum($costs->map(static fn (PurchasePostingModel $p): Money => Money::parse((string) $p->deductible_net))),
            Money::sum($vat->map(static fn (PurchasePostingModel $p): Money => Money::parse((string) $p->deductible_input_vat))),
            $documents->count(),
            sprintf('z %d zaksięgowanych faktur (KSeF)', $documents->count()),
        );
    }

    private function supplierFor(KsefDocumentModel $document): ?SupplierModel
    {
        $nip = preg_replace('/\D/', '', (string) $document->seller_nip) ?? '';
        if ($nip === '') {
            return null;
        }

        return SupplierModel::query()
            ->where('tax_profile_id', $document->tax_profile_id)
            ->where('nip', $nip)
            ->first();
    }

    /** A posting into a month whose report exists never rewrites it — it flags it. */
    private function flagAffectedReport(KsefDocumentModel $document, PostingPlan $plan): void
    {
        foreach (array_unique(array_filter([$plan->bookingPeriod?->toString(), $plan->vatPeriod?->toString()])) as $period) {
            $latest = \Poland\Laravel\Models\ReportVersionModel::query()
                ->where('tax_profile_id', $document->tax_profile_id)
                ->where('period', $period)
                ->orderByDesc('version')
                ->first();

            if ($latest === null) {
                continue;
            }

            $this->audit->record(
                AuditRecorder::REPORT_REQUIRES_REVIEW,
                (int) $document->tax_profile_id,
                $latest,
                $period,
                null,
                ['reason' => 'Zaksięgowano fakturę po wygenerowaniu raportu.', 'ksef_number' => $document->ksef_number, 'report_version' => $latest->version],
            );
        }
    }
}
