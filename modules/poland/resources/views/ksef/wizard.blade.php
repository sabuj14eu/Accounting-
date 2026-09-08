@extends('poland::ksef.layout')
@section('title', 'KSeF — konfiguracja krok '.$step)
@section('content')
@php
    $c = $credential;
    $done = (int) ($c?->setup_step ?? 0);
    $names = [1 => 'Środowisko', 2 => 'Dane sprzedawcy', 3 => 'Uprawnienia i token', 4 => 'Domyślne dane faktury', 5 => 'Test i włączenie'];
@endphp
<h1>Konfiguracja KSeF</h1>
<p class="sub">{{ $profile?->name ?? 'Brak profilu podatnika' }} · środowisko <strong>{{ $environment->shortLabel() }}</strong> · API {{ $apiVersion }}</p>

<div class="steps">
    @foreach ($names as $n => $label)
        @if ($n === $step)<span class="current">{{ $n }}. {{ $label }}</span>
        @elseif ($n <= $done + 1)<a class="{{ $n <= $done ? 'done' : '' }}" href="{{ route('poland.ksef.wizard', ['step' => $n]) }}">{{ $n }}. {{ $label }}</a>
        @else<span>{{ $n }}. {{ $label }}</span>@endif
    @endforeach
</div>

@if (!$profile)
    <div class="bad">Najpierw utwórz profil podatnika.</div>
@else

@if ($step === 1)
<div class="card">
    <h2>Krok 1 — środowisko</h2>
    <p>Środowisko KSeF jest ustawieniem wdrożenia (<code>KSEF_ENVIRONMENT</code>), nie wyborem na ekranie — token wydany dla jednego środowiska nigdy nie jest wysyłany do innego.</p>
    <div class="grid">
        <div class="kv"><b>Środowisko</b>{{ $environment->label() }}</div>
        <div class="kv"><b>Tryb transportu</b>{{ $gate->mode() }}</div>
        <div class="kv"><b>Dokumentacja API</b><a href="{{ $environment->documentationUrl() }}" target="_blank" rel="noopener">{{ $environment->documentationUrl() }}</a></div>
        <div class="kv"><b>Aplikacja podatnika (tam nadaje się uprawnienia i generuje token)</b><a href="{{ $environment->taxpayerApplicationUrl() }}" target="_blank" rel="noopener">{{ $environment->taxpayerApplicationUrl() }}</a></div>
        <div class="kv"><b>Format faktury</b>FA(3), schemat 1-0E</div>
    </div>
    @if ($environment->isProduction())
        <div class="alarm" style="margin-top:14px">To jest środowisko PRODUKCYJNE. Każda wysłana faktura ma skutek prawny. Włączenie wymaga zaliczonej bramki produkcyjnej (docs/KSEF_PRODUCTION_GATE.md).</div>
    @else
        <div class="info" style="margin-top:14px">Środowisko {{ $environment->shortLabel() }}: bez skutku prawnego. Używaj wyłącznie danych testowych — NIP-ów losowych, nie rzeczywistych podmiotów.</div>
    @endif
    <form method="post" action="{{ route('poland.ksef.wizard.store', ['step' => 1]) }}">@csrf
        <div class="field"><label><input type="checkbox" name="confirm_environment" value="1"> Rozumiem, które środowisko konfiguruję.</label></div>
        <button type="submit">Dalej →</button>
    </form>
</div>
@endif

