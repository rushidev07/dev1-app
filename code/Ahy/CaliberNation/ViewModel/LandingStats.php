<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\ViewModel;

use Ahy\CaliberNation\Model\Config;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Formats the three headline figures in the landing page's stats band.
 *
 * The figures are admin-configurable (Stores > Configuration > Caliber Nation >
 * Landing Page Stats) and are entered as plain numbers — all currency symbols,
 * thousands separators and "%" live here so the admin can never break the layout
 * with a stray character. The same values feed both the big number and the body
 * copy beneath it, so the two can't drift apart.
 */
class LandingStats implements ArgumentInterface
{
    public function __construct(
        private readonly Config $config
    ) {}

    // ── Average savings per year ───────────────────────────────────────────────

    /** e.g. "$3,500+" — the "+" signals "at least this much". */
    public function getAvgSavings(): string
    {
        return '$' . $this->formatAmount($this->config->getStatAvgSavings()) . '+';
    }

    /** e.g. "$3,500" — same figure without the "+", for use mid-sentence. */
    public function getAvgSavingsPlain(): string
    {
        return '$' . $this->formatAmount($this->config->getStatAvgSavings());
    }

    // ── Monthly equivalent ─────────────────────────────────────────────────────

    /** e.g. "$8.33" — derived from the annual price when the field is left empty. */
    public function getMonthlyCost(): string
    {
        return '$' . $this->formatAmount($this->config->getStatMonthlyCost());
    }

    // ── Maximum discount ───────────────────────────────────────────────────────

    /** e.g. "90%". */
    public function getMaxDiscount(): string
    {
        return $this->formatAmount($this->config->getStatMaxDiscount()) . '%';
    }

    // ── Annual price (kept alongside so the band can reference the plan) ───────

    /** e.g. "$99.99". */
    public function getAnnualPrice(): string
    {
        return $this->config->getFormattedMembershipPrice();
    }

    /** Whether the band has anything worth rendering. */
    public function hasStats(): bool
    {
        return $this->config->getStatAvgSavings() > 0
            || $this->config->getStatMonthlyCost() > 0
            || $this->config->getStatMaxDiscount() > 0;
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Whole numbers render without decimals (3500 → "3,500"); anything with a
     * fractional part keeps two (8.33 → "8.33"). Avoids an ugly "$3,500.00/yr".
     */
    private function formatAmount(float $value): string
    {
        return number_format($value, $value === floor($value) ? 0 : 2);
    }
}
