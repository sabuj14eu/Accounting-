@php $rows = $rows ?? []; @endphp
<div style="border:1px solid #dfe3e9;border-radius:10px;padding:12px 14px;margin-bottom:12px;font-size:.9rem">
    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:baseline">
        <strong>KSeF — stan faktur z zamówień</strong>
        <a href="{{ route('poland.ksef.submissions') }}">wszystkie zgłoszenia →</a>
    </div>
    @if ($rows === [])
        <div style="opacity:.75;margin-top:6px">Żadna faktura nie została jeszcze przygotowana do KSeF. Stan „NOT SENT” dotyczy każdej faktury bez zgłoszenia.</div>
    @else
        <table style="width:100%;margin-top:8px">
            <thead><tr style="text-align:left;opacity:.7"><th>Zamówienie</th><th>Faktura</th><th>Nabywca</th><th style="text-align:right">Brutto</th><th>KSeF</th><th>Numer KSeF</th></tr></thead>
            <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td>{{ $r['erp_sales_order_id'] ? '#'.$r['erp_sales_order_id'] : '—' }}</td>
                    <td><a href="{{ $r['url'] }}">{{ $r['invoice_number'] }}</a></td>
                    <td>{{ $r['buyer'] ?? '—' }}</td>
                    <td style="text-align:right">{{ $r['gross'] !== null ? number_format((float) $r['gross'], 2, ',', ' ') : '—' }}</td>
                    <td><b>{{ $r['label'] }}</b></td>
                    <td>{{ $r['ksef_number'] ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
