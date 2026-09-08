<?php

declare(strict_types=1);

namespace Poland\Ksef\Fa3;

use DateTimeImmutable;

/** `Platnosc`: paid flag and date, due date, form of payment (TFormaPlatnosci 1–7), bank account. */
final class Fa3Payment
{
    public const FORMS = ['1' => 'Gotówka', '2' => 'Karta', '3' => 'Bon', '4' => 'Czek', '5' => 'Kredyt', '6' => 'Przelew', '7' => 'Mobilna'];

    public function __construct(
        public readonly ?bool $paid = null,
        public readonly ?DateTimeImmutable $paidOn = null,
        public readonly ?DateTimeImmutable $dueDate = null,
        public readonly ?string $formCode = null,
        public readonly ?string $bankAccount = null,
        public readonly ?string $bankName = null,
    ) {
        if ($paid === true && $paidOn === null) {
            throw new \InvalidArgumentException('Faktura oznaczona jako zapłacona wymaga daty zapłaty (DataZaplaty).');
        }
        if ($formCode !== null && ! isset(self::FORMS[$formCode])) {
            throw new \InvalidArgumentException('Forma płatności musi być kodem 1–7 (TFormaPlatnosci).');
        }
        if ($bankAccount !== null && preg_match('/^[A-Z0-9]{8,34}$/', $bankAccount) !== 1) {
            throw new \InvalidArgumentException('Numer rachunku: 8–34 znaki [A-Z0-9], bez spacji.');
        }
    }

    public function isEmpty(): bool
    {
        return $this->paid === null && $this->dueDate === null && $this->formCode === null && $this->bankAccount === null;
    }
}
