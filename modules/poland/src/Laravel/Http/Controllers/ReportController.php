<?php

declare(strict_types=1);

namespace Poland\Laravel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Laravel\Models\ReportVersionModel;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Laravel\Support\MonthlyReportService;
use Poland\Reporting\ObligationKind;

/**
 * The monthly accounting report: generate it, print it, record payments against
 * it, close the month.
 */
final class ReportController
{
    public function __construct(private readonly MonthlyReportService $reports) {}

    /** The printable report. Read-only; generating is a separate, deliberate act. */
    public function show(Request $request, string $period): View
    {
        $profile = $this->profile($request);
        $month = Period::parse($period);

        return view('poland::report', [
            'accountantReport' => $this->reports->preview($profile, $month),
            'profile' => $profile,
        ]);
    }

    /** Generate and store an immutable new version of the month's report. */
    public function generate(Request $request, string $period): RedirectResponse
    {
        $profile = $this->profile($request);
        $month = Period::parse($period);

        try {
            $report = $this->reports->generate(
                $profile,
                $month,
                (string) ($request->user()?->email ?? 'system'),
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['generate' => $e->getMessage()]);
        }

        return redirect()
            ->route('poland.report', ['period' => $month->toString()])
            ->with('status', sprintf('Wygenerowano raport za %s (wersja %d).', $month->label(), $report->version));
    }

    public function markPaid(Request $request, string $period): RedirectResponse
    {
        $profile = $this->profile($request);
        $month = Period::parse($period);

        $validated = $request->validate([
            'kind' => ['required', 'string', 'in:zus,pit,vat'],
            'amount_paid' => ['required', 'string', 'max:32'],
            'paid_at' => ['required', 'date'],
            'payment_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->reports->markPaid(
                $profile,
                $month,
                ObligationKind::from($validated['kind']),
                Money::parse($validated['amount_paid']),
                new \DateTimeImmutable($validated['paid_at']),
                $validated['payment_reference'] ?? null,
                $validated['notes'] ?? null,
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['payment' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Zapisano płatność.');
    }

    public function close(Request $request, string $period): RedirectResponse
    {
        $month = Period::parse($period);

        try {
            $this->reports->close($this->profile($request), $month);
        } catch (\Throwable $e) {
            return back()->withErrors(['close' => $e->getMessage()]);
        }

        return back()->with('status', sprintf('Miesiąc %s został zamknięty.', $month->label()));
    }

    public function reopen(Request $request, string $period): RedirectResponse
    {
        $month = Period::parse($period);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $this->reports->reopen($this->profile($request), $month, $validated['reason']);
        } catch (\Throwable $e) {
            return back()->withErrors(['reopen' => $e->getMessage()]);
        }

        return back()->with('status', sprintf('Miesiąc %s otwarty ponownie.', $month->label()));
    }

    /** Every version ever generated for a month — nothing is ever removed. */
    public function history(Request $request, string $period): View
    {
        $profile = $this->profile($request);

        return view('poland::history', [
            'profile' => $profile,
            'period' => Period::parse($period),
            'versions' => ReportVersionModel::query()
                ->where('tax_profile_id', $profile->getKey())
                ->where('period', $period)
                ->orderByDesc('version')
                ->get(),
        ]);
    }

    private function profile(Request $request): TaxProfileModel
    {
        if ($request->filled('profile')) {
            return TaxProfileModel::findOrFail((int) $request->query('profile'));
        }

        $profile = TaxProfileModel::query()->orderBy('id')->first();

        abort_if($profile === null, 404, 'Brak profilu podatnika. Utwórz go przed rozliczeniem.');

        return $profile;
    }
}
