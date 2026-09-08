<?php

declare(strict_types=1);

namespace Poland\Ksef\Fa3;

/** `Podmiot2`: the buyer. Name and address are optional only for the art. 106e ust. 5 pkt 3 cases. */
final class Fa3Buyer
{
    public function __construct(
        public readonly Fa3BuyerIdentifier $identifier,
        public readonly ?string $name,
        public readonly ?Fa3Address $address = null,
        public readonly ?string $email = null,
        /** JST: the buyer is a subordinate unit of a local government entity. Mandatory marker in FA(3). */
        public readonly bool $localGovernmentSubUnit = false,
        /** GV: the buyer is a member of a VAT group. Mandatory marker in FA(3). */
        public readonly bool $vatGroupMember = false,
    ) {
        if ($name !== null && (trim($name) === '' || mb_strlen($name) > 512)) {
            throw new \InvalidArgumentException('Nazwa nabywcy, jeśli podana, ma 1–512 znaków.');
        }
    }
}
