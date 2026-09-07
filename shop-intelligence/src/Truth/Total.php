<?php

declare(strict_types=1);

namespace Shop\Truth;

/**
 * The sum of a set of figures, carrying the mix it was built from.
 *
 * §25: ACTUAL, EXPECTED, ESTIMATED and USER DECLARED are never mixed without
 * the distinction being displayed. This class is how that is enforced rather
 * than remembered — describe() cannot render a mixed total without the split,
 * because the split is built in the same method as the number.
 */
final class Total implements \JsonSerializable
{
    /**
     * @param  array<string, Money>  $byCertainty
     * @param  array<string, Money>  $byProvenance
     */
    private function __construct(
        public readonly Money $amount,
        public readonly Certainty $certainty,
        public readonly Provenance $provenance,
        public readonly array $byCertainty,
        public readonly array $byProvenance,
        public readonly Money $corroborated,
        public readonly Money $uncorroborated,
        public readonly int $figureCount,
    ) {
    }

    /** @param  list<Figure>  $figures */
    public static function of(array $figures): self
    {
        if ($figures === []) {
            return new self(
                Money::zero(),
                // An empty set is not ACTUAL zero. Absence is not evidence.
                Certainty::ESTIMATED,
                Provenance::ANALYTICAL_ESTIMATE,
                [],
                [],
                Money::zero(),
                Money::zero(),
                0,
            );
        }

        $byCertainty = [];
        $byProvenance = [];
        $corroborated = Money::zero();
        $uncorroborated = Money::zero();
        $total = Money::zero();

        foreach ($figures as $figure) {
            $c = $figure->certainty()->value;
            $p = $figure->provenance()->value;
            $byCertainty[$c] = ($byCertainty[$c] ?? Money::zero())->plus($figure->amount);
            $byProvenance[$p] = ($byProvenance[$p] ?? Money::zero())->plus($figure->amount);
            $total = $total->plus($figure->amount);

            if ($figure->isCorroborated()) {
                $corroborated = $corroborated->plus($figure->amount);
            } else {
                $uncorroborated = $uncorroborated->plus($figure->amount);
            }
        }

        return new self(
            $total,
            Certainty::worst(...array_map(static fn (Figure $f) => $f->certainty(), $figures)),
            Provenance::worst(...array_map(static fn (Figure $f) => $f->provenance(), $figures)),
            $byCertainty,
            $byProvenance,
            $corroborated,
            $uncorroborated,
            count($figures),
        );
    }

    public function isMixed(): bool
    {
        return count($this->byCertainty) > 1;
    }

    public function isFullyCorroborated(): bool
    {
        return $this->figureCount > 0 && $this->uncorroborated->isZero();
    }

    /**
     * The one string this application is allowed to print for a total.
     *
     * A mixed total renders its split in the same breath as its value; there is
     * no code path that produces the number alone.
     */
    public function describe(): string
    {
        if ($this->figureCount === 0) {
            return 'NO DATA — nothing recorded for this period (not the same as zero)';
        }

        $text = $this->amount->format().' ['.$this->certainty->label().']';

        if ($this->isMixed()) {
            $parts = [];
            foreach ($this->byCertainty as $state => $amount) {
                $parts[] = $state.' '.$amount->format();
            }
            $text .= ' — mixed: '.implode(' + ', $parts);
        }

        return $text;
    }

    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amount->grosze,
            'formatted' => $this->amount->format(),
            'certainty' => $this->certainty->value,
            'provenance' => $this->provenance->value,
            'mixed' => $this->isMixed(),
            'by_certainty' => array_map(static fn (Money $m) => $m->grosze, $this->byCertainty),
            'by_provenance' => array_map(static fn (Money $m) => $m->grosze, $this->byProvenance),
            'corroborated' => $this->corroborated->grosze,
            'uncorroborated' => $this->uncorroborated->grosze,
            'figure_count' => $this->figureCount,
            'describe' => $this->describe(),
        ];
    }
}