@if ($step === 2)
<div class="card">
    <h2>Krok 2 — dane sprzedawcy na fakturze (Podmiot1)</h2>
    <p class="basis">FA(3) wymaga NIP, nazwy i adresu sprzedawcy. Nazwa pochodzi z profilu podatnika ({{ $profile->name }}); adres podaj tutaj — profil go nie przechowuje.</p>
    <form method="post" action="{{ route('poland.ksef.wizard.store', ['step' => 2]) }}">@csrf
        <div class="field"><label>NIP podatnika (10 cyfr, kontekst uwierzytelnienia)</label><input name="nip" value="{{ old('nip', $c->nip ?: $profile->nip) }}" required pattern="\d{10}"></div>
        <div class="field"><label>Kod kraju</label><input name="seller_address_country" value="{{ old('seller_address_country', $c->seller_address_country ?? 'PL') }}" required pattern="[A-Z]{2}" style="max-width:120px"></div>
        <div class="field"><label>Adres, linia 1 (ulica, numer)</label><input name="seller_address_line1" value="{{ old('seller_address_line1', $c->seller_address_line1) }}" required maxlength="512"></div>
        <div class="field"><label>Adres, linia 2 (kod pocztowy, miejscowość)</label><input name="seller_address_line2" value="{{ old('seller_address_line2', $c->seller_address_line2) }}" maxlength="512"></div>
        <div class="field"><label>E-mail (opcjonalnie, trafia na fakturę)</label><input name="seller_email" type="email" value="{{ old('seller_email', $c->seller_email) }}"></div>
        <div class="field"><label>Telefon (opcjonalnie, max 16 znaków)</label><input name="seller_phone" value="{{ old('seller_phone', $c->seller_phone) }}" maxlength="16"></div>
        <div class="field"><label>Miejsce wystawienia (P_1M, opcjonalnie)</label><input name="issue_place" value="{{ old('issue_place', $c->issue_place) }}" maxlength="256"></div>
        <div class="field"><label>Nazwa systemu w nagłówku faktury (SystemInfo)</label><input name="system_info" value="{{ old('system_info', $c->system_info ?? config('poland.ksef.system_info')) }}" maxlength="256"></div>
        <button type="submit">Zapisz i dalej →</button>
    </form>
</div>
@endif

@if ($step === 3)
<div class="card">
    <h2>Krok 3 — uprawnienia i token KSeF</h2>
    <p>Zrób to w aplikacji podatnika KSeF: <a href="{{ $environment->taxpayerApplicationUrl() }}" target="_blank" rel="noopener">{{ $environment->taxpayerApplicationUrl() }}</a></p>
    <ol>
        <li>Zaloguj się w kontekście NIP <strong>{{ $c->nip ?: $profile->nip }}</strong> (właściciel lub osoba z uprawnieniem <code>CredentialsManage</code>).</li>
        <li>Jeśli tę aplikację ma obsługiwać inna osoba niż właściciel: <em>Uprawnienia → nadaj osobie</em> uprawnienia <code>InvoiceRead</code> i <code>InvoiceWrite</code> w kontekście tego NIP.</li>
        <li><em>Tokeny → wygeneruj token</em> z uprawnieniami dokładnie: <strong>{{ implode(', ', $requiredScopes) }}</strong>, opis np. „SignalMesh Accounts”. Nie nadawaj tokenowi <code>CredentialsManage</code> — ta aplikacja go odmówi.</li>
        <li>Skopiuj token <strong>od razu</strong> — KSeF pokazuje go tylko raz. Wklej poniżej. Zapisujemy go zaszyfrowany; nie da się go potem odczytać ani wyświetlić.</li>
    </ol>
    <form method="post" action="{{ route('poland.ksef.wizard.store', ['step' => 3]) }}">@csrf
        <div class="field"><label>Token KSeF @if($c->hasToken())(zapisany: …{{ $c->token_fingerprint }} — zostaw puste, aby zachować)@endif</label>
            <input name="token" type="password" autocomplete="off" @if(!$c->hasToken()) required @endif minlength="16"></div>
        <div class="field"><label>Numer referencyjny tokena (opcjonalnie, z listy tokenów w KSeF)</label><input name="token_reference" value="{{ old('token_reference', $c->token_reference) }}" maxlength="64"></div>
        <div class="field"><label>Token ważny do (opcjonalnie)</label><input name="token_valid_until" type="date" value="{{ old('token_valid_until', $c->token_valid_until?->format('Y-m-d')) }}" style="max-width:200px"></div>
        <div class="field"><label>Uprawnienia nadane tokenowi (zaznacz zgodnie z KSeF)</label>
            @php $declared = old('token_permissions', $c->token_permissions ?? $requiredScopes); @endphp
            @foreach ($requiredScopes as $scope)
                <label style="display:inline-block;margin-right:14px"><input type="checkbox" name="token_permissions[]" value="{{ $scope }}" @checked(in_array($scope, (array) $declared, true))> {{ $scope }}</label>
            @endforeach
            <div class="basis">Rzeczywiste uprawnienia odczytamy z tokena dostępowego przy teście połączenia — deklaracja jest tylko podpowiedzią.</div>
        </div>
        <button type="submit">Zapisz token i dalej →</button>
    </form>
