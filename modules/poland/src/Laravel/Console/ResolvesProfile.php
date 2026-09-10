<?php

declare(strict_types=1);

namespace Poland\Laravel\Console;

use Poland\Laravel\Models\TaxProfileModel;

/** `--profile=ID`, or the only profile there is. Shared by the KSeF commands. */
trait ResolvesProfile
{
    private function resolveProfile(): ?TaxProfileModel
    {
        if ($this->option('profile') !== null) {
            $profile = TaxProfileModel::find((int) $this->option('profile'));
            if ($profile === null) {
                $this->error('Nie znaleziono profilu podatnika o ID '.$this->option('profile').'.');
            }

            return $profile;
        }

        $profiles = TaxProfileModel::query()->limit(2)->get();
        if ($profiles->isEmpty()) {
            $this->error('Brak profilu podatnika. Utwórz go w panelu (Profil podatnika) i spróbuj ponownie.');

            return null;
        }
        if ($profiles->count() > 1) {
            $this->error('Istnieje więcej niż jeden profil podatnika — wskaż go przez --profile=ID.');

            return null;
        }

        return $profiles->first();
    }
}
