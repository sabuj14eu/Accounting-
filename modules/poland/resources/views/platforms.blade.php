@php
    use Poland\Domain\Money;
    use Poland\Platforms\Platform;
    use Poland\Platforms\SettlementStatus;
    use Poland\Platforms\VatTreatment;
@endphp
@include('poland::partials.head', ['title' => 'Glovo / platformy', 'active' => 'platforms', 'profile' => $profile])

    <h1>Glovo / platformy</h1>
    <p class="sub">
        Rozliczenie platformy jest źródłem księgowym: zamówienia brutto to sprzedaż sklepu (dopisywana raz do raportu
        miesięcznego na kanale platformy), prowizja to koszt, a wypłata jest uzgadniana z wyciągiem.
    </p>

    @if (session('status'))<div class="good">{{ session('status') }}</div>@endif
    @foreach (($errors ?? collect())->all() ?? [] as $message)
        <div class="bad">{{ $message }}</div>
    @endforeach

    <div class="card">
        <h2>Zapisane miesiące</h2>
        @if ($settlements->isEmpty())
            <p class="basis">Nic jeszcze nie zapisano.</p>
        @else
            <div class="tableWrap">
            <table>
                <thead><tr><th>Miesiąc</th><th>Platforma</th><th class="num">Zamówienia brutto</th><th class="num">Prowizja brutto</th><th class="num">Oczekiwana wypłata</th><th class="num">Otrzymano</th><th>Status</th><th>VAT prowizji</th></tr></thead>
                <tbody>
                @foreach ($settlements as $row)
                    @php $status = SettlementStatus::from($row->status); $treatment = VatTreatment::from($row->vat_treatment); @endphp
                    <tr>
                        <td>{{ $row->period }}<div class="basis">v{{ $row->version }} · {{ $row->recorded_by }}</div></td>
                        <td>{{ Platform::from($row->platform)->label() }}</td>
                        <td class="num">{{ Money::parse((string) $row->gross_orders)->format() }}@if($row->gross_orders_by_rate === null)<div class="basis">bez podziału na stawki — NIE w raporcie sprzedaży</div>@endif</td>
                        <td class="num">{{ Money::parse((string) $row->commission_net)->plus(Money::parse((string) $row->commission_vat))->format() }}</td>
                        <td class="num">{{ Money::parse((string) $row->payout_expected)->format() }}</td>
                        <td class="num">{{ $row->payout_received !== null ? Money::parse((string) $row->payout_received)->format() : '—' }}</td>
                        <td><span class="pill {{ $status === SettlementStatus::Reconciled ? 'paid' : 'unpaid' }}">{{ $status->label() }}</span>
                            @if($row->difference !== null && $status !== SettlementStatus::Reconciled)<div class="basis">różnica {{ Money::parse((string) $row->difference)->format() }}</div>@endif</td>
                        <td><span class="pill {{ $treatment->isDecided() ? 'muted' : 'warn' }}">{{ $treatment->isDecided() ? $treatment->value : 'NIEUSTALONE' }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    <div class="card">
        <h2>Zapisz miesiąc</h2>
        <form method="POST" action="{{ route('poland.platforms.store') }}" class="inline">
            @csrf
            <div>
                <label for="platform">Platforma</label>
                <select id="platform" name="platform">
                    @foreach ($platforms as $platform)<option value="{{ $platform->value }}">{{ $platform->label() }}</option>@endforeach
                </select>
            </div>
            <div><label for="period">Miesiąc</label><input type="month" id="period" name="period" value="{{ old('period', $defaultPeriod) }}" required></div>
            <div><label for="gross_orders">Zamówienia brutto (razem)</label><input type="text" id="gross_orders" name="gross_orders" inputmode="decimal" placeholder="12 000,00" value="{{ old('gross_orders') }}" required></div>
            <div><label for="gross_08">w tym 8%</label><input type="text" id="gross_08" name="gross_08" inputmode="decimal" value="{{ old('gross_08') }}" style="width:8em"></div>
            <div><label for="gross_23">w tym 23%</label><input type="text" id="gross_23" name="gross_23" inputmode="decimal" value="{{ old('gross_23') }}" style="width:8em"></div>
            <div><label for="gross_05">w tym 5%</label><input type="text" id="gross_05" name="gross_05" inputmode="decimal" value="{{ old('gross_05') }}" style="width:8em"></div>
            <div><label for="gross_zw">w tym zw</label><input type="text" id="gross_zw" name="gross_zw" inputmode="decimal" value="{{ old('gross_zw') }}" style="width:8em"></div>
            <div><label for="commission_net">Prowizja netto</label><input type="text" id="commission_net" name="commission_net" inputmode="decimal" placeholder="3 600,00" value="{{ old('commission_net') }}" required></div>
            <div><label for="commission_vat">VAT od prowizji</label><input type="text" id="commission_vat" name="commission_vat" inputmode="decimal" placeholder="828,00" value="{{ old('commission_vat') }}" required></div>
            <div><label for="deduction_name">Inne potrącenie — nazwa</label><input type="text" id="deduction_name" name="deduction_name" placeholder="marketing" value="{{ old('deduction_name') }}"></div>
            <div><label for="deduction_amount">— kwota</label><input type="text" id="deduction_amount" name="deduction_amount" inputmode="decimal" value="{{ old('deduction_amount') }}" style="width:8em"></div>
            <div><label for="payout_received">Otrzymano na konto (jeśli już)</label><input type="text" id="payout_received" name="payout_received" inputmode="decimal" value="{{ old('payout_received') }}"></div>
            <div>
                <label for="vat_treatment">Traktowanie VAT prowizji (decyzja z księgowym)</label>
                <select id="vat_treatment" name="vat_treatment">
                    @foreach ($treatments as $treatment)
                        <option value="{{ $treatment->value }}" {{ old('vat_treatment', 'unknown') === $treatment->value ? 'selected' : '' }}>{{ $treatment->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div><label for="statement_from">Zestawienie od</label><input type="date" id="statement_from" name="statement_from" value="{{ old('statement_from') }}"></div>
            <div><label for="statement_to">do</label><input type="date" id="statement_to" name="statement_to" value="{{ old('statement_to') }}"></div>
            <div><label for="correction_reason">Przyczyna korekty (jeśli miesiąc już zapisany)</label><input type="text" id="correction_reason" name="correction_reason" value="{{ old('correction_reason') }}"></div>
            <button type="submit">Zapisz</button>
        </form>
        <p class="basis" style="margin-top:10px">
            Bez podziału na stawki system NIE zakłada stawki dla całej kwoty i nie dopisze sprzedaży do raportu.
            Traktowanie VAT „nieustalone” zapisuje i uzgadnia z bankiem, ale nie wchodzi do deklaracji.
        </p>
    </div>

@include('poland::partials.foot')
