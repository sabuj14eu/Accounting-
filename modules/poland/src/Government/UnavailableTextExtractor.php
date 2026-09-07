<?php

declare(strict_types=1);

namespace Poland\Government;

use Poland\Government\Contracts\TextExtractor;
use RuntimeException;

/**
 * The default extractor: one that refuses.
 *
 * No OCR toolchain is installed, so no text can be read from a scanned letter.
 * It throws rather than returning an empty string, because empty text would
 * classify as INFORMATION ONLY and a demand for payment would sit silently in
 * the inbox until the deadline passed.
 */
final class UnavailableTextExtractor implements TextExtractor
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function extract(string $binary, string $filename): array
    {
        throw new RuntimeException(
            'Brak zainstalowanego mechanizmu odczytu tekstu (pdftotext / OCR), więc treść '
            .'dokumentu "'.$filename.'" nie została odczytana. Dokument zapisano i oznaczono '
            .'jako DO PRZEGLĄDU RĘCZNEGO. Pusty tekst zostałby sklasyfikowany jako '
            .'"informacja", a wezwanie do zapłaty czekałoby w skrzynce do upływu terminu.',
        );
    }
}
