@php
    use Poland\Domain\Enums\PitRegime;
@endphp
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rozliczenie miesięczne — {{ $period->label() }}</title>
    <style>
        :root { color-scheme: light dark; --bg:#f6f7f9; --card:#fff; --ink:#15181d; --muted:#5d6672;
                --line:#e2e5ea; --warn:#b45309; --warn-bg:#fff7ed; --bad:#b91c1c; --bad-bg:#fef2f2;
                --ok:#15803d; }
        @media (prefers-color-scheme: dark) {
            :root { --bg:#101318; --card:#181c22; --ink:#e8eaee; --muted:#9aa4b2; --line:#272c34;
                    --warn:#fbbf24; --warn-bg:#2a2113; --bad:#f87171; --bad-bg:#2a1616; --ok:#4ade80; }
        }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--ink); font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif; }
        .wrap { max-width: 960px; margin: 0 auto; padding: 24px 16px 64px; }
        h1 { font-size: 1.5rem; margin: 0 0 4px; }
        .sub { color: var(--muted); margin: 0 0 24px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 10px;
                padding: 20px; margin-bottom: 20px; }
        .grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
        .tile { border: 1px solid var(--line); border-radius: 8px; padding: 14px; }
        .tile .k { color: var(--muted); font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; }
        .tile .v { font-size: 1.45rem; font-weight: 650; margin-top: 4px; font-variant-numeric: tabular-nums; }
        .tile .d { color: var(--muted); font-size: .8rem; margin-top: 4px; }
        .tile.total { border-color: var(--ink); }
        .note, .warn { border-radius: 8px; padding: 12px 14px; margin-bottom: 10px; font-size: .9rem; }
        .warn { background: var(--warn-bg); border: 1px solid var(--warn); color: var(--warn); }
        .note { background: var(--card); border: 1px solid var(--line); color: var(--muted); }
        .bad { background: var(--bad-bg); border: 1px solid var(--bad); color: var(--bad); }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        th, td { text-align: left; padding: 7px 6px; border-bottom: 1px solid var(--line); }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
        form { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
        label { display: block; font-size: .8rem; color: var(--muted); margin-bottom: 4px; }
        input, select { padding: 8px 10px; border: 1px solid var(--line); border-radius: 6px;
                        background: var(--card); color: var(--ink); font: inherit; }
        button { padding: 9px 16px; border: 0; border-radius: 6px; background: var(--ink);
                 color: var(--bg); font: inherit; font-weight: 600; cursor: pointer; }
        details { margin-top: 8px; }
        summary { cursor: pointer; color: var(--muted); font-size: .9rem; }
        .basis { color: var(--muted); font-size: .8rem; }
        .disclaimer { border: 1px dashed var(--line); border-radius: 8px; padding: 14px;
                      color: var(--muted); font-size: .85rem; }
    </style>
</head>
<body>
<div class="wrap">

    <h1>Rozliczenie miesięczne — {{ $period->label() }}</h1>
    <p class="sub">{{ $profile?->name ?? 'Brak profilu podatnika' }}@if($profile?->nip) · NIP {{ $profile->nip }}@endif</p>

    @if (session('status'))
        <div class="note" style="color: var(--ok); border-color: var(--ok);">{{ session('status') }}</div>
    @endif

    @foreach ($errors->all() as $message)
        <div class="warn bad">{{ $message }}</div>
    @endforeach

    <div class="card">
        <form method="POST" action="{{ route('poland.sales.store') }}">
            @csrf
            <div>
                <label for="period">Miesiąc</label>
                <input type="month" id="period" name="period" value="{{ old('period', $period->toString()) }}" required>
            </div>
            <div>
                <label for="gross">Sprzedaż brutto z kasy fiskalnej</label>
                <input type="text" id="gross" name="gross" inputmode="decimal"
                       placeholder="48 500,00" value="{{ old('gross') }}" required>
            </div>
            <div>
                <label for="correction_reason">Przyczyna korekty (jeśli miesiąc już zapisany)</label>
                <input type="text" id="correction_reason" name="correction_reason"
                       value="{{ old('correction_reason') }}" placeholder="np. pominięty raport dobowy">
            </div>
            <button type="submit">Zapisz sprzedaż</button>
        </form>
    </div>

    @if ($error)
        <div class="warn">{{ $error }}</div>
    @endif

    @if ($report)
        @if ($report->isEstimate)
            <div class="warn">
                <strong>To jest szacunek, nie kwota ostateczna.</strong>
                Brakuje danych potrzebnych do dokładnego wyliczenia — szczegóły w ostrzeżeniach poniżej.
            </div>
        @endif

        @foreach ($report->warnings as $warning)
            <div class="warn">{{ $warning }}</div>
        @endforeach

        <div class="card">
            <div class="grid">
                <div class="tile">
                    <div class="k">ZUS</div>
                    <div class="v">{{ $report->zus->total->format() }}</div>
                    <div class="d">
                        społeczne {{ $report->zus->socialTotal->format() }} ·
                        zdrowotna {{ $report->zus->health->format() }}<br>
                        termin {{ $report->deadlines['zus']['date']->format('d.m.Y') }}
                    </div>
                </div>
                <div class="tile">
                    <div class="k">VAT</div>
                    <div class="v">{{ $report->vat->amountToPay->format() }}</div>
                    <div class="d">
                        @if ($report->vat->settlesVat)
                            należny {{ $report->vat->outputVat->format() }} ·
                            naliczony {{ $report->vat->inputVat->format() }}<br>
                            {{ $report->vat->jpkStructure }} do
                            {{ $report->deadlines['vat']['date']->format('d.m.Y') }}
                        @else
                            podatnik zwolniony
                        @endif
                    </div>
                </div>
                <div class="tile">
                    <div class="k">
                        {{ $report->profile->pitRegime === PitRegime::LumpSum ? 'Ryczałt (PIT)' : 'Zaliczka PIT' }}
                    </div>
                    <div class="v">{{ $report->pit->advanceDue->format() }}</div>
                    <div class="d">
                        narastająco {{ $report->pit->taxYearToDate->format() }}<br>
                        termin {{ $report->deadlines['pit_advance']['date']->format('d.m.Y') }}
                    </div>
                </div>
                <div class="tile total">
                    <div class="k">Razem do zapłaty</div>
                    <div class="v">{{ $report->totalDue->format() }}</div>
                    <div class="d">
                        ze sprzedaży {{ $report->grossSales->format() }} zostaje
                        {{ $report->netAfterCharges()->format() }}
                    </div>
                </div>
            </div>
        </div>

        @foreach ($report->notes as $note)
            <div class="note">{{ $note }}</div>
        @endforeach

        @foreach ([$report->zus->breakdown, $report->vat->breakdown, $report->pit->breakdown] as $breakdown)
            <div class="card">
                <details>
                    <summary>{{ $breakdown->title }} — pokaż wyliczenie</summary>
                    <table>
                        <tbody>
                        @foreach ($breakdown->lines() as $line)
                            <tr>
                                <td @if($line->emphasis) style="font-weight:650" @endif>
                                    {{ $line->label }}
                                    @if ($line->formula || $line->legalBasis)
                                        <div class="basis">
                                            {{ $line->formula }}@if($line->formula && $line->legalBasis) · @endif{{ $line->legalBasis }}
                                        </div>
                                    @endif
                                </td>
                                <td class="num" @if($line->emphasis) style="font-weight:650" @endif>
                                    {{ $line->value() }}
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </details>
            </div>
        @endforeach
    @endif

    @if ($recorded->isNotEmpty())
        <div class="card">
            <h2 style="font-size:1rem;margin:0 0 10px">Zapisana sprzedaż</h2>
            <table>
                <thead><tr><th>Miesiąc</th><th class="num">Brutto</th><th class="num">Netto</th><th class="num">VAT</th></tr></thead>
                <tbody>
                @foreach ($recorded as $row)
                    <tr>
                        <td><a href="{{ route('poland.dashboard', ['period' => $row->period]) }}">{{ $row->period }}</a></td>
                        <td class="num">{{ number_format((float) $row->gross_total, 2, ',', ' ') }}</td>
                        <td class="num">{{ number_format((float) $row->net_total, 2, ',', ' ') }}</td>
                        <td class="num">{{ number_format((float) $row->vat_total, 2, ',', ' ') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($history->isNotEmpty())
        <div class="card">
            <h2 style="font-size:1rem;margin:0 0 10px">Historia rozliczeń</h2>
            <table>
                <thead><tr><th>Miesiąc</th><th class="num">ZUS</th><th class="num">VAT</th><th class="num">PIT</th><th class="num">Razem</th><th>Status</th></tr></thead>
                <tbody>
                @foreach ($history as $row)
                    <tr>
                        <td>{{ $row->period }}</td>
                        <td class="num">{{ number_format((float) $row->zus_total, 2, ',', ' ') }}</td>
                        <td class="num">{{ number_format((float) $row->vat_due, 2, ',', ' ') }}</td>
                        <td class="num">{{ number_format((float) $row->pit_due, 2, ',', ' ') }}</td>
                        <td class="num">{{ number_format((float) $row->total_due, 2, ',', ' ') }}</td>
                        <td>{{ $row->statusLabel() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="disclaimer">
        {{ \Poland\Reporting\MonthlyTaxReport::DISCLAIMER }}
        @if ($report)
            <br><br><strong>Źródła stawek:</strong>
            <ul style="margin:6px 0 0;padding-left:18px">
                @foreach ($report->rateSources as $source)
                    <li>{{ $source }}</li>
                @endforeach
            </ul>
        @endif
    </div>

</div>
</body>
</html>
