@extends('poland::ksef.layout')
@section('title', 'KSeF — faktura '.$submission->invoice_number)
@section('content')
@php
    use Poland\Ksef\Outgoing\KsefSubmissionState as S;
    $s = $submission;
    $pill = fn (string $state): string => match ($state) {
        'ACCEPTED' => 'ok', 'REJECTED', 'BLOCKED', 'CANCELLED' => 'bad', 'MANUAL_REVIEW' => 'warn',
        'SUBMITTED', 'PROCESSING' => 'info', default => 'muted' };
@endphp
<h1>Faktura {{ $s->invoice_number }}</h1>
<p class="sub">Nabywca {{ $s->buyer_label ?? '—' }} · wystawiona {{ $s->issue_date?->format('d.m.Y') ?? '—' }} · faktura księgowa #{{ $s->erp_invoice_id }} @if($s->erp_sales_order_id)· zamówienie #{{ $s->erp_sales_order_id }}@endif · środowisko {{ strtoupper($s->environment) }}</p>

<div class="card">
    <div class="row">
        <div><div class="what">Kwota</div><div class="meta">netto {{ $s->net !== null ? number_format((float) $s->net, 2, ',', ' ') : '—' }} · VAT {{ $s->vat !== null ? number_format((float) $s->vat, 2, ',', ' ') : '—' }} · z faktury księgowej, nie z KSeF</div></div>
        <div class="big">{{ $s->gross !== null ? number_format((float) $s->gross, 2, ',', ' ').' '.$s->currency : '—' }}</div>
    </div>
    <div class="row">
        <div><div class="what">KSeF</div>
            <div class="meta">
                @if ($s->ksef_status_code !== null) status KSeF {{ $s->ksef_status_code }} — {{ $s->ksef_status_description }} @endif
                @if ($s->submitted_at) · wysłana {{ $s->submitted_at->format('d.m.Y H:i:s') }} @endif
                @if ($s->accepted_at) · przyjęta {{ $s->accepted_at->format('d.m.Y H:i:s') }} @endif
            </div></div>
        <div class="big"><span class="pill {{ $pill($s->state) }}" style="font-size:1rem">{{ $state->label() }}</span></div>
    </div>
    <div class="grid" style="margin-top:12px">
        <div class="kv"><b>Numer KSeF</b>@if($s->ksef_number)<code>{{ $s->ksef_number }}</code>@else — @endif</div>
        <div class="kv"><b>Sesja / referencja faktury</b>{{ $s->session_reference ?? '—' }}<br>{{ $s->invoice_reference ?? '—' }}</div>
        <div class="kv"><b>Data nadania numeru / trwałego zapisu</b>{{ $s->acquisition_date?->format('d.m.Y H:i:s') ?? '—' }}<br>{{ $s->permanent_storage_date?->format('d.m.Y H:i:s') ?? '—' }}</div>
        <div class="kv"><b>UPO</b>@if($s->upoDocument) <span class="pill {{ $s->upoDocument->isValid() ? 'ok' : 'warn' }}">AVAILABLE</span> <a href="{{ route('poland.ksef.submissions.upo_xml', ['submission' => $s->getKey()]) }}">pobierz XML</a> <span class="basis">sha256 {{ substr($s->upoDocument->xml_hash, 0, 16) }}…</span>@elseif($state === S::Accepted) nie pobrane jeszcze @else — @endif</div>
        <div class="kv"><b>Dokument FA(3)</b>@if($s->document) {{ $s->document->schema_system_code }} {{ $s->document->schema_version }} · {{ $s->document->xml_size }} B · <span class="pill {{ $s->document->isValid() ? 'ok' : 'bad' }}">{{ $s->document->validation_status }}</span> <a href="{{ route('poland.ksef.submissions.xml', ['submission' => $s->getKey()]) }}">pobierz XML</a><br><span class="basis">sha256 {{ $s->document->xml_hash }}</span><br><span class="basis">wygenerowano {{ $s->document->generated_at?->format('d.m.Y H:i:s') }} ({{ $s->document->generator_version }})</span>@else — @endif</div>
        <div class="kv"><b>Próby wysyłki</b>{{ $s->attempt_count }}</div>
    </div>

    @if ($state === S::Blocked)
        <div class="bad" style="margin-top:12px"><strong>ZABLOKOWANA — nie wysłano.</strong><br><pre style="white-space:pre-wrap">{{ $s->blocked_reason }}</pre>Popraw dane w księgowości i przygotuj fakturę ponownie.</div>
    @endif
    @if ($state === S::Rejected)
        <div class="bad" style="margin-top:12px"><strong>ODRZUCONA przez KSeF.</strong> Powód: {{ $s->rejection_reason }}<br>Działanie: PRZEGLĄD RĘCZNY — popraw fakturę w księgowości i przygotuj nowe zgłoszenie.</div>
    @endif
    @if ($state === S::ManualReview)
        <div class="warn" style="margin-top:12px"><strong>PRZEGLĄD RĘCZNY.</strong> {{ $s->manual_review_reason }}</div>
    @endif
    @if ($s->lastError)
        <div class="warn" style="margin-top:12px">Ostatni błąd [{{ $s->lastError->category }}@if(!$s->lastError->outcome_known), WYNIK NIEZNANY@endif]: {{ $s->lastError->message }}</div>
    @endif

    <div class="actions">
        @if ($state === S::Validated)
            <form method="post" action="{{ route('poland.ksef.submissions.ready', ['submission' => $s->getKey()]) }}">@csrf<button type="submit">Zatwierdź do wysyłki</button></form>
        @endif
        @if ($state === S::Ready)
            <form method="post" action="{{ route('poland.ksef.submissions.send', ['submission' => $s->getKey()]) }}" onsubmit="return confirm('Wysłać fakturę {{ $s->invoice_number }} do KSeF ({{ strtoupper($s->environment) }})?');">@csrf
                <label style="display:inline-block;margin-right:10px"><input type="checkbox" name="confirm" value="1" required> Potwierdzam wysyłkę do KSeF {{ strtoupper($s->environment) }}</label>
                <button type="submit" class="{{ $gate->hasLegalEffect() ? 'danger' : '' }}">Wyślij do KSeF</button></form>
        @endif
        @if ($state->isPending())
            <form method="post" action="{{ route('poland.ksef.submissions.poll', ['submission' => $s->getKey()]) }}">@csrf<button type="submit">Sprawdź status</button></form>
        @endif
        @if ($state === S::Accepted && !$s->upoDocument)
            <form method="post" action="{{ route('poland.ksef.submissions.upo', ['submission' => $s->getKey()]) }}">@csrf<button type="submit">Pobierz UPO</button></form>
        @endif
        @if ($state === S::ManualReview)
            <form method="post" action="{{ route('poland.ksef.submissions.resolve', ['submission' => $s->getKey()]) }}" class="inline">@csrf
                <select name="action">
                    <option value="recheck">Sprawdź ponownie w KSeF</option>
                    <option value="resend">Wyślij ponownie (nowa sesja)</option>
                    @if (($s->ksef_status_details['extensions']['originalKsefNumber'] ?? null))<option value="accept_original">Duplikat: to ta sama faktura — przypisz numer {{ $s->ksef_status_details['extensions']['originalKsefNumber'] }}</option>@endif
                    <option value="cancel">Anuluj zgłoszenie</option>
                </select>
                <input name="note" placeholder="uzasadnienie (do audytu)" maxlength="500" style="max-width:320px">
                <button type="submit" class="ghost">Zdecyduj</button></form>
        @endif
        @if (in_array($state, [S::Draft, S::Validated, S::Ready, S::Blocked], true))
            <form method="post" action="{{ route('poland.ksef.submissions.cancel', ['submission' => $s->getKey()]) }}" class="inline">@csrf<input name="reason" placeholder="powód anulowania" required maxlength="500" style="max-width:260px"><button type="submit" class="ghost">Anuluj</button></form>
        @endif
    </div>
