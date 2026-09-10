@include('poland::partials.head', ['title' => 'Faktury do przeglądu', 'active' => 'inbox', 'profile' => $profile])

    <h1>Faktury do przeglądu</h1>
    <p class="sub">
        {{ $profile?->name ?? 'Brak profilu podatnika' }}@if($profile?->nip) · NIP {{ $profile->nip }}@endif
        · Każda faktura przychodząca z KSeF trafia tutaj. Nic nie jest księgowane bez zatwierdzenia.
    </p>

    @if (session('status'))<div class="good">{{ session('status') }}</div>@endif
    @foreach (($errors ?? collect())->all() ?? [] as $message)
        <div class="bad">{{ $message }}</div>
    @endforeach

    @php
        $transportOn = (bool) config('poland.ksef.transport_enabled', false) && (string) config('poland.ksef.transport', 'disabled') === 'real';
    @endphp
    @unless ($transportOn)
        <div class="warn">
            <strong>KSeF: NOT CONNECTED.</strong> Transport HTTP do KSeF nie jest włączony, więc faktury nie
            przychodzą same. Pusta skrzynka NIE znaczy, że nie ma faktur — znaczy, że system ich nie widzi.
            Dokumenty spoza KSeF (paragony, faktury uproszczone do 450 zł, dostawcy zagraniczni) nigdy tu nie trafią.
        </div>
    @endunless

    @foreach ($conflicts as $conflict)
        <div class="bad">
            {{ $conflict->reason }}
            <form method="POST" action="{{ route('poland.inbox.supersede', ['period' => $conflict->period->toString()]) }}" style="margin-top:8px">
                @csrf
                <button type="submit" class="ghost">Użyj zaksięgowanych faktur za {{ $conflict->period->toString() }} (oznacz ręczną sumę jako zastąpioną)</button>
            </form>
        </div>
    @endforeach

    <div class="card">
        <h2>Czekają na decyzję ({{ $awaiting->count() }})</h2>
        @if ($awaiting->isEmpty())
            <p class="basis">Nic do przeglądu.</p>
        @else
            <div class="tableWrap">
            <table>
                <thead><tr><th>Dostawca</th><th>Faktura</th><th>Data</th><th class="num">Brutto</th><th>Uwagi</th><th></th></tr></thead>
                <tbody>
                @foreach ($awaiting as $doc)
                    <tr>
                        <td>{{ $doc->seller_name ?? '—' }}<div class="basis">NIP {{ $doc->seller_nip ?? \Poland\Ksef\Parsing\ParsedInvoice::MISSING }}</div></td>
                        <td>{{ $doc->invoice_number ?? \Poland\Ksef\Parsing\ParsedInvoice::MISSING }}<div class="basis">{{ $doc->ksef_number }}</div></td>
                        <td>{{ $doc->invoice_date?->format('d.m.Y') ?? \Poland\Ksef\Parsing\ParsedInvoice::MISSING }}
                            @if($doc->due_date)<div class="basis">termin {{ $doc->due_date->format('d.m.Y') }}</div>@endif</td>
                        <td class="num">{{ $doc->gross !== null ? \Poland\Domain\Money::parse((string) $doc->gross)->format() : \Poland\Ksef\Parsing\ParsedInvoice::MISSING }}</td>
                        <td>
                            @if ($doc->isCorrection())<span class="pill warn">KOREKTA</span>@endif
                            @if ($doc->hasUndecidedDuplicate())<span class="pill unpaid">MOŻLIWY DUPLIKAT</span>@endif
                            @if ($doc->needs_review)<div class="basis">{{ $doc->review_reason }}</div>@endif
                        </td>
                        <td><a href="{{ route('poland.inbox.show', ['document' => $doc->getKey()]) }}"><button type="button">Otwórz</button></a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    <div class="card">
        <h2>Ostatnie decyzje</h2>
        @if ($recent->isEmpty())
            <p class="basis">Jeszcze nic nie zatwierdzono ani nie odrzucono.</p>
        @else
            <div class="tableWrap">
            <table>
                <thead><tr><th>Dostawca</th><th>Faktura</th><th class="num">Brutto</th><th>Status</th><th>Kto / kiedy</th></tr></thead>
                <tbody>
                @foreach ($recent as $doc)
                    <tr>
                        <td>{{ $doc->seller_name ?? $doc->seller_nip ?? '—' }}</td>
                        <td><a href="{{ route('poland.inbox.show', ['document' => $doc->getKey()]) }}">{{ $doc->invoice_number ?? $doc->ksef_number }}</a></td>
                        <td class="num">{{ $doc->gross !== null ? \Poland\Domain\Money::parse((string) $doc->gross)->format() : '—' }}</td>
                        <td><span class="pill {{ $doc->approvalStatus() === \Poland\Purchases\ApprovalStatus::Posted ? 'paid' : 'muted' }}">{{ $doc->approvalStatus()->label() }}</span></td>
                        <td class="basis">{{ $doc->approved_by }} · {{ $doc->approved_at?->format('d.m.Y H:i') }}@if($doc->decision_note)<br>{{ $doc->decision_note }}@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

@include('poland::partials.foot')
