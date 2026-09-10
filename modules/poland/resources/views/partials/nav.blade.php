@php
    $navProfile = $profile ?? null;
    $inboxCount = 0;
    if ($navProfile !== null) {
        try {
            $inboxCount = app(\Poland\Laravel\Support\InvoicePostingService::class)->awaitingReviewCount($navProfile);
        } catch (\Throwable) {
            $inboxCount = 0;
        }
    }
    $active = $active ?? '';
@endphp
<nav class="nav" aria-label="Sekcje">
    <a href="{{ route('poland.dashboard') }}" class="{{ $active === 'dashboard' ? 'on' : '' }}">Co muszę zapłacić</a>
    <a href="{{ route('poland.inbox') }}" class="{{ $active === 'inbox' ? 'on' : '' }}">Faktury do przeglądu @if($inboxCount > 0)<span class="count">{{ $inboxCount }}</span>@endif</a>
    <a href="{{ route('poland.platforms') }}" class="{{ $active === 'platforms' ? 'on' : '' }}">Glovo / platformy</a>
    <a href="{{ route('poland.products') }}" class="{{ $active === 'products' ? 'on' : '' }}">Produkty i magazyn</a>
</nav>
