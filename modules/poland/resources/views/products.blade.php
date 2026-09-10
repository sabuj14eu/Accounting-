@php use Poland\Inventory\StockPosition; @endphp
@include('poland::partials.head', ['title' => 'Produkty i magazyn', 'active' => 'products', 'profile' => $profile])

    <h1>Produkty i magazyn</h1>
    <p class="sub">
        Śledzenie stanu jest <strong>wyłączone domyślnie</strong>. Włącz je tylko dla produktów, które chcesz liczyć
        (np. napoje). Sprzedaż jest miesięczna, więc zużycie wynika wyłącznie z policzenia — system nie zgaduje stanu.
    </p>

    @if (session('status'))<div class="good">{{ session('status') }}</div>@endif
    @foreach (($errors ?? collect())->all() ?? [] as $message)
        <div class="bad">{{ $message }}</div>
    @endforeach

    <div class="card">
        <h2>Produkty ({{ $products->count() }})</h2>
        @if ($products->isEmpty())
            <p class="basis">Brak produktów. Dodaj pierwszy poniżej albo przypisz produkt z poziomu faktury.</p>
        @else
            <div class="tableWrap">
            <table>
                <thead><tr><th>Produkt</th><th>Kategoria</th><th>Jedn.</th><th>KPiR</th><th>Śledzenie</th><th>Stan</th><th>Inwentaryzacja</th></tr></thead>
                <tbody>
                @foreach ($products as $product)
                    @php $position = $positions[$product->getKey()] ?? null; @endphp
                    <tr>
                        <td>{{ $product->name }}<div class="basis">{{ $product->code }}</div></td>
                        <td>{{ $product->category ?? '—' }}</td>
                        <td>{{ $product->stockUnit()->label() }}</td>
                        <td class="basis">kol. {{ $product->costCategory()->kpirColumn() }}</td>
                        <td>
                            <form method="POST" action="{{ route('poland.products.tracking', ['product' => $product->getKey()]) }}" class="inline">
                                @csrf
                                @if ($product->inventory_tracked)
                                    <span class="pill paid">TRACK</span>
                                    <input type="hidden" name="tracked" value="0">
                                    <button type="submit" class="ghost">Wyłącz</button>
                                @else
                                    <span class="pill muted">nie śledzony</span>
                                    <input type="hidden" name="tracked" value="1">
                                    <input type="text" name="opening_count" inputmode="decimal" placeholder="stan początkowy ({{ $product->stockUnit()->label() }})" style="width:11em">
                                    <input type="date" name="counted_on" value="{{ date('Y-m-d') }}">
                                    <button type="submit" class="ghost">Włącz</button>
                                @endif
                            </form>
                        </td>
                        <td>
                            @if ($position)
                                @if ($position->status() === StockPosition::NO_OPENING_COUNT)
                                    <span class="pill warn">BRAK STANU POCZĄTKOWEGO</span>
                                    <div class="basis">zakupy od włączenia: {{ $position->purchases->format() }}</div>
                                @elseif ($position->status() === StockPosition::NOT_COUNTED)
                                    wg ksiąg <strong>{{ $position->bookQuantity()->format() }}</strong>
                                    <div class="basis">otwarcie {{ $position->openingCount->format() }} + zakupy {{ $position->purchases->format() }} · <span class="pill muted">NIE POLICZONO</span></div>
                                @else
                                    policzono <strong>{{ $position->physicalCount->format() }}</strong> ({{ $position->countedOn?->format('d.m.Y') }})
                                    <div class="basis">wg ksiąg {{ $position->bookQuantity()->format() }} · zużycie/różnica {{ $position->impliedConsumption()->format() }}</div>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if ($product->inventory_tracked)
                                <form method="POST" action="{{ route('poland.products.count', ['product' => $product->getKey()]) }}" class="inline">
                                    @csrf
                                    <input type="text" name="quantity" inputmode="decimal" placeholder="policzono" style="width:7em" required>
                                    <input type="date" name="counted_on" value="{{ date('Y-m-d') }}" required>
                                    <button type="submit" class="ghost">Zapisz</button>
                                </form>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    <div class="card">
        <h2>Dodaj produkt</h2>
        <form method="POST" action="{{ route('poland.products.store') }}" class="inline">
            @csrf
            <div><label for="code">Kod</label><input type="text" id="code" name="code" required maxlength="40" placeholder="COLA-05" value="{{ old('code') }}"></div>
            <div><label for="name">Nazwa</label><input type="text" id="name" name="name" required maxlength="200" placeholder="Coca-Cola 0,5 l" value="{{ old('name') }}"></div>
            <div><label for="category">Kategoria</label><input type="text" id="category" name="category" maxlength="40" placeholder="NAPOJE" value="{{ old('category') }}"></div>
            <div>
                <label for="stock_unit">Jednostka liczenia</label>
                <select id="stock_unit" name="stock_unit">
                    @foreach ($units as $unit)<option value="{{ $unit->value }}">{{ $unit->label() }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="cost_category">Kategoria kosztu</label>
                <select id="cost_category" name="cost_category">
                    @foreach ($categories as $category)<option value="{{ $category->value }}">{{ $category->label() }}</option>@endforeach
                </select>
            </div>
            <div><label for="tracked">Śledzić stan?</label><select id="tracked" name="tracked"><option value="0">nie (domyślnie)</option><option value="1">tak</option></select></div>
            <button type="submit">Dodaj</button>
        </form>
    </div>

@include('poland::partials.foot')
