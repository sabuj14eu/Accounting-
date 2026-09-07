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
use Poland\Laravel\Support\SettlementRecorder;
use Poland\Rates\MissingRateException;

/**
 * The taxpayer-facing screen: enter the month's cash-register total, see what
 * is owed.
 */
final class DashboardController
{
    public function __construct(private readonly SettlementRecorder $recorder) {}

    public function show(Request $request): View
    {
        $profile = $this->profileFor($request);
        $period = $this->periodFrom($request);

        $report = null;
        $error = null;

        if ($profile !== null) {
            try {
                $report = $this->recorder->settle($profile, $period);
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
            'report' => $report,
            'error' => $error,
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
