<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Model\Discount;

/**
 * Single source of truth for the discount bands used by both the email summary and
 * the CSV "Bracket" column.
 *
 * Bands are derived from the configured threshold rather than hardcoded, so they stay
 * meaningful at any threshold: they run from the threshold up to the next ten, then in
 * steps of ten, closing with an open-ended band at the top. A threshold of 50 therefore
 * yields 50–60, 60–70, 70–80, 80–90 and 90%+, ordered highest first.
 */
class BracketProvider
{
    private const STEP     = 10.0;
    private const TOP_BAND = 90.0;

    /** @var array<string, Bracket[]> */
    private array $cache = [];

    /**
     * Bands for a threshold, ordered from the highest discount down.
     *
     * @return Bracket[]
     */
    public function getBrackets(float $threshold): array
    {
        $key = (string) $threshold;

        if (!isset($this->cache[$key])) {
            $this->cache[$key] = $this->buildBrackets($threshold);
        }

        return $this->cache[$key];
    }

    /**
     * Label of the band a percentage falls into, or an empty string when it sits below
     * the threshold entirely.
     */
    public function getLabelFor(float $percentage, float $threshold): string
    {
        foreach ($this->getBrackets($threshold) as $bracket) {
            if ($bracket->contains($percentage)) {
                return $bracket->getLabel();
            }
        }

        return '';
    }

    /**
     * Count how many of the given percentages fall into each band.
     *
     * @param  float[] $percentages
     * @return array<int, array{label: string, count: int}>
     */
    public function summarize(array $percentages, float $threshold): array
    {
        $brackets = $this->getBrackets($threshold);
        $counts   = \array_fill(0, \count($brackets), 0);

        foreach ($percentages as $percentage) {
            foreach ($brackets as $index => $bracket) {
                if ($bracket->contains((float) $percentage)) {
                    $counts[$index]++;
                    break;
                }
            }
        }

        $summary = [];
        foreach ($brackets as $index => $bracket) {
            $summary[] = ['label' => $bracket->getLabel(), 'count' => $counts[$index]];
        }

        return $summary;
    }

    /**
     * @return Bracket[]
     */
    private function buildBrackets(float $threshold): array
    {
        $threshold = \max(0.0, $threshold);
        $brackets  = [];
        $lower     = $threshold;

        while ($lower < self::TOP_BAND) {
            // First step lands on the next whole ten so the bands stay readable
            // even when the threshold is not itself a multiple of ten.
            $upper = \min(self::TOP_BAND, \floor($lower / self::STEP) * self::STEP + self::STEP);

            if ($upper <= $lower) {
                $upper = \min(self::TOP_BAND, $lower + self::STEP);
            }

            $brackets[] = new Bracket($lower, $upper, $this->formatRange($lower, $upper));
            $lower      = $upper;
        }

        $openFrom   = \max(self::TOP_BAND, $threshold);
        $brackets[] = new Bracket($openFrom, null, $this->formatRange($openFrom, null));

        return \array_reverse($brackets);
    }

    private function formatRange(float $from, ?float $to): string
    {
        if ($to === null) {
            return \sprintf('%s%%+', $this->formatNumber($from));
        }

        // Plain hyphen: renders cleanly in email clients and in CSV.
        return \sprintf('%s-%s%%', $this->formatNumber($from), $this->formatNumber($to));
    }

    private function formatNumber(float $value): string
    {
        return \rtrim(\rtrim(\number_format($value, 2, '.', ''), '0'), '.');
    }
}
