<?php

declare(strict_types=1);

namespace Poland\Ksef\Fa3;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use Poland\Domain\Money;

/**
 * Deterministic FA(3) XML from an {@see Fa3Invoice}.
 *
 * Element order follows the pinned XSD exactly; the same input and the same
 * creation timestamp produce byte-identical output, so a stored hash is
 * meaningful. Nothing is defaulted: an absent optional field is an absent
 * element. The generator adds nothing that the invoice did not state, except
 * the four mandatory `Adnotacje` markers, which the schema requires and which
 * are derived from the invoice's own content (exempt lines, reverse charge,
 * split payment, cash method) — every one of them reads "2" (no) unless the
 * invoice says otherwise.
 */
final class Fa3XmlGenerator
{
    public function __construct(private readonly string $systemInfo = 'SignalMesh Accounts')
    {
        if (trim($systemInfo) === '' || mb_strlen($systemInfo) > 256) {
            throw new \InvalidArgumentException('SystemInfo ma 1–256 znaków.');
        }
    }

    public function generate(Fa3Invoice $invoice, DateTimeImmutable $createdAt): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $doc->preserveWhiteSpace = false;

        $root = $doc->createElementNS(Fa3Schema::NAMESPACE, 'Faktura');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:etd', Fa3Schema::TYPES_NAMESPACE);
        $doc->appendChild($root);

        $this->header($doc, $root, $createdAt);
        $this->seller($doc, $root, $invoice->seller);
        $this->buyer($doc, $root, $invoice->buyer);
        $this->fa($doc, $root, $invoice);

        $xml = $doc->saveXML();
        if ($xml === false) {
            throw new \RuntimeException('Nie udało się zserializować dokumentu FA(3).');
        }

