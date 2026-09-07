<?php

declare(strict_types=1);

namespace Poland\Government\Contracts;

/**
 * Gets text out of an uploaded document. A port: pdftotext, an OCR engine or a
 * hosted service are all implementations, and the inbox depends on none of them.
 */
interface TextExtractor
{
    public function isAvailable(): bool;

    /** @return array{text: string, method: string, confidence: float|null} */
    public function extract(string $binary, string $filename): array;
}
