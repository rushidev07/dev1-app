<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\ViewModel;

use Ahy\CaliberNation\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * "Members save an average of $X" messaging (P4.3). Store-wide figure (cache-safe).
 *
 * Basis (locked): average savings PER MEMBER ORDER = Σ order_savings.savings ÷
 * distinct member orders. When there isn't enough order data (< MIN_ORDERS),
 * falls back to the admin static value; if that's 0 too, the block hides itself.
 * All copy is admin-configurable (messaging config).
 */
class AverageSavings implements ArgumentInterface
{
    private const MIN_ORDERS = 5;
    private const SAVINGS_TABLE = 'ahy_caliber_nation_order_savings';

    private ?float $average = null;

    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resource,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {}

    public function isEnabled(): bool
    {
        return $this->config->isEnabled() && $this->config->isPricingEnabled();
    }

    /** Average savings per member order, or the admin static fallback. */
    public function getAverage(): float
    {
        if ($this->average !== null) {
            return $this->average;
        }
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::SAVINGS_TABLE);
        $row = $conn->fetchRow(
            $conn->select()->from($table, [
                'orders' => new \Zend_Db_Expr('COUNT(DISTINCT order_id)'),
                'total'  => new \Zend_Db_Expr('SUM(savings)'),
            ])
        );
        $orders = (int) ($row['orders'] ?? 0);
        $total  = (float) ($row['total'] ?? 0);

        if ($orders >= self::MIN_ORDERS && $total > 0) {
            return $this->average = round($total / $orders, 2);
        }
        return $this->average = round($this->config->getAverageSavingsStatic(), 2);
    }

    /** Whether there's a meaningful figure to show. */
    public function isAvailable(): bool
    {
        return $this->isEnabled() && $this->getAverage() > 0;
    }

    public function getFormattedAverage(): string
    {
        return $this->priceCurrency->format($this->getAverage(), false);
    }

    public function getIntro(): string
    {
        return $this->config->getAverageSavingsIntro();
    }

    public function getSavingsMessage(): string
    {
        return $this->config->getSavingsMessage();
    }
}
