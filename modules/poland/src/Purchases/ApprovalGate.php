<?php

declare(strict_types=1);

namespace Poland\Purchases;

use Poland\Ksef\Parsing\ParsedInvoice;

/**
 * Decides whether an invoice may be approved by a person right now.
 *
 * Every incoming invoice passes through here. The gate never approves
 * anything itself — it says whether the Approve button may exist and, when it
 * may not, which fact removes it. An interpreter may propose a product
 * mapping; only a person's action moves the status, and only when this gate
 * has nothing to object to.
 */
final class ApprovalGate
{
    public function assess(
        ParsedInvoice $invoice,
        ?PostingPlan $plan,
        bool $correctionLinked = false,
        bool $possibleDuplicate = false,
        bool $alreadyPosted = false,
        bool $firstTimeSupplier = false,
    ): ApprovalAssessment {
        $blockers = [];
        $notes = [];

        if ($alreadyPosted) {
            $blockers[] = 'Ta faktura jest już zaksięgowana. Zmiana wymaga faktury korygującej, nie ponownego zatwierdzenia.';
        }

        if (! $invoice->isComplete()) {
            $blockers[] = 'Nie odczytano wszystkich wymaganych pól: '.implode(', ', $invoice->missing).'. Otwórz dokument źródłowy.';
        }

        if ($invoice->totalsAgree() === false) {
            $blockers[] = 'Suma netto i VAT nie zgadza się z kwotą brutto w nagłówku faktury — dokument wymaga wyjaśnienia z dostawcą.';
        }

        if ($invoice->lineTotalsAgree() === false) {
            $blockers[] = 'Pozycje faktury nie sumują się do kwot z nagłówka. Ani nagłówek, ani pozycje nie są "poprawiane" automatycznie — rozstrzygnij ręcznie.';
        }

        if ($invoice->metadata->isCorrection() && ! $correctionLinked) {
            $blockers[] = 'Faktura korygująca bez powiązanej faktury pierwotnej. Wskaż korygowaną fakturę albo potwierdź, że nie ma jej w systemie.';
        }

        if ($possibleDuplicate) {
            $blockers[] = 'Możliwy duplikat: ten sam dostawca, numer, data i kwota istnieją już pod innym numerem KSeF. Rozstrzygnij, czy to jedna faktura czy dwie.';
        }

        if ($plan === null) {
            $blockers[] = 'Nie udało się przygotować planu księgowania.';
        } else {
            foreach ($plan->blockers as $blocker) {
                $blockers[] = $blocker;
            }
            foreach ($plan->notes as $note) {
                $notes[] = $note;
            }
        }

        if ($firstTimeSupplier) {
            $notes[] = 'Pierwsza faktura od tego dostawcy — sprawdź NIP i nazwę.';
        }

        if ($invoice->unknownElements !== []) {
            $notes[] = 'Dokument zawiera pola, których parser nie mapuje: '.implode(', ', $invoice->unknownElements).'. Oryginalny XML jest zachowany w całości.';
        }

        return new ApprovalAssessment(array_values(array_unique($blockers)), array_values(array_unique($notes)));
    }
}
