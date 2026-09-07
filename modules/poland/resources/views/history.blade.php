<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Historia raportów — {{ $period->label() }}</title>
    <style>
        :root { color-scheme:light dark; --bg:#f5f6f8; --card:#fff; --ink:#14171c;
                --muted:#5b6472; --line:#dfe3e9; --ok:#15803d; }
        @media (prefers-color-scheme: dark) { :root {
            --bg:#0f1216; --card:#171b21; --ink:#e9ebef; --muted:#9aa4b2;
            --line:#262b33; --ok:#86efac; } }
        body { margin:0; background:var(--bg); color:var(--ink);
               font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif; }
        .wrap { max-width:900px; margin:0 auto; padding:22px 16px 60px; }
        .card { background:var(--card); border:1px solid var(--line);
                border-radius:10px; padding:18px 20px; }
        table { width:100%; border-collapse:collapse; font-size:.9rem; }
        th,td { text-align:left; padding:8px 6px; border-bottom:1px solid var(--line); }
        td.num,th.num { text-align:right; font-variant-numeric:tabular-nums; }
        th { color:var(--muted); font-size:.76rem; text-transform:uppercase; }
        .basis { color:var(--muted); font-size:.78rem; }
        code { font-size:.78rem; color:var(--muted); }
    </style>
</head>
<body>
<div class="wrap">
    <h1 style="font-size:1.4rem;margin:0 0 4px">Historia raportów — {{ $period->label() }}</h1>
    <p class="basis" style="margin:0 0 18px">
        {{ $profile->name }} · Każde przeliczenie tworzy nową wersję.
        Poprzednie wersje nigdy nie znikają ani się nie zmieniają.
    </p>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Wersja</th><th>Wygenerowano</th><th>Przez</th>
                    <th class="num">Razem</th><th>Stan</th><th>Suma kontrolna</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($versions as $v)
                <tr>
                    <td><strong>{{ $v->version }}</strong></td>
                    <td>{{ $v->generated_at?->format('d.m.Y H:i') }}</td>
                    <td>{{ $v->generated_by }}</td>
                    <td class="num">{{ number_format((float) $v->total_due, 2, ',', ' ') }} zł</td>
                    <td>
                        @if ($v->is_closed)
                            <span style="color:var(--ok)">zamknięty {{ $v->closed_at?->format('d.m.Y') }}</span>
                        @else
                            otwarty
                        @endif
                        @if ($v->is_estimate)<div class="basis">szacunek</div>@endif
                        @unless ($v->rates_fit_for_filing)
                            <div class="basis">stawki niezweryfikowane</div>
                        @endunless
                        @if ($v->reopen_reason)
                            <div class="basis">otwarty ponownie: {{ $v->reopen_reason }}</div>
                        @endif
                    </td>
                    <td>
                        <code>{{ substr($v->checksum, 0, 12) }}…</code>
                        <div class="basis">{{ $v->verifyChecksum() ? 'zgodna' : 'NIEZGODNA' }}</div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="basis">Nie wygenerowano jeszcze żadnego raportu za ten miesiąc.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <p class="basis" style="text-align:center;margin-top:18px">
        <a href="{{ route('poland.dashboard', ['period' => $period->toString()]) }}">← Pulpit</a>
    </p>
</div>
</body>
</html>