        return $xml;
    }

    private function header(DOMDocument $doc, DOMElement $root, DateTimeImmutable $createdAt): void
    {
        $header = $this->child($doc, $root, 'Naglowek');
        $code = $this->child($doc, $header, 'KodFormularza', Fa3Schema::FORM_VALUE);
        $code->setAttribute('kodSystemowy', Fa3Schema::SYSTEM_CODE);
        $code->setAttribute('wersjaSchemy', Fa3Schema::SCHEMA_VERSION);
        $this->child($doc, $header, 'WariantFormularza', (string) Fa3Schema::VARIANT);
        $this->child($doc, $header, 'DataWytworzeniaFa', $createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
        $this->child($doc, $header, 'SystemInfo', $this->systemInfo);
    }

    private function seller(DOMDocument $doc, DOMElement $root, Fa3Seller $seller): void
    {
        $p1 = $this->child($doc, $root, 'Podmiot1');
        $id = $this->child($doc, $p1, 'DaneIdentyfikacyjne');
        $this->child($doc, $id, 'NIP', $seller->nip);
        $this->child($doc, $id, 'Nazwa', $seller->name);
        $this->address($doc, $p1, 'Adres', $seller->address);
        if ($seller->email !== null || $seller->phone !== null) {
            $contact = $this->child($doc, $p1, 'DaneKontaktowe');
            if ($seller->email !== null) {
                $this->child($doc, $contact, 'Email', $seller->email);
            }
            if ($seller->phone !== null) {
                $this->child($doc, $contact, 'Telefon', $seller->phone);
            }
        }
    }

    private function buyer(DOMDocument $doc, DOMElement $root, Fa3Buyer $buyer): void
    {
        $p2 = $this->child($doc, $root, 'Podmiot2');
        $id = $this->child($doc, $p2, 'DaneIdentyfikacyjne');
        $identifier = $buyer->identifier;
        switch ($identifier->kind) {
            case Fa3BuyerIdentifier::NIP:
                $this->child($doc, $id, 'NIP', (string) $identifier->value);
                break;
            case Fa3BuyerIdentifier::VAT_UE:
                $this->child($doc, $id, 'KodUE', (string) $identifier->countryCode);
                $this->child($doc, $id, 'NrVatUE', (string) $identifier->value);
                break;
            case Fa3BuyerIdentifier::OTHER:
                if ($identifier->countryCode !== null) {
                    $this->child($doc, $id, 'KodKraju', $identifier->countryCode);
                }
                $this->child($doc, $id, 'NrID', (string) $identifier->value);
                break;
            default:
                $this->child($doc, $id, 'BrakID', '1');
        }
        if ($buyer->name !== null) {
            $this->child($doc, $id, 'Nazwa', $buyer->name);
        }
        if ($buyer->address !== null) {
            $this->address($doc, $p2, 'Adres', $buyer->address);
        }
        if ($buyer->email !== null) {
            $contact = $this->child($doc, $p2, 'DaneKontaktowe');
            $this->child($doc, $contact, 'Email', $buyer->email);
        }
        // Both markers are mandatory in the schema ("1" yes / "2" no); they are
        // facts about the buyer's record, stated there, never guessed here.
        $this->child($doc, $p2, 'JST', $buyer->localGovernmentSubUnit ? '1' : '2');
        $this->child($doc, $p2, 'GV', $buyer->vatGroupMember ? '1' : '2');
    }

    private function fa(DOMDocument $doc, DOMElement $root, Fa3Invoice $invoice): void
    {
        $fa = $this->child($doc, $root, 'Fa');
        $this->child($doc, $fa, 'KodWaluty', $invoice->currency);
        $this->child($doc, $fa, 'P_1', $invoice->issueDate->format('Y-m-d'));
        if ($invoice->issuePlace !== null) {
            $this->child($doc, $fa, 'P_1M', $invoice->issuePlace);
        }
        $this->child($doc, $fa, 'P_2', $invoice->number);
        if ($invoice->saleDate !== null) {
            $this->child($doc, $fa, 'P_6', $invoice->saleDate->format('Y-m-d'));
        }

        $totals = $invoice->totals();
        // Groups with a VAT partner, in schema order.
        foreach (['1', '2', '3', '4'] as $group) {
            if (isset($totals->byGroup[$group])) {
                $this->child($doc, $fa, 'P_13_'.$group, $this->amount($totals->byGroup[$group]['net']));
                $this->child($doc, $fa, 'P_14_'.$group, $this->amount($totals->byGroup[$group]['vat']));
            }
        }
        // Groups without a VAT element.
        foreach (['6_1', '6_2', '6_3', '7', '8', '9', '10'] as $group) {
            if (isset($totals->byGroup[$group])) {
                $this->child($doc, $fa, 'P_13_'.$group, $this->amount($totals->byGroup[$group]['net']));
            }
        }
        $this->child($doc, $fa, 'P_15', $this->amount($totals->gross));

        $annotations = $this->child($doc, $fa, 'Adnotacje');
        $this->child($doc, $annotations, 'P_16', $invoice->cashMethod ? '1' : '2');
        $this->child($doc, $annotations, 'P_17', '2');
        $this->child($doc, $annotations, 'P_18', $invoice->hasReverseChargeLines() ? '1' : '2');
        $this->child($doc, $annotations, 'P_18A', $invoice->splitPayment ? '1' : '2');
        $exemption = $this->child($doc, $annotations, 'Zwolnienie');
        if ($invoice->hasExemptLines()) {
            $this->child($doc, $exemption, 'P_19', '1');
            $this->child($doc, $exemption, 'P_19A', (string) $invoice->exemptionLegalBasis);
        } else {
            $this->child($doc, $exemption, 'P_19N', '1');
        }
        $nst = $this->child($doc, $annotations, 'NoweSrodkiTransportu');
        $this->child($doc, $nst, 'P_22N', '1');
        $this->child($doc, $annotations, 'P_23', '2');
        $margin = $this->child($doc, $annotations, 'PMarzy');
        $this->child($doc, $margin, 'P_PMarzyN', '1');

        $this->child($doc, $fa, 'RodzajFaktury', $invoice->type);

        if ($invoice->correction !== null) {
            $c = $invoice->correction;
            $this->child($doc, $fa, 'PrzyczynaKorekty', $c->reason);
            if ($c->effectType !== null) {
                $this->child($doc, $fa, 'TypKorekty', $c->effectType);
            }
            $corrected = $this->child($doc, $fa, 'DaneFaKorygowanej');
            $this->child($doc, $corrected, 'DataWystFaKorygowanej', $c->correctedIssueDate->format('Y-m-d'));
            $this->child($doc, $corrected, 'NrFaKorygowanej', $c->correctedInvoiceNumber);
            if ($c->correctedKsefNumber !== null) {
                $this->child($doc, $corrected, 'NrKSeF', '1');
                $this->child($doc, $corrected, 'NrKSeFFaKorygowanej', $c->correctedKsefNumber);
            } else {
                $this->child($doc, $corrected, 'NrKSeFN', '1');
            }
        }

        foreach ($invoice->additionalDescriptions as $key => $value) {
            $extra = $this->child($doc, $fa, 'DodatkowyOpis');
            $this->child($doc, $extra, 'Klucz', $key);
            $this->child($doc, $extra, 'Wartosc', $value);
        }

        foreach ($invoice->lines as $line) {
            $row = $this->child($doc, $fa, 'FaWiersz');
            $this->child($doc, $row, 'NrWierszaFa', (string) $line->number);
            if ($line->saleDate !== null) {
                $this->child($doc, $row, 'P_6A', $line->saleDate->format('Y-m-d'));
            }
            $this->child($doc, $row, 'P_7', $line->description);
            if ($line->internalCode !== null) {
                $this->child($doc, $row, 'Indeks', $line->internalCode);
            }
            if ($line->unit !== null) {
                $this->child($doc, $row, 'P_8A', $line->unit);
            }
            if ($line->quantity !== null) {
                $this->child($doc, $row, 'P_8B', $line->quantity);
            }
            if ($line->unitNetPrice !== null) {
                $this->child($doc, $row, 'P_9A', $line->unitNetPrice);
            }
            $this->child($doc, $row, 'P_11', $this->amount($line->netValue));
            $this->child($doc, $row, 'P_12', $line->rate->value);
            if ($line->gtu !== null) {
                $this->child($doc, $row, 'GTU', $line->gtu);
            }
        }

        if ($invoice->payment !== null && ! $invoice->payment->isEmpty()) {
            $p = $invoice->payment;
            $payment = $this->child($doc, $fa, 'Platnosc');
            if ($p->paid === true && $p->paidOn !== null) {
                $this->child($doc, $payment, 'Zaplacono', '1');
                $this->child($doc, $payment, 'DataZaplaty', $p->paidOn->format('Y-m-d'));
            }
            if ($p->dueDate !== null) {
                $term = $this->child($doc, $payment, 'TerminPlatnosci');
                $this->child($doc, $term, 'Termin', $p->dueDate->format('Y-m-d'));
            }
            if ($p->formCode !== null) {
                $this->child($doc, $payment, 'FormaPlatnosci', $p->formCode);
            }
            if ($p->bankAccount !== null) {
                $account = $this->child($doc, $payment, 'RachunekBankowy');
                $this->child($doc, $account, 'NrRB', $p->bankAccount);
                if ($p->bankName !== null) {
                    $this->child($doc, $account, 'NazwaBanku', $p->bankName);
                }
            }
        }
    }

    private function address(DOMDocument $doc, DOMElement $parent, string $name, Fa3Address $address): void
    {
        $el = $this->child($doc, $parent, $name);
        $this->child($doc, $el, 'KodKraju', $address->countryCode);
        $this->child($doc, $el, 'AdresL1', $address->line1);
        if ($address->line2 !== null) {
            $this->child($doc, $el, 'AdresL2', $address->line2);
        }
    }

    private function child(DOMDocument $doc, DOMElement $parent, string $name, ?string $text = null): DOMElement
    {
        $el = $doc->createElementNS(Fa3Schema::NAMESPACE, $name);
        if ($text !== null) {
            $el->appendChild($doc->createTextNode($text));
        }
        $parent->appendChild($el);

        return $el;
    }

    /** TKwotowy: up to 2 fraction digits, no thousands separator, "-" for negatives. */
    private function amount(Money $money): string
    {
        $grosze = $money->grosze;
        $sign = $grosze < 0 ? '-' : '';
        $abs = abs($grosze);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }
}
