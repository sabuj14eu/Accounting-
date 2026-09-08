@extends('poland::ksef.layout')
@section('title', 'KSeF — stan integracji')
@section('content')
@php
    $ov = $overview;
    $cred = $ov['credential'] ?? null;
    $modePill = fn (string $mode): string => match ($mode) { 'PRODUCTION' => 'bad', 'FAKE' => 'warn', 'DISABLED' => 'muted', default => 'info' };
    $statePill = fn (string $s): string => match ($s) { 'OK' => 'ok', 'FAILED' => 'bad', 'N/A' => 'muted', default => 'warn' };
@endphp

<h1>KSeF — Krajowy System e-Faktur</h1>
<p class="sub">
    {{ $profile?->name ?? 'Brak profilu podatnika' }}@if($profile?->nip) · NIP {{ $profile->nip }}@endif
    · środowisko <strong>{{ $gate->environment()->shortLabel() }}</strong>
    · tryb <span class="pill {{ $modePill($gate->mode()) }}">{{ $gate->mode() }}</span>
    · API {{ $ov['api_version'] ?? '—' }}
</p>

@if ($gate->mode() === 'PRODUCTION')
    <div class="alarm">ŚRODOWISKO PRODUKCYJNE — wysłana faktura ma skutek prawny.</div>
@elseif ($gate->mode() === 'FAKE')
    <div class="warn">ATRAPA transportu (KSEF_TRANSPORT=fake). Nic nie trafia do KSeF; wyniki nie są podstawą rozliczenia.</div>
@elseif ($gate->mode() === 'DISABLED')
    <div class="warn">{{ $gate->status()['detail'] }}</div>
@else
    <div class="info">Środowisko {{ $gate->mode() }}: faktury nie mają skutku prawnego. Nie wysyłaj tu rzeczywistych danych podatników.</div>
@endif

