<?php

declare(strict_types=1);

namespace Poland\Laravel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Laravel\Support\PlatformSettlementService;
use Poland\Platforms\Platform;
use Poland\Platforms\PlatformSettlement;
use Poland\Platforms\VatTreatment;

/** The Glovo month, typed once: orders, commission, deductions, payout, VAT treatment. */
final class PlatformController
{
    public function __construct(private readonly PlatformSettlementService $settlements)
    {
    }

    public function index(Request $request): View
    {
        $profile = $this->profile($request);

        return view('poland::platforms', [
            'profile' => $profile,
            'settlements' => $profile === null ? collect() : $this->settlements->inForceFor($profile),
            'platforms' => Platform::cases(),
            'treatments' => VatTreatment::cases(),
            'defaultPeriod' => Period::of((int) date('Y'), (int) date('n'))->previous()->toString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $profile = $this->profile($request);
        if ($profile === null) {
            return redirect()->route('poland.platforms')->withErrors(['profile' => 'Najpierw skonfiguruj profil podatnika.']);
        }

        $validated = $request->validate([
            'platform' => ['required', Rule::in(array_map(static fn (Platform $p): string => $p->value, Platform::cases()))],
            'period' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'gross_orders' => ['required', 'string', 'max:32'],
            'gross_23' => ['nullable', 'string', 'max:32'],
            'gross_08' => ['nullable', 'string', 'max:32'],
            'gross_05' => ['nullable', 'string', 'max:32'],
            'gross_zw' => ['nullable', 'string', 'max:32'],
            'commission_net' => ['required', 'string', 'max:32'],
            'commission_vat' => ['required', 'string', 'max:32'],
            'deduction_name' => ['nullable', 'string', 'max:80'],
            'deduction_amount' => ['nullable', 'string', 'max:32'],
            'payout_received' => ['nullable', 'string', 'max:32'],
            'vat_treatment' => ['required', Rule::in(array_map(static fn (VatTreatment $t): string => $t->value, VatTreatment::cases()))],
            'statement_from' => ['nullable', 'date'],
            'statement_to' => ['nullable', 'date'],
            'correction_reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $byRate = [];
            foreach (['gross_23' => '0.23', 'gross_08' => '0.08', 'gross_05' => '0.05', 'gross_zw' => 'zw'] as $field => $designation) {
                if (isset($validated[$field]) && trim((string) $validated[$field]) !== '') {
                    $byRate[$designation] = Money::parse($validated[$field]);
                }
            }

            $deductions = [];
            if (isset($validated['deduction_amount']) && trim((string) $validated['deduction_amount']) !== '') {
                $name = trim((string) ($validated['deduction_name'] ?? ''));
                if ($name === '') {
                    throw new \RuntimeException('Każde potrącenie musi mieć nazwę (np. marketing, zwroty).');
                }
                $deductions[$name] = Money::parse($validated['deduction_amount']);
            }

            $settlement = new PlatformSettlement(
                Platform::from($validated['platform']),
                Period::parse($validated['period']),
                Money::parse($validated['gross_orders']),
                $byRate === [] ? null : $byRate,
                Money::parse($validated['commission_net']),
                Money::parse($validated['commission_vat']),
                $deductions,
                VatTreatment::from($validated['vat_treatment']),
                isset($validated['payout_received']) && trim((string) $validated['payout_received']) !== ''
                    ? Money::parse($validated['payout_received'])
                    : null,
            );

            $row = $this->settlements->record(
                $profile,
                $settlement,
                $this->actor($request),
                $validated['correction_reason'] ?? null,
                null,
                null,
                isset($validated['statement_from']) ? new \DateTimeImmutable($validated['statement_from']) : null,
                isset($validated['statement_to']) ? new \DateTimeImmutable($validated['statement_to']) : null,
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['platform' => $e->getMessage()])->withInput();
        }

        $message = sprintf(
            'Zapisano %s za %s (wersja %d): oczekiwana wypłata %s, status %s.',
            Platform::from($validated['platform'])->label(),
            $validated['period'],
            $row->version,
            $settlement->expectedPayout()->format(),
            $settlement->status()->label(),
        );
        if (! $settlement->canProduceSalesLines()) {
            $message .= ' UWAGA: bez podziału na stawki sprzedaż z platformy NIE została dopisana do raportu miesięcznego.';
        }

        return redirect()->route('poland.platforms')->with('status', $message);
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
