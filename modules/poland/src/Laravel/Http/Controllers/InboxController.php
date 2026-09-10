<?php

declare(strict_types=1);

namespace Poland\Laravel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Poland\Domain\Period;
use Poland\Laravel\Models\KsefDocumentModel;
use Poland\Laravel\Models\ProductModel;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Laravel\Support\InvoicePostingService;
use Poland\Laravel\Support\ProductCatalogService;
use Poland\Laravel\Support\SettlementRecorder;
use Poland\Purchases\ApprovalStatus;
use Poland\Purchases\CostCategory;

/**
 * The review inbox: what arrived, what approving each invoice will do, and the
 * one tap that does it. Nothing here posts without the gate's consent.
 */
final class InboxController
{
    public function __construct(
        private readonly InvoicePostingService $postings,
        private readonly ProductCatalogService $catalog,
        private readonly SettlementRecorder $recorder,
    ) {
    }

    public function index(Request $request): View
    {
        $profile = $this->profile($request);

        $awaiting = $profile === null ? collect() : KsefDocumentModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->incoming()
            ->awaitingReview()
            ->orderByDesc('invoice_date')
            ->get();

        $recent = $profile === null ? collect() : KsefDocumentModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->incoming()
            ->whereIn('approval_status', [ApprovalStatus::Posted->value, ApprovalStatus::Rejected->value])
            ->orderByDesc('approved_at')
            ->limit(30)
            ->get();

        return view('poland::inbox', [
            'profile' => $profile,
            'awaiting' => $awaiting,
            'recent' => $recent,
            'conflicts' => $profile === null ? [] : app(\Poland\Laravel\Support\LedgerRepository::class)
                ->conflictsFor($profile, Period::of((int) date('Y'), (int) date('n'))),
        ]);
    }

    public function show(Request $request, int $document): View
    {
        $profile = $this->profile($request);
        $model = $this->document($profile, $document);

        $vatPeriod = null;
        if (preg_match('/^\d{4}-\d{2}$/', (string) $request->query('vat_period', '')) === 1) {
            $vatPeriod = Period::parse((string) $request->query('vat_period'));
        }
        $category = CostCategory::tryFrom((string) $request->query('cost_category', ''));
        $share = $request->filled('deductible_share') ? max(0, min(100, (int) $request->query('deductible_share'))) / 100 : 1.0;

        $error = null;
        $review = null;
        try {
            $review = $this->postings->review($model, $vatPeriod, $category, $share);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            try {
                $review = $this->postings->review($model);
            } catch (\Throwable $inner) {
                $error .= ' '.$inner->getMessage();
            }
        }

        return view('poland::invoice', [
            'profile' => $profile,
            'document' => $model,
            'review' => $review,
            'error' => $error,
            'products' => $profile === null ? collect() : ProductModel::query()
                ->where('tax_profile_id', $profile->getKey())->where('active', true)->orderBy('name')->get(),
            'categories' => CostCategory::cases(),
            'chosenVatPeriod' => $vatPeriod?->toString() ?? $review['plan']->vatPeriod?->toString() ?? '',
            'chosenCategory' => $category?->value ?? $review['plan']->costCategory->value ?? CostCategory::OtherExpenses->value,
            'chosenShare' => (int) round($share * 100),
        ]);
    }

