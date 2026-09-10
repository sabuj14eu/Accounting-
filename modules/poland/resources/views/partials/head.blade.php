<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Księgowość' }}</title>
    <style>
        :root { color-scheme: light dark;
            --bg:#f5f6f8; --card:#fff; --ink:#14171c; --muted:#5b6472; --line:#dfe3e9;
            --pay:#b91c1c; --pay-bg:#fef2f2; --ok:#15803d; --ok-bg:#f0fdf4;
            --warn:#92400e; --warn-bg:#fffbeb; --alarm:#7f1d1d; --alarm-bg:#fee2e2; }
        @media (prefers-color-scheme: dark) { :root {
            --bg:#0f1216; --card:#171b21; --ink:#e9ebef; --muted:#9aa4b2; --line:#262b33;
            --pay:#fca5a5; --pay-bg:#2b1616; --ok:#86efac; --ok-bg:#132318;
            --warn:#fcd34d; --warn-bg:#2a2113; --alarm:#fecaca; --alarm-bg:#3b1414; } }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--ink);
               font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif; }
        .wrap { max-width:980px; margin:0 auto; padding:22px 16px 70px; }
        h1 { font-size:1.5rem; margin:0 0 2px; }
        .sub { color:var(--muted); margin:0 0 20px; font-size:.92rem; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:10px;
                padding:18px 20px; margin-bottom:16px; }
        h2 { font-size:.82rem; margin:0 0 14px; text-transform:uppercase;
             letter-spacing:.06em; color:var(--muted); }
        .alarm { background:var(--alarm-bg); border:2px solid var(--alarm); color:var(--alarm);
                 border-radius:8px; padding:12px 14px; margin-bottom:16px; font-weight:700; text-align:center; }
        .warn { background:var(--warn-bg); border:1px solid var(--warn); color:var(--warn);
                border-radius:8px; padding:11px 14px; margin-bottom:10px; font-size:.9rem; }
        .good { background:var(--ok-bg); border:1px solid var(--ok); color:var(--ok);
                border-radius:8px; padding:11px 14px; margin-bottom:10px; font-size:.9rem; }
        .bad  { background:var(--pay-bg); border:1px solid var(--pay); color:var(--pay);
                border-radius:8px; padding:11px 14px; margin-bottom:10px; font-size:.9rem; }
        .row { display:flex; justify-content:space-between; align-items:baseline; gap:16px;
               padding:13px 0; border-bottom:1px solid var(--line); }
        .row:last-of-type { border-bottom:0; }
        .row .what { font-weight:600; }
        .row .meta { color:var(--muted); font-size:.84rem; font-weight:400; margin-top:2px; }
        .amt { font-size:1.28rem; font-weight:700; font-variant-numeric:tabular-nums; white-space:nowrap; }
        .amt.pay { color:var(--pay); } .amt.none { color:var(--ok); font-size:1rem; }
        table { width:100%; border-collapse:collapse; font-size:.9rem; }
        th,td { text-align:left; padding:8px 6px; border-bottom:1px solid var(--line); vertical-align:top; }
        th { color:var(--muted); font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; }
        td.num, th.num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
        .pill { display:inline-block; padding:2px 9px; border-radius:99px; font-size:.74rem; font-weight:700; }
        .pill.unpaid { background:var(--pay-bg); color:var(--pay); }
        .pill.paid { background:var(--ok-bg); color:var(--ok); }
        .pill.warn { background:var(--warn-bg); color:var(--warn); }
        .pill.muted { background:var(--line); color:var(--muted); }
        form.inline { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
        label { display:block; font-size:.78rem; color:var(--muted); margin-bottom:4px; }
        input,select,textarea { padding:8px 10px; border:1px solid var(--line); border-radius:6px;
                       background:var(--card); color:var(--ink); font:inherit; max-width:100%; }
        button { padding:9px 16px; border:0; border-radius:6px; background:var(--ink);
                 color:var(--bg); font:inherit; font-weight:600; cursor:pointer; }
        button.ghost { background:transparent; color:var(--ink); border:1px solid var(--line); }
        button.approve { background:var(--ok); color:#fff; font-size:1.05rem; padding:12px 22px; }
        a { color:inherit; }
        .basis { color:var(--muted); font-size:.78rem; }
        .actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:4px; align-items:center; }
        details summary { cursor:pointer; color:var(--muted); font-size:.9rem; }
        .nav { display:flex; gap:6px; flex-wrap:wrap; margin:0 0 18px; }
        .nav a { text-decoration:none; padding:6px 12px; border:1px solid var(--line); border-radius:99px;
                 font-size:.86rem; background:var(--card); }
        .nav a.on { background:var(--ink); color:var(--bg); border-color:var(--ink); }
        .nav .count { display:inline-block; min-width:1.4em; padding:0 6px; margin-left:6px; border-radius:99px;
                      background:var(--pay); color:#fff; font-size:.74rem; text-align:center; }
        .willdo { font-size:1.02rem; padding:14px; border-left:4px solid var(--ok); background:var(--ok-bg); border-radius:6px; }
        .willdo.blocked { border-left-color:var(--pay); background:var(--pay-bg); }
        .tableWrap { overflow-x:auto; }
    </style>
</head>
<body>
<div class="wrap">
@include('poland::partials.nav', ['active' => $active ?? ''])
