<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'KSeF') — SignalMesh Accounts</title>
    <style>
        :root { color-scheme: light dark;
            --bg:#f5f6f8; --card:#fff; --ink:#14171c; --muted:#5b6472; --line:#dfe3e9;
            --pay:#b91c1c; --pay-bg:#fef2f2; --ok:#15803d; --ok-bg:#f0fdf4;
            --warn:#92400e; --warn-bg:#fffbeb; --alarm:#7f1d1d; --alarm-bg:#fee2e2; --info:#1d4ed8; --info-bg:#eff6ff; }
        @media (prefers-color-scheme: dark) { :root {
            --bg:#0f1216; --card:#171b21; --ink:#e9ebef; --muted:#9aa4b2; --line:#262b33;
            --pay:#fca5a5; --pay-bg:#2b1616; --ok:#86efac; --ok-bg:#132318;
            --warn:#fcd34d; --warn-bg:#2a2113; --alarm:#fecaca; --alarm-bg:#3b1414; --info:#93c5fd; --info-bg:#12203a; } }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--ink); font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif; }
        .wrap { max-width:1040px; margin:0 auto; padding:22px 16px 70px; }
        h1 { font-size:1.5rem; margin:0 0 2px; }
        .sub { color:var(--muted); margin:0 0 20px; font-size:.92rem; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:18px 20px; margin-bottom:16px; }
        h2 { font-size:.82rem; margin:0 0 14px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); }
        .alarm { background:var(--alarm-bg); border:2px solid var(--alarm); color:var(--alarm); border-radius:8px; padding:12px 14px; margin-bottom:16px; font-weight:700; }
        .warn { background:var(--warn-bg); border:1px solid var(--warn); color:var(--warn); border-radius:8px; padding:11px 14px; margin-bottom:10px; font-size:.9rem; }
        .good { background:var(--ok-bg); border:1px solid var(--ok); color:var(--ok); border-radius:8px; padding:11px 14px; margin-bottom:10px; font-size:.9rem; }
        .bad  { background:var(--pay-bg); border:1px solid var(--pay); color:var(--pay); border-radius:8px; padding:11px 14px; margin-bottom:10px; font-size:.9rem; }
        .info { background:var(--info-bg); border:1px solid var(--info); color:var(--info); border-radius:8px; padding:11px 14px; margin-bottom:10px; font-size:.9rem; }
        .row { display:flex; justify-content:space-between; align-items:baseline; gap:16px; padding:11px 0; border-bottom:1px solid var(--line); }
        .row:last-of-type { border-bottom:0; }
        .row .what { font-weight:600; } .row .meta { color:var(--muted); font-size:.84rem; font-weight:400; margin-top:2px; }
        .big { font-size:1.6rem; font-weight:800; letter-spacing:.02em; }
        table { width:100%; border-collapse:collapse; font-size:.9rem; }
        th,td { text-align:left; padding:8px 6px; border-bottom:1px solid var(--line); vertical-align:top; }
        th { color:var(--muted); font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; }
        td.num, th.num { text-align:right; font-variant-numeric:tabular-nums; }
        .pill { display:inline-block; padding:2px 9px; border-radius:99px; font-size:.74rem; font-weight:700; white-space:nowrap; }
        .pill.ok { background:var(--ok-bg); color:var(--ok); } .pill.bad { background:var(--pay-bg); color:var(--pay); }
        .pill.warn { background:var(--warn-bg); color:var(--warn); } .pill.muted { background:var(--line); color:var(--muted); } .pill.info { background:var(--info-bg); color:var(--info); }
        form.inline { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
        label { display:block; font-size:.78rem; color:var(--muted); margin-bottom:4px; }
        input,select,textarea { padding:8px 10px; border:1px solid var(--line); border-radius:6px; background:var(--card); color:var(--ink); font:inherit; width:100%; max-width:520px; }
        input[type=checkbox] { width:auto; }
        .field { margin-bottom:12px; }
        button { padding:9px 16px; border:0; border-radius:6px; background:var(--ink); color:var(--bg); font:inherit; font-weight:600; cursor:pointer; }
        button.ghost { background:transparent; color:var(--ink); border:1px solid var(--line); }
        button.danger { background:var(--pay); color:#fff; }
        a { color:inherit; }
        .basis { color:var(--muted); font-size:.78rem; }
        .actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:8px; align-items:center; }
        .steps { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px; }
        .steps a, .steps span { padding:6px 10px; border-radius:6px; border:1px solid var(--line); font-size:.82rem; text-decoration:none; }
        .steps .current { background:var(--ink); color:var(--bg); border-color:var(--ink); }
        .steps .done { border-color:var(--ok); color:var(--ok); }
        pre { background:var(--bg); border:1px solid var(--line); border-radius:8px; padding:12px; overflow:auto; font-size:.8rem; max-height:420px; }
        code { font-size:.86em; }
        nav.top { display:flex; gap:14px; flex-wrap:wrap; font-size:.88rem; margin-bottom:18px; }
        details summary { cursor:pointer; color:var(--muted); font-size:.9rem; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; }
        .kv { font-size:.9rem; } .kv b { display:block; color:var(--muted); font-size:.74rem; text-transform:uppercase; letter-spacing:.04em; }
    </style>
</head>
<body>
<div class="wrap">
    <nav class="top">
        <a href="{{ route('poland.dashboard') }}">← Rozliczenie</a>
        <a href="{{ route('poland.ksef.status') }}">KSeF: stan</a>
        <a href="{{ route('poland.ksef.wizard') }}">Konfiguracja</a>
        <a href="{{ route('poland.ksef.submissions') }}">Faktury wychodzące</a>
    </nav>

    @if (session('status'))<div class="good">{{ session('status') }}</div>@endif
    @if (session('ksef_failed'))<div class="bad">{{ session('ksef_failed') }}</div>@endif
    @foreach ((isset($errors) ? $errors->all() : []) as $message)
        <div class="bad">{{ $message }}</div>
    @endforeach

    @yield('content')

    <p class="basis" style="margin-top:28px">
        Każda liczba na tej stronie ma źródło i znacznik czasu. „Wysłana” nie znaczy „przyjęta”; „skonfigurowana” nie znaczy „połączona”;
        pusta odpowiedź KSeF nie znaczy „brak faktur”. Wysyłka do organów (JPK, PIT, ZUS) pozostaje WYŁĄCZONA.
    </p>
</div>
</body>
</html>
