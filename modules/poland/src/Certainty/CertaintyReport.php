<?php

declare(strict_types=1);

namespace Poland\Certainty;

/** The collected certainty of a month: one state, and the reasons for it. */
final class CertaintyReport implements \JsonSerializable
{
    /** @param list<Caveat> $caveats */
    private function __construct(
        public readonly DataCertainty $certainty,
        public readonly array $caveats,
    ) {
    }

    /** @param list<Caveat> $caveats */
    public static function of(array $caveats, bool $reconciled = false): self
    {
        if ($caveats === []) {
            return new self(
                $reconciled ? DataCertainty::Verified : DataCertainty::Calculated,
                [],
            );
        }

        return new self(
            DataCertainty::worst(...array_map(
                static fn (Caveat $c): DataCertainty => $c->certainty,
                $caveats,
            )),
            $caveats,
        );
    }

    public function isFinal(): bool
    {
        return $this->certainty->isFinal();
    }

    /** @return list<Caveat> */
    public function blocking(): array
    {
        return array_values(array_filter(
            $this->caveats,
            static fn (Caveat $c): bool => $c->certainty === DataCertainty::NotEnoughData,
        ));
    }

    public function jsonSerialize(): array
    {
        return [
            'certainty' => $this->certainty->value,
            'label' => $this->certainty->label(),
            'english_label' => $this->certainty->englishLabel(),
            'is_final' => $this->isFinal(),
            'caveats' => $this->caveats,
        ];
    }
}
