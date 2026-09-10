@php
    use Poland\Reporting\ObligationKind;
    use Poland\Reporting\PaymentStatus;
    $r = $accountantReport ?? null;
@endphp
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Co muszę zapłacić — {{ $period->label() }}</title>
    <style>
        :root { color-scheme: light dark;
            --bg:#f5f6f8; --card:#fff; --ink:#14171c; --muted:#5b6472; --line:#dfe3e9;
            --pay:#b91c1c; --pay-bg:#fef2f2; --ok:#15803d; --ok-bg:#f0fdf4;
            --warn:#92400e; --warn-bg:#fffbeb; --alarm:#7f1d1d; --alarm-bg:#fee2e2; }
        @media (prefers-color-scheme: dark) { :root {
            --bg:#0f1216; --card:#171b21; --ink:#e9ebef; --muted:#9aa4b2; --line:#262b33;
            --pay:#fca5a5; --pay-bg:#2b1616; --ok:#86efac; --ok-bg:#132318;
            --warn:#fcd34d; --warn-bg:#2a2113; --alarm:#fecaca; --alarm-bg:#3b1414; } }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--ink);
               font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif; }
        .wrap { max-width:980px; margin:0 auto; padding:22px 16px 70px; }
        h1 { font-size:1.5rem; margin:0 0 2px; }
        .sub { color:var(--muted); margin:0 0 20px; font-size:.92rem; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:10px;
                padding:18px 20px; margin-bottom:16px; }
        h2 { font-size:.82rem; margin:0 0 14px; text-transform:uppercase;
             letter-spacing:.06em; color:var(--muted); }
        .alarm { background:var(--alarm-bg); border:2px solid var(--alarm); color:var(--alarm);
                 border-radius:8px; padding:12px 14px; margin-bottom:16px;
                 font-weight:700; text-align:center; }
        .warn { background:var(--warn-bg); border:1px solid var(--warn); color:var(--warn);
                border-radius:8px; padding:11px 14px; margin-bottom:10px; font-size:.9rem; }
        .good { background:var(--ok-bg); border:1px solid var(--ok); color:var(--ok);
                border-radius:8px; padding:11px 14px; margin-bottom:10px; font-size:.9rem; }
        .bad  { background:var(--pay-bg); border:1px solid var(--pay); color:var(--pay);
                border-radius:8px; padding:11px 14px; margin-bottom:10px; font-size:.9rem; }
        .row { display:flex; justify-content:space-between; align-items:baseline; gap:16px;
               padding:13px 0; border-bottom:1px solid var(--line); }
        .row:last-of-type { border-bottom:0; }
        .row .what { font-weight:600; }
        .row .meta { color:var(--muted); font-size:.84rem; font-weight:400; margin-top:2px; }
        .amt { font-size:1.28rem; font-weight:700; font-variant-numeric:tabular-nums;
               white-space:nowrap; }
        .amt.pay { color:var(--pay); } .amt.none { color:var(--ok); font-size:1rem; }
        .grand { display:flex; justify-content:space-between; align-items:baseline;
                 margin-top:14px; padding-top:14px; border-top:2px solid var(--ink); }
        .grand .amt { font-size:1.7rem; }
        table { width:100%; border-collapse:collapse; font-size:.9rem; }
        th,td { text-align:left; padding:8px 6px; border-bottom:1px solid var(--line); vertical-align:top; }
        th { color:var(--muted); font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; }
        td.num, th.num { text-align:right; font-variant-numeric:tabular-nums; }
        .pill { display:inline-block; padding:2px 9px; border-radius:99px; font-size:.74rem; font-weight:700; }
        .pill.unpaid { background:var(--pay-bg); color:var(--pay); }
        .pill.paid { background:var(--ok-bg); color:var(--ok); }
        .pill.none { background:var(--ok-bg); color:var(--ok); }
        form.inline { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
        label { display:block; font-size:.78rem; color:var(--muted); margin-bottom:4px; }
        input,select { padding:8px 10px; border:1px solid var(--line); border-radius:6px;
                       background:var(--card); color:var(--ink); font:inherit; }
        button { padding:9px 16px; border:0; border-radius:6px; background:var(--ink);
                 color:var(--bg); font:inherit; font-weight:600; cursor:pointer; }
        button.ghost { background:transparent; color:var(--ink); border:1px solid var(--line); }
        a { color:inherit; }
        .basis { color:var(--muted); font-size:.78rem; }
        .actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:4px; }
        details summary { cursor:pointer; color:var(--muted); font-size:.9rem; }
        .nav { display:flex; gap:6px; flex-wrap:wrap; margin:0 0 18px; }
        .nav a { text-decoration:none; padding:6px 12px; border:1px solid var(--line); border-radius:99px;
                 font-size:.86rem; background:var(--card); }
        .nav a.on { background:var(--ink); color:var(--bg); border-color:var(--ink); }
        .nav .count { display:inline-block; min-width:1.4em; padding:0 6px; margin-left:6px; border-radius:99px;
                      background:var(--pay); color:#fff; font-size:.74rem; text-align:center; }
    </style>
</head>
<body>
<div class="wrap">
@include('poland::partials.nav', ['active' => 'dashboard', 'profile' => $profile])

    <h1>Co muszę zapłacić — {{ $period->label() }}</h1>
    <p class="sub">
        {{ $profile?->name ?? 'Brak profilu podatnika' }}@if($profile?->nip) · NIP {{ $profile->nip }}@endif
        @if($closed ?? false) · <strong>MIESIĄC ZAMKNIĘTY</strong>@endif
    </p>

    @if (session('status'))<div class="good">{{ session('status') }}</div>@endif
    @foreach (($errors ?? collect())->all() ?? [] as $message)
        <div class="bad">{{ $message }}</div>
    @endforeach

    @if ($r && ($banner = $r->verificationBanner()))
        <div class="alarm">{{ $banner }}</div>
    @endif

    @if ($error)
        <div class="warn">{{ $error }}</div>
    @endif

    {{-- THE ANSWER, FIRST. Everything else on this page is supporting detail. --}}
    @if ($r)
        <div class="card">
            <h2>Do zapłaty za {{ $period->label() }}</h2>

            @foreach ($r->obligations as $o)
                <div class="row">
                    <div>
                        <div class="what">{{ $o->kind->label() }}</div>
                        <div class="meta">
                            {{ $o->payTo() }}@if($o->form) · {{ $o->form }}@endif
                            @if($o->dueDate) · termin {{ $o->dueDate->format('d.m.Y') }}@endif
                        </div>
                    </div>
                    <div class="amt {{ $o->status === PaymentStatus::Unpaid ? 'pay' : 'none' }}">
                        @if ($o->status === PaymentStatus::Unpaid)
                            {{ $o->amount->format() }}
                        @else
                            {{ $o->headline() }}
                        @endif
                    </div>
                </div>
            @endforeach

            <div class="grand">
                <div class="what">{{ $r->everythingPaid() ? 'Wszystko zapłacone' : 'RAZEM DO ZAPŁATY' }}</div>
                <div class="amt {{ $r->totalOutstanding()->isPositive() ? 'pay' : 'none' }}">
                    {{ $r->totalOutstanding()->format() }}
                </div>
            </div>

            <div class="actions" style="margin-top:16px">
                <a href="{{ route('poland.report', ['period' => $period->toString()]) }}">
                    <button type="button" class="ghost">Pełny raport księgowy / druk / PDF</button>
                </a>
                @unless ($closed ?? false)
                    <form method="POST" action="{{ route('poland.report.generate', ['period' => $period->toString()]) }}">
                        @csrf
                        <button type="submit">Wygeneruj i zapisz wersję raportu</button>
                    </form>
                    <form method="POST" action="{{ route('poland.report.close', ['period' => $period->toString()]) }}">
                        @csrf
                        <button type="submit" class="ghost">Zamknij miesiąc</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('poland.report.reopen', ['period' => $period->toString()]) }}" class="inline">
                        @csrf
                        <div>
                            <label for="reason">Przyczyna ponownego otwarcia</label>
                            <input type="text" id="reason" name="reason" required
                                   placeholder="np. korekta raportu dobowego">
                        </div>
                        <button type="submit" class="ghost">Otwórz ponownie</button>
                    </form>
                @endunless
                <a href="{{ route('poland.report.history', ['period' => $period->toString()]) }}">
                    <button type="button" class="ghost">Historia wersji</button>
                </a>
            </div>
        </div>

        {{-- Financial situation --}}
        <div class="card">
            <h2>Sytuacja finansowa</h2>
            <table>
                <thead><tr><th></th><th class="num">{{ $period->label() }}</th><th class="num">Narastająco</th></tr></thead>
                <tbody>
                    <tr><td>Przychód</td>
                        <td class="num">{{ $r->financials->revenueMonth->format() }}</td>
                        <td class="num">{{ $r->financials->revenueYearToDate->format() }}</td></tr>
                    <tr><td>Koszty</td>
                        <td class="num">{{ $r->financials->costsMonth->format() }}</td>
                        <td class="num">{{ $r->financials->costsYearToDate->format() }}</td></tr>
                    <tr><td><strong>{{ $r->financials->profitIsKnown() ? 'Dochód' : 'Dochód (górna granica)' }}</strong></td>
                        <td class="num"><strong>{{ $r->financials->incomeMonth()->format() }}</strong></td>
                        <td class="num"><strong>{{ $r->financials->incomeYearToDate()->format() }}</strong></td></tr>
                </tbody>
            </table>
            @if ($caveat = $r->financials->caveat())
                <div class="warn" style="margin-top:12px">{{ $caveat }}</div>
            @endif
        </div>

        {{-- Payment checklist with recording --}}
        <div class="card">
            <h2>Lista płatności</h2>
            <table>
                <thead><tr><th>Płatność</th><th class="num">Kwota</th><th>Termin</th><th>Status</th><th>Zapisz zapłatę</th></tr></thead>
                <tbody>
                @foreach ($r->obligations as $o)
                    <tr>
                        <td>{{ $o->kind->label() }}<div class="basis">{{ $o->payTo() }}</div></td>
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
                            @if ($o->status === PaymentStatus::Paid)
                                <div class="basis">
                                    {{ $o->amountPaid?->format() }} · {{ $o->paidAt?->format('d.m.Y') }}
                                    @if($o->paymentReference)<br>{{ $o->paymentReference }}@endif
                                </div>
                            @endif
                        </td>
                        <td>
                            @if ($o->status === PaymentStatus::Unpaid)
                                <form method="POST" action="{{ route('poland.report.paid', ['period' => $period->toString()]) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="kind" value="{{ $o->kind->value }}">
                                    <input type="text" name="amount_paid" size="9"
                                           value="{{ $o->amount->jsonSerialize() }}" required>
                                    <input type="date" name="paid_at" value="{{ now()->format('Y-m-d') }}" required>
                                    <input type="text" name="payment_reference" size="12" placeholder="nr przelewu">
                                    <button type="submit">Zapłacone</button>
                                </form>
                            @else
                                <span class="basis">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @foreach ($r->report->warnings as $warning)
            <div class="warn">{{ $warning }}</div>
        @endforeach

        {{-- Sales by channel: shop register vs platforms. Monthly, split, never summed silently. --}}
        @php $salesRow = $recorded->firstWhere('period', $period->toString()); @endphp
        @if ($salesRow)
            <div class="card">
                <h2>Sprzedaż {{ $period->label() }} wg kanału</h2>
                <table>
                    <tbody>
                    @foreach ($salesRow->toDomain()->grossByChannel() as $channel => $gross)
                        <tr><td>{{ \Poland\Domain\SalesChannel::label($channel) }}</td><td class="num">{{ $gross->format() }}</td></tr>
                    @endforeach
                    <tr><td><strong>Razem brutto</strong></td><td class="num"><strong>{{ $salesRow->toDomain()->grossTotal()->format() }}</strong></td></tr>
                    </tbody>
                </table>
            </div>
        @endif
    @endif

    {{-- Integration status: what is actually connected, stated so an empty
         result can never read as a statement about the taxpayer's records. --}}
    @if (!empty($integrations))
        <div class="card">
            <h2>Stan integracji</h2>
            <table>
                <thead><tr><th>Integracja</th><th>Status</th><th>Co to znaczy</th></tr></thead>
                <tbody>
                @foreach ($integrations as $integration)
                    <tr>
                        <td>{{ $integration->name }}</td>
                        <td>
                            <span class="pill {{ $integration->operational ? 'paid' : 'unpaid' }}">
                                {{ $integration->status }}
                            </span>
                        </td>
                        <td>
                            {{ $integration->detail }}
                            @if ($integration->nextStep)
                                <div class="basis">→ {{ $integration->nextStep }}</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Data entry --}}
    <div class="card">
        <h2>Wprowadź dane</h2>
        <form method="POST" action="{{ route('poland.sales.store') }}" class="inline">
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
                <input type="text" id="correction_reason" name="correction_reason" value="{{ old('correction_reason') }}">
            </div>
            <button type="submit">Zapisz sprzedaż</button>
        </form>

        <details style="margin-top:16px">
            <summary>Koszty i VAT naliczony (nieobowiązkowe — ale bez nich wynik jest górną granicą)</summary>
            <form method="POST" action="{{ route('poland.costs.store') }}" class="inline" style="margin-top:12px">
                @csrf
                <div>
                    <label for="cperiod">Miesiąc</label>
                    <input type="month" id="cperiod" name="period" value="{{ $period->toString() }}" required>
                </div>
                <div>
                    <label for="costs_net">Koszty netto</label>
                    <input type="text" id="costs_net" name="costs_net" inputmode="decimal" placeholder="5 000,00" required>
                </div>
                <div>
                    <label for="input_vat">VAT naliczony</label>
                    <input type="text" id="input_vat" name="input_vat" inputmode="decimal" placeholder="1 150,00">
                </div>
                <div>
                    <label for="document_count">Liczba dokumentów</label>
                    <input type="number" id="document_count" name="document_count" min="0" value="0">
                </div>
                <button type="submit">Zapisz koszty</button>
            </form>
        </details>
    </div>

    <p class="basis" style="text-align:center">
        {{ \Poland\Reporting\MonthlyTaxReport::DISCLAIMER }}
    </p>

</div>
</body>
</html>