    public function approve(Request $request, int $document): RedirectResponse
    {
        $profile = $this->profile($request);
        $model = $this->document($profile, $document);

        $validated = $request->validate([
            'vat_period' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'cost_category' => ['nullable', Rule::in(array_map(static fn (CostCategory $c): string => $c->value, CostCategory::cases()))],
            'deductible_share' => ['nullable', 'integer', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $posting = $this->postings->approve(
                $model,
                $this->actor($request),
                isset($validated['vat_period']) && $validated['vat_period'] !== null ? Period::parse($validated['vat_period']) : null,
                isset($validated['cost_category']) && $validated['cost_category'] !== null ? CostCategory::from($validated['cost_category']) : null,
                isset($validated['deductible_share']) && $validated['deductible_share'] !== null ? ((int) $validated['deductible_share']) / 100 : 1.0,
                $validated['note'] ?? null,
            );
        } catch (\Throwable $e) {
            return redirect()->route('poland.inbox.show', ['document' => $model->getKey()])
                ->withErrors(['approve' => $e->getMessage()])->withInput();
        }

        return redirect()->route('poland.inbox')
            ->with('status', sprintf(
                'Zaksięgowano %s (%s): %s',
                $model->invoice_number ?? $model->ksef_number,
                $model->seller_name ?? $model->seller_nip,
                $posting->plan['description'] ?? '',
            ));
    }

    public function reject(Request $request, int $document): RedirectResponse
    {
        $profile = $this->profile($request);
        $model = $this->document($profile, $document);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $this->postings->reject($model, $validated['reason'], $this->actor($request));
        } catch (\Throwable $e) {
            return back()->withErrors(['reject' => $e->getMessage()]);
        }

        return redirect()->route('poland.inbox')
            ->with('status', sprintf('Odrzucono %s. XML zachowany; faktura nie wchodzi do rejestrów.', $model->invoice_number ?? $model->ksef_number));
    }

    /** "This line IS this product, and one invoice unit holds N stock units." */
    public function mapLine(Request $request, int $document, int $line): RedirectResponse
    {
        $profile = $this->profile($request);
        $model = $this->document($profile, $document);

        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'pack_size' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $product = ProductModel::query()
            ->where('tax_profile_id', $model->tax_profile_id)
            ->findOrFail((int) $validated['product_id']);

        try {
            $this->catalog->mapLine(
                $model,
                $line,
                $product,
                isset($validated['pack_size']) && $validated['pack_size'] !== null ? (float) $validated['pack_size'] : null,
                $this->actor($request),
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['map' => $e->getMessage()]);
        }

        return redirect()->route('poland.inbox.show', ['document' => $model->getKey()])
            ->with('status', sprintf('Pozycja %d → %s. Zapamiętano dla tego dostawcy.', $line, $product->name));
    }

    /** A person decides whether a flagged twin is a real second invoice. */
    public function resolveDuplicate(Request $request, int $document): RedirectResponse
    {
        $profile = $this->profile($request);
        $model = $this->document($profile, $document);

        $validated = $request->validate(['decision' => ['required', Rule::in(['distinct', 'duplicate'])]]);

        if ($validated['decision'] === 'duplicate') {
            try {
                $this->postings->reject($model, 'Duplikat dokumentu KSeF '.$model->possible_duplicate_of.' — rozstrzygnięte przez '.$this->actor($request), $this->actor($request));
            } catch (\Throwable $e) {
                return back()->withErrors(['duplicate' => $e->getMessage()]);
            }
            $model->forceFill(['duplicate_decision' => 'confirmed_duplicate'])->save();

            return redirect()->route('poland.inbox')->with('status', 'Oznaczono jako duplikat i odrzucono. Oryginał pozostaje.');
        }

        $model->forceFill(['duplicate_decision' => 'confirmed_distinct'])->save();

        return redirect()->route('poland.inbox.show', ['document' => $model->getKey()])
            ->with('status', 'Potwierdzono: to osobna faktura. Można ją teraz zatwierdzić.');
    }

    /** Resolve a register conflict in favour of the posted invoices. */
    public function supersedeSummary(Request $request, string $period): RedirectResponse
    {
        $profile = $this->profile($request);
        if ($profile === null) {
            return redirect()->route('poland.inbox')->withErrors(['profile' => 'Najpierw skonfiguruj profil podatnika.']);
        }

        $this->recorder->supersedePurchaseSummary($profile, Period::parse($period), $this->actor($request));

        return redirect()->route('poland.inbox')
            ->with('status', sprintf('Ręczna suma zakupów za %s oznaczona jako zastąpiona przez zaksięgowane faktury.', $period));
    }

    private function document(?TaxProfileModel $profile, int $id): KsefDocumentModel
    {
        abort_if($profile === null, 404);

        return KsefDocumentModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->findOrFail($id);
    }

    private function profile(Request $request): ?TaxProfileModel
    {
        $query = TaxProfileModel::query();
        if ($request->filled('profile')) {
            return $query->find((int) $request->query('profile'));
        }

        return $query->orderBy('id')->first();
    }

    private function actor(Request $request): string
    {
        return (string) ($request->user()?->email ?? $request->user()?->name ?? 'system');
    }
}