</div>

@if ($s->document && !$s->document->isValid())
<div class="card"><h2>Błędy walidacji XSD</h2><pre>{{ implode("\n", $s->document->validation_errors ?? []) }}</pre></div>
@endif

<div class="card">
    <h2>Historia stanów (append-only)</h2>
    <table><thead><tr><th>Kiedy</th><th>Z</th><th>Do</th><th>Źródło</th><th>Kod KSeF</th><th>Opis</th><th>Kto</th></tr></thead><tbody>
    @foreach ($s->events as $e)
        <tr><td class="basis">{{ $e->occurred_at?->format('d.m.Y H:i:s') }}</td><td>{{ $e->from_state ?? '—' }}</td><td><span class="pill {{ $pill($e->to_state) }}">{{ $e->to_state }}</span></td><td>{{ $e->source }}</td><td>{{ $e->ksef_code ?? '—' }}</td><td>{{ $e->ksef_description }}</td><td class="basis">{{ $e->actor }}</td></tr>
    @endforeach
    </tbody></table>
</div>

@if ($errors_for->isNotEmpty())
<div class="card"><h2>Błędy tego zgłoszenia</h2>
    <table><thead><tr><th>Kiedy</th><th>Operacja</th><th>Kategoria</th><th>HTTP / kod</th><th>Komunikat</th></tr></thead><tbody>
    @foreach ($errors_for as $e)<tr><td class="basis">{{ $e->occurred_at?->format('d.m.Y H:i:s') }}</td><td><code>{{ $e->operation }}</code></td><td>{{ $e->category }}@if(!$e->outcome_known) · WYNIK NIEZNANY@endif</td><td>{{ $e->http_status ?? '—' }} / {{ $e->ksef_code ?? '—' }}</td><td>{{ $e->message }}</td></tr>@endforeach
    </tbody></table>
</div>
@endif

<div class="card"><details><summary>Podgląd XML FA(3)</summary><pre>{{ $s->document?->xml ?? '—' }}</pre></details></div>
@endsection
