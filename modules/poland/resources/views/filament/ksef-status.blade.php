<x-filament-panels::page>
    @php
        $h = $health ?? null; $ov = $overview ?? null;
        $colour = fn (string $state): string => match ($state) { 'OK' => 'color:#15803d', 'FAILED' => 'color:#b91c1c', default => 'color:#92400e' };
    @endphp
    <div style="display:grid;gap:16px">
        <div style="border:1px solid #dfe3e9;border-radius:12px;padding:16px">
            <div style="font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;opacity:.7">KSeF — stan</div>
            @if (!$h)
                <div style="font-size:1.4rem;font-weight:800">NOT CONFIGURED</div>
                <p>Brak profilu podatnika.</p>
            @else
                <div style="font-size:1.6rem;font-weight:800;{{ $h->isConnected() ? 'color:#15803d' : 'color:#b91c1c' }}">{{ $h->overall }}</div>
                <div style="opacity:.75">tryb {{ $h->mode }} · środowisko {{ $ov['environment']->shortLabel() }} · API {{ $ov['api_version'] }}</div>
                <table style="width:100%;margin-top:10px;font-size:.9rem">
                    @foreach ($h->checks as $c)
                        <tr><td style="padding:4px 0"><code>{{ $c->name }}</code></td><td style="font-weight:700;{{ $colour($c->state) }}">{{ $c->state }}</td><td style="opacity:.85">{{ $c->detail }}</td></tr>
                    @endforeach
                </table>
                <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:10px;font-size:.9rem">
                    <span>Przychodzące: <b>{{ $ov['incoming_count'] }}</b></span>
                    <span>Wychodzące przyjęte: <b>{{ $ov['outgoing_accepted'] }}</b></span>
                    <span>W toku: <b>{{ $ov['outgoing_pending'] }}</b></span>
                    <span>Przegląd: <b>{{ $ov['outgoing_review'] }}</b></span>
                    <span>Odrzucone: <b>{{ $ov['outgoing_rejected'] }}</b></span>
                    <span>Błędy 30 dni: <b>{{ $ov['failed_operations_30d'] }}</b></span>
                </div>
            @endif
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <a href="{{ $statusUrl }}" style="padding:9px 14px;border-radius:8px;background:#14171c;color:#fff;text-decoration:none">Stan i test połączenia</a>
            <a href="{{ $configureUrl }}" style="padding:9px 14px;border-radius:8px;border:1px solid #dfe3e9;text-decoration:none">Konfiguruj KSeF</a>
            <a href="{{ $invoicesUrl }}" style="padding:9px 14px;border-radius:8px;border:1px solid #dfe3e9;text-decoration:none">Faktury wychodzące</a>
        </div>
        <p style="font-size:.82rem;opacity:.7">„CONNECTED” wymaga udanego, świeżego uwierzytelnienia. Wysyłka do organów (JPK/PIT/ZUS) pozostaje wyłączona.</p>
    </div>
</x-filament-panels::page>
