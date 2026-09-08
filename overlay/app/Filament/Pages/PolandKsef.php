<?php

declare(strict_types=1);

namespace App\Filament\Pages;

/**
 * Placed into the foundation by bin/install-foundation.sh so the admin panel
 * discovers the KSeF page. Everything lives in the Poland module; this file
 * is only the namespace the panel scans.
 */
final class PolandKsef extends \Poland\Laravel\Filament\KsefStatusPage
{
}
