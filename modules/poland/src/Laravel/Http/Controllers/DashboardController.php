<?php

declare(strict_types=1);

namespace Poland\Laravel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Laravel\Models\SalesReportModel;
use Poland\Laravel\Models\SettlementModel;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Laravel\Support\MonthlyReportService;
use Poland\Laravel\Support\SettlementRecorder;
use Poland\Rates\MissingRateException;
use Poland\Rates\UnverifiedRateException;

/**
 * The taxpayer-facing screen: enter the month's cash-register total, see what
 * is owed.
 */
final class DashboardController
{
    public function __construct(
        private readonly SettlementRecorder $recorder,
        private readonly MonthlyReportService $reports,
    ) {}

    public function show(Request $request): View
    {
        $profile = $this->profileFor($request);
        $period = $this->periodFrom($request);

        $accountantReport = null;
        $error = null;

        if ($profile !== null) {
            try {
                $accountantReport = $this->reports->preview($profile, $period);
            } catch (UnverifiedRateException $e) {
                // Production requires verified rates. Show why rather than a 500.
                $error = $e->getMessage();
            } catch (MissingRateException $e) {
                $error = $e->getMessage();
            } catch (\InvalidArgumentException $e) {
                // Most often: this month has no cash-register report yet, which
                // is the normal state of the screen before anything is entered.
                $error = $e->getMessage();
            }
        }

        return view('poland::dashboard', [
            'profile' => $profile,
            'period' => $period,
            'accountantReport' => $accountantReport,
            'report' => $accountantReport?->report,
            'error' => $error,
            'closed' => $profile !== null && $this->reports->isClosed($profile, $period),
            'integrations' => $this->integrationPanel($profile),
            'history' => $profile === null ? collect() : SettlementModel::query()
                ->where('tax_profile_id', $profile->getKey())
                ->orderByDesc('period')
                ->limit(12)
                ->get(),
            'recorded' => $profile === null ? collect() : SalesReportModel::query()
                ->where('tax_profile_id', $profile->getKey())
                ->inForce()
                ->orderByDesc('period')
                ->limit(12)
                ->get(),
        ]);
    }

    public function storeSales(Request $request): RedirectResponse
    {
        $profile = $this->profileFor($request);
        if ($profile === null) {
            return redirect()->route('poland.dashboard')
                ->withErrors(['profile' => 'Najpierw skonfiguruj profil podatnika.']);
        }

        $validated = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'gross' => ['required', 'string', 'max:32'],
            'designation' => ['nullable', 'string', 'max:10'],
            'register_id' => ['nullable', 'string', 'max:64'],
            'report_number' => ['nullable', 'string', 'max:64'],
            'correction_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $domainProfile = $profile->toDomain();
        $designation = $validated['designation']
            ?? ($domainProfile->vatStatus->settlesVat() ? '0.23' : 'zw');

        try {
            $this->recorder->recordSales(
                $profile,
                Period::parse($validated['period']),
                [$designation => Money::parse($validated['gross'])],
                $validated['register_id'] ?? null,
                $validated['report_number'] ?? null,
                $validated['correction_reason'] ?? null,
            );
        } catch (\Throwable $e) {
            return redirect()
                ->route('poland.dashboard', ['period' => $validated['period']])
                ->withErrors(['gross' => $e->getMessage()])
                ->withInput();
        }

        return redirect()
            ->route('poland.dashboard', ['period' => $validated['period']])
            ->with('status', 'Zapisano sprzedaż za '.$validated['period'].'.');
    }

    /** Record the month's deductible costs and input VAT. */
    public function storeCosts(Request $request): RedirectResponse
    {
        $profile = $this->profileFor($request);
        if ($profile === null) {
            return redirect()->route('poland.dashboard')
                ->withErrors(['profile' => 'Najpierw skonfiguruj profil podatnika.']);
        }

        $validated = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'costs_net' => ['required', 'string', 'max:32'],
            'input_vat' => ['nullable', 'string', 'max:32'],
            'document_count' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->recorder->recordPurchases(
                $profile,
                Period::parse($validated['period']),
                Money::parse($validated['costs_net']),
                Money::parse($validated['input_vat'] ?? '0'),
                (int) ($validated['document_count'] ?? 0),
                $validated['note'] ?? null,
            );
        } catch (\Throwable $e) {
            return redirect()
                ->route('poland.dashboard', ['period' => $validated['period']])
                ->withErrors(['costs_net' => $e->getMessage()])
                ->withInput();
        }

        return redirect()
            ->route('poland.dashboard', ['period' => $validated['period']])
            ->with('status', 'Zapisano koszty za '.$validated['period'].'.');
    }

    /**
     * What each external integration is actually doing.
     *
     * Shown on the dashboard so an unavailable production integration is never
     * presented as working, and so an empty result can never be read as a
     * statement about the taxpayer's records.
     *
     * @return list<\Poland\Certainty\IntegrationStatus>
     */
    private function integrationPanel(?TaxProfileModel $profile): array
    {
        $ksefReason = $profile !== null
            ? app(\Poland\Laravel\Support\KsefIngestService::class)->unavailableReason($profile)
            : null;

        $unverified = [];
        try {
            $rates = app(\Poland\Rates\RateRepository::class);
            foreach (\Poland\Rates\RateRepository::TABLES as $table) {
                $versions = $rates->table($table)->versions();
                foreach ($versions as $version) {
                    if (! $version->isFitForFiling()) {
                        $unverified[$table] = true;
                        break;
                    }
                }
            }
        } catch (\Throwable) {
            // A rate table that will not load is itself reported by the engine.
        }

        return \Poland\Certainty\IntegrationStatus::panel(
            (bool) config('poland.ksef.transport_enabled', false),
            (string) config('poland.ksef.transport', 'disabled'),
            app(\Poland\Government\Contracts\TextExtractor::class)->isAvailable(),
            $unverified === [],
            implode(', ', array_keys($unverified)),
            $ksefReason,
        );
    }

    private function profileFor(Request $request): ?TaxProfileModel
    {
        $query = TaxProfileModel::query();

        if ($request->filled('profile')) {
            return $query->find((int) $request->query('profile'));
        }

        return $query->orderBy('id')->first();
    }

    private function periodFrom(Request $request): Period
    {
        $value = (string) $request->query('period', '');

        if (preg_match('/^\d{4}-\d{1,2}$/', $value) === 1) {
            return Period::parse($value);
        }

        // Default to the month just ended: that is the one being settled now.
        return Period::of((int) date('Y'), (int) date('n'))->previous();
    }
}
