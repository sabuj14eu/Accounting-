@extends('poland::ksef.layout')
@section('title', 'KSeF — faktury wychodzące')
@section('content')
@php
    use Poland\Ksef\Outgoing\KsefSubmissionState;
    $pill = fn (string $state): string => match ($state) {
        'ACCEPTED' => 'ok', 'REJECTED', 'BLOCKED', 'CANCELLED' => 'bad', 'MANUAL_REVIEW' => 'warn',
        'SUBMITTED', 'PROCESSING' => 'info', default => 'muted' };
@endphp
<h1>Faktury wychodzące → KSeF</h1>
<p class="sub">{{ $profile?->name ?? 'Brak profilu' }} · środowisko {{ $gate->environment()->shortLabel() }} · tryb {{ $gate->mode() }}</p>

<div class="card">
    <h2>Przygotuj fakturę księgową do KSeF</h2>
    <p class="basis">Cykl: faktura księgowa → przygotowanie (mapowanie FA(3) + walidacja XSD) → przegląd → <strong>ręczna</strong> wysyłka → status → UPO. Zamówienie sprzedaży nigdy nie jest wysyłane samo; wysyłana jest faktura, i tylko na wyraźne polecenie.</p>
    @if (!$erpAvailable)
        <div class="warn">Moduł faktur ERP nie jest dostępny w tej instalacji — przygotowanie z faktur księgowych jest niemożliwe.</div>
    @else
    <form method="post" action="{{ route('poland.ksef.submissions.prepare') }}" class="inline">@csrf
        <div><label>Faktura księgowa</label>
            <select name="erp_invoice_id" required style="min-width:360px">
                @foreach ($erpInvoices as $inv)
                    <option value="{{ $inv['id'] }}">#{{ $inv['id'] }} · {{ $inv['number'] }} · {{ $inv['date'] }} · {{ $inv['customer'] }} · {{ $inv['total'] }}</option>
                @endforeach
            </select></div>
        <button type="submit">Przygotuj FA(3)</button>
    </form>
    @if ($erpInvoices === [])<p class="basis">Brak faktur w ERP.</p>@endif

    <details style="margin-top:14px"><summary>Identyfikatory nabywców (ERP nie przechowuje NIP kontrahenta — potwierdź go raz na klienta)</summary>
        <table style="margin-top:8px"><thead><tr><th>Klient</th><th>Zapisany identyfikator</th><th>Ustaw</th></tr></thead><tbody>
        @foreach (collect($erpInvoices)->unique('customer_id')->filter(fn ($i) => $i['customer_id'] !== null) as $inv)
            @php $row = $identifiers[(int) $inv['customer_id']] ?? null; @endphp
            <tr>
                <td>#{{ $inv['customer_id'] }} {{ $inv['customer'] }}</td>
                <td>@if($row) <code>{{ $row['identifier_type'] }}</code> {{ $row['identifier_country'] }} {{ $row['identifier_value'] }} <span class="basis">({{ $row['confirmed_by'] }})</span> @else <span class="pill warn">NIE USTALONO</span> @endif</td>
                <td>
                    <form method="post" action="{{ route('poland.ksef.customer_identifier', ['customerId' => $inv['customer_id']]) }}" class="inline">@csrf
                        <select name="identifier_type" style="max-width:150px">
                            <option value="nip" @selected(($row['identifier_type'] ?? '') === 'nip')>NIP</option>
                            <option value="vat_ue" @selected(($row['identifier_type'] ?? '') === 'vat_ue')>VAT UE</option>
                            <option value="other" @selected(($row['identifier_type'] ?? '') === 'other')>inny</option>
                            <option value="none" @selected(($row['identifier_type'] ?? '') === 'none')>brak (konsument)</option>
                        </select>
                        <input name="identifier_country" placeholder="kraj (VAT UE)" value="{{ $row['identifier_country'] ?? '' }}" style="max-width:110px" maxlength="2">
                        <input name="identifier_value" placeholder="numer" value="{{ $row['identifier_value'] ?? '' }}" style="max-width:180px" maxlength="50">
                        <input name="address_line1" placeholder="adres, linia 1" value="{{ $row['address_line1'] ?? '' }}" style="max-width:200px">
                        <input name="address_line2" placeholder="kod i miejscowość" value="{{ $row['address_line2'] ?? '' }}" style="max-width:180px">
                        <input type="hidden" name="address_country" value="{{ $row['address_country'] ?? 'PL' }}">
                        <button type="submit" class="ghost">Zapisz</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody></table>
    </details>
    @endif
</div>

<div class="card">
    <h2>Zgłoszenia</h2>
    @if ($submissions->isEmpty())
        <p class="basis">Nie przygotowano jeszcze żadnej faktury.</p>
    @else
    <table><thead><tr><th>Zamówienie</th><th>Faktura</th><th>Nabywca</th><th class="num">Brutto</th><th>KSeF</th><th>Numer KSeF</th><th>Śr.</th><th></th></tr></thead><tbody>
    @foreach ($submissions as $s)
        <tr>
            <td>{{ $s->erp_sales_order_id ? '#'.$s->erp_sales_order_id : '—' }}</td>
            <td>{{ $s->invoice_number }} <span class="basis">#{{ $s->erp_invoice_id }}</span></td>
            <td>{{ $s->buyer_label ?? '—' }}</td>
            <td class="num">{{ $s->gross !== null ? number_format((float) $s->gross, 2, ',', ' ').' '.$s->currency : '—' }}</td>
            <td><span class="pill {{ $pill($s->state) }}">{{ KsefSubmissionState::from($s->state)->label() }}</span></td>
            <td>@if($s->ksef_number)<code>{{ $s->ksef_number }}</code>@else — @endif</td>
            <td class="basis">{{ strtoupper($s->environment) }}</td>
            <td><a href="{{ route('poland.ksef.submissions.show', ['submission' => $s->getKey()]) }}">Szczegóły</a></td>
        </tr>
    @endforeach
    </tbody></table>
    @endif
</div>
@endsection
