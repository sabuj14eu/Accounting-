<?php

declare(strict_types=1);

namespace Poland\Laravel\Filament;

use Filament\Pages\Page;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Laravel\Support\Ksef\KsefConnectionService;

/**
 * The KSeF entry in the Filament navigation ("Tax & Compliance → KSeF").
 *
 * Panels discover pages from the application's own directories, so the
 * installer places two one-line subclasses of this page there (see
 * `overlay/app/Filament`). This class carries everything; the subclasses
 * carry nothing but a namespace.
 */
abstract class KsefStatusPage extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Tax & Compliance';

    protected static ?string $navigationLabel = 'KSeF';

    protected static ?string $title = 'KSeF — Krajowy System e-Faktur';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'poland-ksef';

    protected string $view = 'poland::filament.ksef-status';

    /** @return array<string,mixed> */
    protected function getViewData(): array
    {
        $profile = TaxProfileModel::query()->orderBy('id')->first();
        $connections = app(KsefConnectionService::class);

        return [
            'profile' => $profile,
            'health' => $profile !== null ? $connections->health($profile) : null,
            'overview' => $profile !== null ? $connections->overview($profile) : null,
            'statusUrl' => route('poland.ksef.status'),
            'configureUrl' => route('poland.ksef.wizard'),
            'invoicesUrl' => route('poland.ksef.submissions'),
        ];
    }
}
