@php
    use Poland\Ksef\Parsing\ParsedInvoice;
    use Poland\Purchases\ApprovalStatus;
    $parsed = $review['parsed'] ?? null;
    $plan = $review['plan'] ?? null;
    $assessment = $review['assessment'] ?? null;
    $resolutions = $review['resolutions'] ?? [];
    $display = $parsed?->displayAll() ?? [];
    $canDecide = $document->approvalStatus() === ApprovalStatus::AwaitingReview;
@endphp
@include('poland::partials.head', ['title' => 'Faktura '.($document->invoice_number ?? $document->ksef_number), 'active' => 'inbox', 'profile' => $profile])

    <h1>{{ $document->seller_name ?? $display['seller_name'] ?? ParsedInvoice::MISSING }} — {{ $document->invoice_number ?? ParsedInvoice::MISSING }}</h1>
    <p class="sub">
        KSeF {{ $document->ksef_number }} · otrzymana {{ $document->receivedAt()->format('d.m.Y H:i') }}
        · status: <strong>{{ $document->approvalStatus()->label() }}</strong>
        @if($document->approved_by) ({{ $document->approved_by }}, {{ $document->approved_at?->format('d.m.Y H:i') }})@endif
    </p>

    @if (session('status'))<div class="good">{{ session('status') }}</div>@endif
    @foreach (($errors ?? collect())->all() ?? [] as $message)
        <div class="bad">{{ $message }}</div>
    @endforeach
    @if ($error)<div class="warn">{{ $error }}</div>@endif

    {{-- WHAT APPROVING WILL DO — the sentence the owner reads before tapping. --}}
    @if ($plan && $assessment)
        <div class="card">
            <h2>Co zrobi zatwierdzenie</h2>
            <div class="willdo {{ $assessment->canApprove() ? '' : 'blocked' }}">
                @if ($assessment->canApprove())
                    {{ $plan->describe() }}
                @else
                    <strong>Nie można zatwierdzić:</strong>
                    <ul style="margin:8px 0 0 18px; padding:0">
                        @foreach ($assessment->blockers as $blocker)<li>{{ $blocker }}</li>@endforeach
                    </ul>
                @endif
            </div>
            @foreach ($assessment->notes as $note)
                <div class="basis" style="margin-top:6px">· {{ $note }}</div>
            @endforeach
            @if ($plan->deductionWindowClosed(\Poland\Domain\Period::of((int) date('Y'), (int) date('n'))))
                <div class="warn" style="margin-top:10px">Okno odliczenia VAT dla tej faktury minęło ({{ $plan->deductionWindowEnd()->toString() }}). Odliczenie wymaga korekty deklaracji — decyzja księgowego.</div>
            @endif

            @if ($canDecide)
                <form method="GET" action="{{ route('poland.inbox.show', ['document' => $document->getKey()]) }}" class="inline" style="margin-top:14px">
                    <div>
                        <label for="vat_period">Okres odliczenia VAT (od {{ $plan->receiptPeriod->toString() }} do {{ $plan->deductionWindowEnd()->toString() }})</label>
                        <input type="month" id="vat_period" name="vat_period" value="{{ $chosenVatPeriod }}">
                    </div>
                    <div>
                        <label for="cost_category">Kategoria kosztu (KPiR)</label>
                        <select id="cost_category" name="cost_category">
                            @foreach ($categories as $category)
                                <option value="{{ $category->value }}" {{ $chosenCategory === $category->value ? 'selected' : '' }}>{{ $category->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="deductible_share">Odliczenie (%)</label>
                        <input type="number" id="deductible_share" name="deductible_share" min="0" max="100" value="{{ $chosenShare }}" style="width:5em">
                    </div>
                    <button type="submit" class="ghost">Przelicz</button>
                </form>

                <div class="actions" style="margin-top:16px">
                    @if ($assessment->canApprove())
                        <form method="POST" action="{{ route('poland.inbox.approve', ['document' => $document->getKey()]) }}" class="inline">
                            @csrf
                            <input type="hidden" name="vat_period" value="{{ $chosenVatPeriod }}">
                            <input type="hidden" name="cost_category" value="{{ $chosenCategory }}">
                            <input type="hidden" name="deductible_share" value="{{ $chosenShare }}">
                            <div><label for="note">Uwaga (opcjonalnie)</label><input type="text" id="note" name="note" maxlength="500"></div>
                            <button type="submit" class="approve">Zatwierdź i zaksięguj</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('poland.inbox.reject', ['document' => $document->getKey()]) }}" class="inline">
                        @csrf
                        <div><label for="reason">Przyczyna odrzucenia</label><input type="text" id="reason" name="reason" required maxlength="500" placeholder="np. nie nasza faktura"></div>
                        <button type="submit" class="ghost">Odrzuć</button>
                    </form>
                </div>
            @endif
        </div>
    @endif

    @if ($document->hasUndecidedDuplicate())
        <div class="card">
            <h2>Możliwy duplikat</h2>
            <div class="bad">{{ $document->duplicate_reason }}</div>
            <form method="POST" action="{{ route('poland.inbox.duplicate', ['document' => $document->getKey()]) }}" class="inline">
                @csrf
                <button type="submit" name="decision" value="distinct" class="ghost">To osobna faktura</button>
                <button type="submit" name="decision" value="duplicate">To duplikat — odrzuć</button>
            </form>
        </div>
    @endif

    @if ($document->isCorrection())
        <div class="card">
            <h2>Faktura korygująca</h2>
            @if ($document->correctedDocument)
                Koryguje: <a href="{{ route('poland.inbox.show', ['document' => $document->corrects_document_id]) }}">{{ $document->correctedDocument->invoice_number ?? $document->correctedDocument->ksef_number }}</a>
                ({{ $document->correctedDocument->approvalStatus()->label() }}).
                Kwoty korekty to RÓŻNICE i księgują się jako podpisana korekta powiązana z oryginałem; oryginał pozostaje bez zmian.
            @else
                <div class="warn">Nie powiązano z fakturą pierwotną. @if($parsed?->correction)Dokument wskazuje: {{ $parsed->correction->originalInvoiceNumber ?? '—' }} / KSeF {{ $parsed->correction->originalKsefNumber ?? '—' }}.@endif</div>
            @endif
            @if ($parsed?->correction?->reason)<div class="basis">Przyczyna: {{ $parsed->correction->reason }}</div>@endif
        </div>
    @endif

    <div class="card">
        <h2>Nagłówek (z dokumentu — MISSING_FIELD oznacza brak w dokumencie, nie zero)</h2>
        <div class="tableWrap">
        <table>
            <tbody>
                <tr><th>Sprzedawca</th><td>{{ $display['seller_name'] ?? '' }} · NIP {{ $display['seller_nip'] ?? '' }}</td><th>Nabywca</th><td>{{ $display['buyer_name'] ?? '' }} · NIP {{ $display['buyer_nip'] ?? '' }}</td></tr>
                <tr><th>Data wystawienia</th><td>{{ $display['invoice_date'] ?? '' }}</td><th>Data sprzedaży</th><td>{{ $display['sale_date'] ?? '' }}@if($parsed?->servicePeriodFrom) (okres {{ $parsed->servicePeriodFrom->format('Y-m-d') }} – {{ $parsed->servicePeriodTo?->format('Y-m-d') }})@endif</td></tr>
                <tr><th>Netto</th><td class="num">{{ $display['net'] ?? '' }}</td><th>VAT</th><td class="num">{{ $display['vat'] ?? '' }}</td></tr>
                <tr><th>Brutto</th><td class="num">{{ $display['gross'] ?? '' }}</td><th>Rodzaj / waluta</th><td>{{ $display['invoice_type'] ?? '' }} / {{ $display['currency'] ?? '' }}</td></tr>
                @php $pt = $parsed?->paymentTerms(); @endphp
                <tr><th>Płatność</th><td colspan="3">
                    @if ($pt && $pt->isPresent())
                        termin {{ $pt->dueDate?->format('d.m.Y') ?? ParsedInvoice::MISSING }} · forma {{ $pt->paymentFormLabel() ?? ParsedInvoice::MISSING }}
                        · zapłacono przy wystawieniu: {{ $pt->paidOnInvoice === null ? 'nie podano' : ($pt->paidOnInvoice ? 'tak' : 'nie') }}
                        @if($pt->supplierAccount) · rachunek {{ $pt->supplierAccount }}@endif
                    @else
                        brak węzła Platnosc w dokumencie (nie znaczy „niezapłacona”)
                    @endif
                </td></tr>
                <tr><th>Sumy nagłówek vs pozycje</th><td colspan="3">
                    @php $lta = $parsed?->lineTotalsAgree(); @endphp
                    {{ $lta === null ? 'brak pozycji do porównania' : ($lta ? 'zgodne' : 'NIEZGODNE — wymaga wyjaśnienia') }}
                    · nagłówek netto+VAT vs brutto: {{ $parsed?->totalsAgree() === null ? 'nie można sprawdzić' : ($parsed->totalsAgree() ? 'zgodne' : 'NIEZGODNE') }}
                </td></tr>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card">
        <h2>Pozycje</h2>
        @if ($resolutions === [])
            <p class="basis">Dokument nie zawiera pozycji (FaWiersz). Księgowanie z sum nagłówka; bez ruchu magazynowego.</p>
        @else
            <div class="tableWrap">
            <table>
                <thead><tr><th>#</th><th>Opis (wg dostawcy)</th><th class="num">Ilość</th><th class="num">Netto</th><th>VAT</th><th>Produkt</th><th>Magazyn</th></tr></thead>
                <tbody>
                @foreach ($resolutions as $resolution)
                    @php $line = $resolution->line; $j = $line->jsonSerialize(); @endphp
                    <tr>
                        <td>{{ $line->lineNo }}</td>
                        <td>{{ $j['description'] }}@if($line->supplierIndex)<div class="basis">indeks {{ $line->supplierIndex }}</div>@endif</td>
                        <td class="num">{{ $j['quantity'] }} {{ $line->unit ?? '' }}</td>
                        <td class="num">{{ $j['net'] }}</td>
                        <td>{{ $j['vat_rate'] }}{{ $line->vatRateIsKnown() ? '' : ' (nieznana)' }} → {{ $j['vat'] }}{{ $line->vatIsDerived ? ' (wyliczony)' : '' }}</td>
                        <td>
                            @if ($resolution->isMapped())
                                {{ $resolution->mapping->name }}
                                <div class="basis">{{ $resolution->mappingSource === 'alias' ? 'z aliasu dostawcy' : 'ustawione ręcznie' }}@if($resolution->mapping->packSize) · 1 {{ $resolution->mapping->packUnit ?? 'op.' }} = {{ rtrim(rtrim(number_format($resolution->mapping->packSize, 3, ',', ' '), '0'), ',') }} {{ $resolution->mapping->stockUnit->label() }}@endif</div>
                            @else
                                <span class="pill muted">bez produktu — tylko księgowanie</span>
                            @endif
                            @if ($canDecide)
                                <form method="POST" action="{{ route('poland.inbox.map', ['document' => $document->getKey(), 'line' => $line->lineNo]) }}" class="inline" style="margin-top:6px">
                                    @csrf
                                    <select name="product_id" required>
                                        <option value="">— przypisz produkt —</option>
                                        @foreach ($products as $product)
                                            <option value="{{ $product->getKey() }}">{{ $product->name }} ({{ $product->stockUnit()->label() }}{{ $product->inventory_tracked ? ', śledzony' : '' }})</option>
                                        @endforeach
                                    </select>
                                    <input type="text" name="pack_size" inputmode="decimal" placeholder="szt w 1 {{ $line->unit ?? 'op.' }}" style="width:9em">
                                    <button type="submit" class="ghost">Zapamiętaj</button>
                                </form>
                            @endif
                        </td>
                        <td>
                            @if ($resolution->isTracked())<span class="pill paid">TRACK</span>@elseif($resolution->isMapped())<span class="pill muted">nie śledzony</span>@else —@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    <details class="card">
        <summary>Oryginalny XML (niezmienny, SHA-256 {{ $document->xml_checksum }})</summary>
        <pre style="white-space:pre-wrap; font-size:.78rem; margin-top:10px">{{ $document->original_xml }}</pre>
    </details>

@include('poland::partials.foot')