</div>
@endif

@if ($step === 4)
<div class="card">
    <h2>Krok 4 — domyślne dane faktury</h2>
    <form method="post" action="{{ route('poland.ksef.wizard.store', ['step' => 4]) }}">@csrf
        @if ($vatExempt)
            <div class="warn">Profil jest podatnikiem zwolnionym z VAT — każda pozycja trafi do KSeF ze stawką „zw” i FA(3) wymaga podstawy prawnej zwolnienia (P_19A). System jej nie zgaduje.</div>
        @endif
        <div class="field"><label>Podstawa prawna zwolnienia z VAT (P_19A) — np. „art. 113 ust. 1 ustawy o VAT”</label><input name="exemption_legal_basis" value="{{ old('exemption_legal_basis', $c->exemption_legal_basis) }}" maxlength="256" @if($vatExempt) required @endif></div>
        <div class="field"><label>Domyślna forma płatności</label>
            <select name="default_payment_form" style="max-width:260px"><option value="">— nie umieszczaj na fakturze —</option>
            @foreach ($paymentForms as $code => $label)<option value="{{ $code }}" @selected(old('default_payment_form', $c->default_payment_form) === (string) $code)>{{ $code }} — {{ $label }}</option>@endforeach
            </select></div>
        <div class="field"><label>Numer rachunku (bez spacji, opcjonalnie)</label><input name="bank_account" value="{{ old('bank_account', $c->bank_account) }}" maxlength="40"></div>
        <div class="field"><label>Nazwa banku (opcjonalnie)</label><input name="bank_name" value="{{ old('bank_name', $c->bank_name) }}"></div>
        <button type="submit">Zapisz i dalej →</button>
    </form>
</div>
@endif

@if ($step === 5)
<div class="card">
    <h2>Krok 5 — test połączenia i włączenie</h2>
    @if (!$c->isConfigured())
        <div class="bad">Konfiguracja niekompletna: {{ implode(', ', $c->missingConfiguration()) }}. Wróć do wcześniejszych kroków.</div>
    @else
        <p>Test wykonuje prawdziwe uwierzytelnienie tokenem w środowisku {{ $environment->shortLabel() }}. Nie wysyła żadnej faktury.</p>
        <form method="post" action="{{ route('poland.ksef.test') }}">@csrf<button type="submit" @disabled(!$gate->isEnabled())>Testuj połączenie</button></form>
        @if (!$gate->isEnabled())<div class="warn" style="margin-top:10px">Transport jest wyłączony ({{ $gate->mode() }}) — test niemożliwy. Ustaw <code>KSEF_TRANSPORT=real</code> i <code>KSEF_TRANSPORT_ENABLED=true</code> w konfiguracji wdrożenia.</div>@endif
        @if ($health)
            <table style="margin-top:12px"><tbody>
            @foreach ($health->checks as $check)<tr><td><code>{{ $check->name }}</code></td><td><span class="pill {{ $check->state === 'OK' ? 'ok' : ($check->state === 'FAILED' ? 'bad' : 'warn') }}">{{ $check->state }}</span></td><td>{{ $check->detail }}</td></tr>@endforeach
            </tbody></table>
            <p><strong>Wynik: {{ $health->overall }}</strong> @if($c->last_connection_test_at)<span class="basis">(test z {{ $c->last_connection_test_at->format('d.m.Y H:i:s') }})</span>@endif</p>
        @endif
        <form method="post" action="{{ route('poland.ksef.wizard.store', ['step' => 5]) }}" style="margin-top:14px">@csrf
            <div class="field"><label><input type="checkbox" name="enabled" value="1" @checked($c->enabled) @disabled($c->last_connection_ok !== true)> Włącz integrację KSeF dla tego podatnika @if($c->last_connection_ok !== true)<span class="basis">(dostępne po udanym teście)</span>@endif</label></div>
            @if ($environment->isProduction())<div class="alarm">Włączenie na PRODUKCJI zostanie zapisane w dzienniku audytu z Twoim identyfikatorem. Wysyłka faktur pozostaje ręczna, faktura po fakturze.</div>@endif
            <button type="submit">Zapisz</button>
        </form>
    @endif
</div>
@endif
@endif
@endsection