@if ($health)
<div class="card">
    <h2>Status</h2>
    <div class="row">
        <div><div class="what">Połączenie z KSeF</div>
            <div class="meta">„CONNECTED” oznacza udane uwierzytelnienie tokenem nie starsze niż {{ config('poland.ksef.auth.freshness_hours', 24) }} h. Zapisane dane logowania to nie połączenie.</div></div>
        <div class="big" style="color: {{ $health->isConnected() ? 'var(--ok)' : 'var(--pay)' }}">{{ $health->overall }}</div>
    </div>
    <table>
        <thead><tr><th>Sprawdzenie</th><th>Wynik</th><th>Szczegóły</th><th>Kiedy</th></tr></thead>
        <tbody>
        @foreach ($health->checks as $check)
            <tr>
                <td><code>{{ $check->name }}</code></td>
                <td><span class="pill {{ $statePill($check->state) }}">{{ $check->state }}</span></td>
                <td>{{ $check->detail }}</td>
                <td class="basis">{{ $check->checkedAt ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="actions">
        <form method="post" action="{{ route('poland.ksef.test') }}">@csrf<button type="submit" @disabled(!$gate->isEnabled())>Testuj połączenie</button></form>
        <a href="{{ route('poland.ksef.wizard') }}"><button type="button" class="ghost">Konfiguruj KSeF</button></a>
        <form method="post" action="{{ route('poland.ksef.sync') }}">@csrf<button type="submit" class="ghost" @disabled($unavailableReason !== null)>Synchronizuj teraz</button></form>
        @if ($unavailableReason)<span class="basis">Synchronizacja niedostępna: {{ $unavailableReason }}</span>@endif
    </div>
</div>
@endif

@if ($ov)
<div class="card">
    <h2>Fakty</h2>
    <div class="grid">
        <div class="kv"><b>Środowisko</b>{{ $gate->environment()->label() }}</div>
        <div class="kv"><b>Transport</b>{{ strtoupper($ov['transport_kind']) }} — {{ $ov['transport_enabled'] ? 'włączony' : 'wyłączony' }}</div>
        <div class="kv"><b>NIP podatnika</b>{{ $cred?->nip ?? '—' }}</div>
        <div class="kv"><b>Uwierzytelnienie</b>
            @if (!$cred) NOT CONFIGURED
            @elseif (!$cred->hasToken()) brak tokenu
            @else token …{{ $cred->token_fingerprint }} @if($cred->token_valid_until) (ważny do {{ $cred->token_valid_until->format('d.m.Y') }})@endif
                @if ($cred->observed_permissions) · uprawnienia: {{ implode(', ', $cred->observed_permissions) }} @endif
                @if ($cred->permissionsMissing()) · <span class="pill bad">BRAK {{ implode(', ', $cred->permissionsMissing()) }}</span>@endif
            @endif
        </div>
        <div class="kv"><b>Integracja włączona</b>{{ $cred?->enabled ? 'TAK' : 'NIE' }}</div>
        <div class="kv"><b>Ostatnie udane połączenie</b>{{ $cred?->last_auth_at?->format('d.m.Y H:i:s') ?? '—' }}</div>
        <div class="kv"><b>Ostatnia synchronizacja</b>{{ $cred?->last_sync_at?->format('d.m.Y H:i:s') ?? '—' }} @if($cred?->last_sync_at) ({{ $cred->last_sync_ok ? 'w całości' : 'NIE w całości' }})@endif</div>
        <div class="kv"><b>Faktury przychodzące (pobrane)</b>{{ $ov['incoming_count'] }} @if($ov['incoming_review_count'])· {{ $ov['incoming_review_count'] }} do przeglądu@endif</div>
        <div class="kv"><b>Faktury wychodzące</b>przyjęte {{ $ov['outgoing_accepted'] }} · w toku {{ $ov['outgoing_pending'] }} · przegląd {{ $ov['outgoing_review'] }} · odrzucone {{ $ov['outgoing_rejected'] }}</div>
        <div class="kv"><b>Nieudane operacje (30 dni)</b>{{ $ov['failed_operations_30d'] }}</div>
        <div class="kv"><b>Ostatni błąd</b>@if($ov['last_error']) [{{ $ov['last_error']->category }}] {{ \Illuminate\Support\Str::limit($ov['last_error']->message, 160) }} <span class="basis">({{ $ov['last_error']->occurred_at?->format('d.m.Y H:i') }})</span>@else — @endif</div>
        <div class="kv"><b>Wersja API</b>{{ $ov['api_version'] }} (przypięta w resources/ksef/PINNED.md)</div>
        <div class="kv"><b>Wysyłka do organów (JPK/PIT/ZUS)</b>DISABLED</div>
    </div>
</div>
@endif

@if ($review->isNotEmpty())
<div class="card">
    <h2>Wymagają przeglądu ręcznego</h2>
    <table><thead><tr><th>Faktura</th><th>Nabywca</th><th>Powód</th><th></th></tr></thead><tbody>
    @foreach ($review as $s)
        <tr><td>{{ $s->invoice_number }}</td><td>{{ $s->buyer_label }}</td><td>{{ $s->manual_review_reason }}</td>
            <td><a href="{{ route('poland.ksef.submissions.show', ['submission' => $s->getKey()]) }}">Otwórz</a></td></tr>
    @endforeach
    </tbody></table>
</div>
@endif

<div class="card">
    <h2>Ostatnie faktury pobrane z KSeF</h2>
    @if ($recentIncoming->isEmpty())
        <p class="basis">Nic nie pobrano. To informacja o systemie, nie o fakturach podatnika.</p>
    @else
        <table><thead><tr><th>Numer KSeF</th><th>Numer</th><th>Kierunek</th><th>Sprzedawca</th><th class="num">Brutto</th><th>Stan</th></tr></thead><tbody>
        @foreach ($recentIncoming as $d)
            <tr><td><code>{{ $d->ksef_number }}</code></td><td>{{ $d->invoice_number ?? 'MISSING_FIELD' }}</td><td>{{ $d->direction }}</td><td>{{ $d->seller_name ?? $d->seller_nip ?? 'MISSING_FIELD' }}</td>
                <td class="num">{{ $d->gross !== null ? number_format((float) $d->gross, 2, ',', ' ') : 'MISSING_FIELD' }}</td>
                <td><span class="pill {{ $d->needs_review ? 'warn' : 'ok' }}">{{ strtoupper($d->processing_status) }}</span></td></tr>
        @endforeach
        </tbody></table>
    @endif
</div>

@if ($recentErrors->isNotEmpty())
<div class="card">
    <h2>Ostatnie błędy</h2>
    <table><thead><tr><th>Kiedy</th><th>Operacja</th><th>Kategoria</th><th>Komunikat</th></tr></thead><tbody>
    @foreach ($recentErrors as $e)
        <tr><td class="basis">{{ $e->occurred_at?->format('d.m.Y H:i:s') }}</td><td><code>{{ $e->operation }}</code></td>
            <td><span class="pill {{ $e->retryable ? 'warn' : 'bad' }}">{{ $e->category }}</span>@if(!$e->outcome_known) <span class="pill bad">WYNIK NIEZNANY</span>@endif</td>
            <td>{{ $e->message }}</td></tr>
    @endforeach
    </tbody></table>
</div>
@endif
@endsection
