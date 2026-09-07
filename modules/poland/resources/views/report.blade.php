@php
    use Poland\Reporting\ObligationKind;
    use Poland\Reporting\PaymentStatus;
    $r = $accountantReport;
    $t = $r->report;
@endphp
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $r->title() }}</title>
    <style>
        /* Print is a first-class target: this sheet goes to an accountant. */
        @page { size: A4; margin: 14mm 12mm; }
        :root {
            color-scheme: light dark;
            --bg:#f5f6f8; --card:#fff; --ink:#14171c; --muted:#5b6472; --line:#dfe3e9;
            --pay:#b91c1c; --pay-bg:#fef2f2; --ok:#15803d; --ok-bg:#f0fdf4;
            --warn:#92400e; --warn-bg:#fffbeb; --alarm:#7f1d1d; --alarm-bg:#fee2e2;
        }
        @media (prefers-color-scheme: dark) {
            :root:not([data-print]) {
                --bg:#0f1216; --card:#171b21; --ink:#e9ebef; --muted:#9aa4b2; --line:#262b33;
                --pay:#fca5a5; --pay-bg:#2b1616; --ok:#86efac; --ok-bg:#132318;
                --warn:#fcd34d; --warn-bg:#2a2113; --alarm:#fecaca; --alarm-bg:#3b1414;
            }
        }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--ink);
               font:14px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif; }
        .sheet { max-width: 940px; margin:0 auto; padding:20px 16px 60px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:10px;
                padding:18px 20px; margin-bottom:16px; }
        h1 { font-size:1.45rem; margin:0 0 2px; }
        h2 { font-size:1rem; margin:0 0 12px; text-transform:uppercase;
             letter-spacing:.06em; color:var(--muted); }
        .sub { color:var(--muted); margin:0 0 18px; }
        .alarm { background:var(--alarm-bg); border:2px solid var(--alarm); color:var(--alarm);
                 border-radius:8px; padding:12px 14px; margin-bottom:16px;
                 font-weight:700; text-align:center; letter-spacing:.02em; }
        .warn { background:var(--warn-bg); border:1px solid var(--warn); color:var(--warn);
                border-radius:8px; padding:11px 14px; margin-bottom:10px; font-size:.9rem; }
        .headline { display:flex; justify-content:space-between; align-items:baseline;
                    gap:16px; padding:14px 0; border-bottom:1px solid var(--line); }
        .headline:last-child { border-bottom:0; }
        .headline .what { font-weight:600; }
        .headline .meta { color:var(--muted); font-size:.85rem; font-weight:400; }
        .amount { font-size:1.3rem; font-weight:700; font-variant-numeric:tabular-nums;
                  white-space:nowrap; }
        .amount.pay { color:var(--pay); }
        .amount.none { color:var(--ok); font-size:1rem; }
        .grand { display:flex; justify-content:space-between; align-items:baseline;
                 margin-top:14px; padding-top:14px; border-top:2px solid var(--ink); }
        .grand .amount { font-size:1.6rem; }
        table { width:100%; border-collapse:collapse; font-size:.9rem; }
        th, td { text-align:left; padding:8px 6px; border-bottom:1px solid var(--line);
                 vertical-align:top; }
        th { color:var(--muted); font-weight:600; font-size:.78rem;
             text-transform:uppercase; letter-spacing:.04em; }
        td.num, th.num { text-align:right; font-variant-numeric:tabular-nums; }
        .pill { display:inline-block; padding:2px 9px; border-radius:99px; font-size:.75rem;
                font-weight:700; letter-spacing:.03em; }
        .pill.unpaid { background:var(--pay-bg); color:var(--pay); }
        .pill.paid   { background:var(--ok-bg);  color:var(--ok); }
        .pill.none   { background:var(--ok-bg);  color:var(--ok); }
        .kv { display:grid; grid-template-columns:auto 1fr; gap:4px 18px; font-size:.9rem; }
        .kv dt { color:var(--muted); }
        .kv dd { margin:0; font-variant-numeric:tabular-nums; }
        .basis { color:var(--muted); font-size:.78rem; }
        details summary { cursor:pointer; color:var(--muted); font-size:.9rem; }
        .noprint { }
        @media print {
            body { background:#fff; }
            .sheet { max-width:none; padding:0; }
            .card { border:0; border-bottom:1px solid #ccc; border-radius:0;
                    padding:10px 0; margin:0; break-inside:avoid; }
            .noprint { display:none !important; }
            .alarm { border:2px solid #000; color:#000; background:#fff; }
            a { text-decoration:none; color:inherit; }
        }
    </style>
</head>
<body>
<div class="sheet">

    <h1>{{ $r->title() }}</h1>
    <p class="sub">
        {{ $t->profile->name }}@if($t->profile->nip) · NIP {{ $t->profile->nip }}@endif<br>
        {{ $t->profile->pitRegime->label() }}@if($t->profile->lumpSumRate) — {{ rtrim(rtrim(number_format($t->profile->lumpSumRate*100,2,',',''),'0'),',') }}%@endif
        · {{ $t->profile->vatStatus->label() }}
        · {{ $t->profile->zusScheme->label() }}
        @if($r->version) · wersja raportu {{ $r->version }}@endif
        @if($r->isClosed) · <strong>MIESIĄC ZAMKNIĘTY</strong>@endif
    </p>

    @if ($banner = $r->verificationBanner())
        <div class="alarm">{{ $banner }}</div>
    @endif

    {{-- 1. SUMMARY — the one question this page exists to answer. --}}
    <div class="card">
        <h2>1 · Co muszę zapłacić za {{ $t->period->label() }}</h2>

        @foreach ($r->obligations as $o)
            <div class="headline">
                <div>
                    <div class="what">{{ $o->kind->label() }}</div>
                    <div class="meta">
                        {{ $o->payTo() }}@if($o->form) · {{ $o->form }}@endif
                        @if($o->dueDate)
                            · termin {{ $o->dueDate->format('d.m.Y') }}
                            @unless($o->dueDateVerified)(kalendarz świąt niesprawdzony)@endunless
                        @endif
                    </div>
                </div>
                <div class="amount {{ $o->status === PaymentStatus::Unpaid ? 'pay' : 'none' }}">
                    @if ($o->status === PaymentStatus::Unpaid)
                        {{ $o->amount->format() }}
                    @else
                        {{ $o->headline() }}
                    @endif
                </div>
            </div>
        @endforeach

        <div class="grand">
            <div class="what">
                @if ($r->everythingPaid())
                    Wszystko zapłacone
                @else
                    RAZEM DO ZAPŁATY
                @endif
            </div>
            <div class="amount {{ $r->totalOutstanding()->isPositive() ? 'pay' : 'none' }}">
                {{ $r->totalOutstanding()->format() }}
            </div>
        </div>
    </div>

    {{-- 2. ZUS --}}
    <div class="card">
        <h2>2 · ZUS</h2>
        <dl class="kv">
            <dt>Firma</dt><dd>{{ $t->profile->name }}</dd>
            <dt>NIP</dt><dd>{{ $t->profile->nip ?? '—' }}</dd>
            <dt>Miesiąc</dt><dd>{{ $t->period->label() }}</dd>
            <dt>Składki społeczne</dt><dd>{{ $t->zus->socialTotal->format() }}</dd>
            @foreach ($t->zus->socialComponents as $name => $amount)
                <dt style="padding-left:14px">· {{ $name }}</dt><dd>{{ $amount->format() }}</dd>
            @endforeach
            @unless (array_key_exists('Fundusz Pracy i FS', $t->zus->socialComponents))
                <dt style="padding-left:14px">· Fundusz Pracy i FS</dt>
                <dd>nie występuje (podstawa poniżej minimalnego wynagrodzenia)</dd>
            @endunless
            <dt>Składka zdrowotna</dt>
            <dd>{{ $t->zus->health->format() }} <span class="basis">— {{ $t->zus->healthBasis }}</span></dd>
            <dt><strong>Razem ZUS</strong></dt><dd><strong>{{ $t->zus->total->format() }}</strong></dd>
            <dt>Termin</dt>
            <dd>{{ $t->deadlines['zus']['date']->format('d.m.Y') }}
                @if($t->deadlines['zus']['shifted'])
                    <span class="basis">(przesunięty z {{ $t->deadlines['zus']['statutory']->format('d.m.Y') }})</span>
                @endif
            </dd>
        </dl>
    </div>

    {{-- 3. PIT --}}
    @php $pit = $r->obligation(ObligationKind::Pit); @endphp
    <div class="card">
        <h2>3 · Podatek dochodowy (PIT)</h2>
        <dl class="kv">
            <dt>Forma</dt><dd>{{ $pit->form }}</dd>
            <dt>Okres</dt><dd>{{ $t->period->label() }}</dd>
            <dt>Zapłata do</dt><dd>{{ $pit->payTo() }}</dd>
            <dt>Kwota</dt>
            <dd>
                @if ($pit->amount->isZero())
                    <strong style="color:var(--ok)">Nic do zapłaty</strong>
                @else
                    <strong>{{ $pit->amount->format() }}</strong>
                @endif
            </dd>
            <dt>Podatek narastająco</dt><dd>{{ $t->pit->taxYearToDate->format() }}</dd>
            <dt>Zapłacone wcześniej</dt><dd>{{ $t->pit->advancesAlreadyDue->format() }}</dd>
            <dt>Termin</dt><dd>{{ $pit->dueDate?->format('d.m.Y') ?? '—' }}</dd>
        </dl>
        @if ($t->pit->isEstimate)
            <div class="warn" style="margin-top:12px">
                Kwota jest GÓRNĄ GRANICĄ, nie zaliczką do zapłaty — brak ewidencji kosztów.
            </div>
        @endif
    </div>

    {{-- 4. VAT — a surplus is never rendered as a payment. --}}
    @php $vat = $r->obligation(ObligationKind::Vat); @endphp
    <div class="card">
        <h2>4 · VAT</h2>
        @if (! $t->vat->settlesVat)
            <p style="margin:0">
                <strong style="color:var(--ok)">Nic do zapłaty</strong> —
                {{ $t->profile->vatStatus->label() }}.
            </p>
        @else
            <dl class="kv">
                <dt>Forma</dt><dd>{{ $vat->form }}</dd>
                <dt>Okres</dt><dd>{{ $t->period->label() }}</dd>
                <dt>Zapłata do</dt><dd>{{ $vat->payTo() }}</dd>
                <dt>VAT należny (sprzedaż)</dt><dd>{{ $t->vat->outputVat->format() }}</dd>
                <dt>VAT naliczony (zakupy)</dt><dd>{{ $t->vat->inputVat->format() }}</dd>
                <dt>Wynik</dt>
                <dd>
                    @if ($vat->hasSurplus())
                        <strong style="color:var(--ok)">NADWYŻKA {{ $vat->surplus->format() }} — nic do zapłaty</strong><br>
                        <span class="basis">do przeniesienia na następny okres lub do zwrotu</span>
                    @elseif ($vat->amount->isZero())
                        <strong style="color:var(--ok)">Nic do zapłaty</strong>
                    @else
                        <strong>{{ $vat->amount->format() }} do zapłaty</strong>
                    @endif
                </dd>
                <dt>Termin</dt><dd>{{ $vat->dueDate?->format('d.m.Y') ?? '—' }}</dd>
            </dl>
        @endif
    </div>

    {{-- 5. FINANCIAL RESULT --}}
    <div class="card">
        <h2>5 · Wynik finansowy</h2>
        <table>
            <thead>
                <tr><th></th><th class="num">{{ $t->period->label() }}</th><th class="num">Narastająco od 1 stycznia</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>Przychód</td>
                    <td class="num">{{ $r->financials->revenueMonth->format() }}</td>
                    <td class="num">{{ $r->financials->revenueYearToDate->format() }}</td>
                </tr>
                <tr>
                    <td>Koszty</td>
                    <td class="num">{{ $r->financials->costsMonth->format() }}</td>
                    <td class="num">{{ $r->financials->costsYearToDate->format() }}</td>
                </tr>
                <tr>
                    <td><strong>{{ $r->financials->profitIsKnown() ? 'Dochód' : 'Dochód (górna granica)' }}</strong></td>
                    <td class="num"><strong>{{ $r->financials->incomeMonth()->format() }}</strong></td>
                    <td class="num"><strong>{{ $r->financials->incomeYearToDate()->format() }}</strong></td>
                </tr>
            </tbody>
        </table>
        @if ($caveat = $r->financials->caveat())
            <div class="warn" style="margin-top:12px">{{ $caveat }}</div>
        @endif
    </div>

    {{-- 6. PAYMENT CHECKLIST --}}
    <div class="card">
        <h2>6 · Lista płatności</h2>
        <table>
            <thead>
                <tr>
                    <th>Płatność</th><th class="num">Kwota</th><th>Termin</th>
                    <th>Status</th><th>Zapłacono</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($r->obligations as $o)
                <tr>
                    <td>
                        {{ $o->kind->label() }}
                        <div class="basis">{{ $o->payTo() }}</div>
                    </td>
                    <td class="num">
                        @if ($o->hasSurplus())
                            <span style="color:var(--ok)">nadwyżka {{ $o->surplus->format() }}</span>
                        @else
                            {{ $o->amount->format() }}
                        @endif
                    </td>
                    <td>{{ $o->dueDate?->format('d.m.Y') ?? '—' }}</td>
                    <td>
                        <span class="pill {{ $o->status === PaymentStatus::Unpaid ? 'unpaid' : ($o->status === PaymentStatus::Paid ? 'paid' : 'none') }}">
                            {{ $o->status->label() }}
                        </span>
                    </td>
                    <td>
                        @if ($o->status === PaymentStatus::Paid)
                            {{ $o->amountPaid?->format() ?? '—' }}
                            <div class="basis">
                                {{ $o->paidAt?->format('d.m.Y') }}
                                @if($o->paymentReference) · {{ $o->paymentReference }}@endif
                            </div>
                            @if (($short = $o->shortfall()) && ! $short->isZero())
                                <div class="basis" style="color:var(--pay)">
                                    {{ $short->isPositive() ? 'niedopłata' : 'nadpłata' }}
                                    {{ $short->isPositive() ? $short->format() : $short->format() }}
                                </div>
                            @endif
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    {{-- 7. PROVENANCE --}}
    <div class="card">
        <h2>7 · Podstawa wyliczenia i pochodzenie stawek</h2>
        <table>
            <thead><tr><th>Tabela</th><th>Wersja</th><th>Obowiązuje</th><th>Weryfikacja</th></tr></thead>
            <tbody>
            @foreach ($t->rateProvenance as $table => $entry)
                <tr>
                    <td>{{ $table }}
                        <div class="basis">{{ $entry['provenance']->sourceDocument }}</div>
                    </td>
                    <td>{{ $entry['version'] }}</td>
                    <td>{{ $entry['effective_from'] }} → {{ $entry['effective_to'] ?? '…' }}</td>
                    <td>
                        {{ $entry['provenance']->status->label() }}
                        @unless ($entry['provenance']->status->fitForFiling())
                            <div class="basis">do potwierdzenia: {{ $entry['provenance']->officialSourceUrl }}</div>
                        @endunless
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <details style="margin-top:14px">
            <summary>Pełne wyliczenie krok po kroku</summary>
            @foreach ([$t->zus->breakdown, $t->vat->breakdown, $t->pit->breakdown] as $b)
                <h3 style="font-size:.9rem;margin:14px 0 6px">{{ $b->title }}</h3>
                <table>
                    <tbody>
                    @foreach ($b->lines() as $line)
                        <tr>
                            <td @if($line->emphasis) style="font-weight:650" @endif>
                                {{ $line->label }}
                                @if ($line->formula || $line->legalBasis)
                                    <div class="basis">{{ $line->formula }}@if($line->formula && $line->legalBasis) · @endif{{ $line->legalBasis }}</div>
                                @endif
                            </td>
                            <td class="num" @if($line->emphasis) style="font-weight:650" @endif>{{ $line->value() }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endforeach
        </details>
    </div>

    {{-- 8. WARNINGS --}}
    <div class="card">
        <h2>8 · Ostrzeżenia i braki danych</h2>
        @forelse ($t->warnings as $w)
            <div class="warn">{{ $w }}</div>
        @empty
            <p style="margin:0;color:var(--muted)">Brak ostrzeżeń.</p>
        @endforelse
        @foreach ($t->notes as $n)
            <p class="basis" style="margin:6px 0">{{ $n }}</p>
        @endforeach

        <div class="warn" style="margin-top:14px">
            <strong>{{ \Poland\Reporting\MonthlyTaxReport::DISCLAIMER }}</strong>
            @if (! $t->fitForFiling())
                <ul style="margin:8px 0 0;padding-left:18px">
                    @foreach ($t->blockersToFiling() as $b)<li>{{ $b }}</li>@endforeach
                </ul>
            @endif
        </div>
    </div>

    <p class="basis noprint" style="text-align:center">
        <a href="{{ route('poland.dashboard', ['period' => $t->period->toString()]) }}">← Pulpit</a>
        · <a href="#" onclick="window.print();return false;">Drukuj / zapisz jako PDF</a>
    </p>

</div>
</body>
</html>
