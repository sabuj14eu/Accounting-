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

    /**
     * Caveats that stop the month being finalised.
     *
     * Includes BLOCKED and FAILED as well as NOT ENOUGH DATA — all three mean
     * the figure is not final, they differ only in who can fix it.
     *
     * @return list<Caveat>
     */
    public function blocking(): array
    {
        return array_values(array_filter(
            $this->caveats,
            static fn (Caveat $c): bool => in_array($c->certainty, [
                DataCertainty::NotEnoughData,
                DataCertainty::Blocked,
                DataCertainty::Failed,
            ], true),
        ));
    }

    /**
     * Caveats the taxpayer can clear themselves, separated from the ones that
     * need an operator.
     *
     * Showing "upload the missing statement" next to "the rate tables are
     * unverified" as one undifferentiated list sends people looking for a
     * document that does not exist.
     *
     * @return list<Caveat>
     */
    public function userActionable(): array
    {
        return array_values(array_filter(
            $this->caveats,
            static fn (Caveat $c): bool => $c->certainty->isUserActionable(),
        ));
    }

    /** @return list<Caveat> */
    public function systemConditions(): array
    {
        return array_values(array_filter(
            $this->caveats,
            static fn (Caveat $c): bool => $c->certainty->isSystemCondition(),
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
