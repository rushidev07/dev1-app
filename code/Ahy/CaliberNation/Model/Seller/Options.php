<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Seller;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Option source of Webkul marketplace sellers for the Seller Participation form —
 * so the admin picks a seller from a (searchable) dropdown instead of typing an id.
 * Label = shop title (or email) + seller id; value = seller id. Deduped, sorted.
 */
class Options implements OptionSourceInterface
{
    private ?array $options = null;

    public function __construct(
        private readonly ResourceConnection $resource
    ) {}

    public function toOptionArray(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $conn = $this->resource->getConnection();
        $select = $conn->select()
            ->from(['mu' => $this->resource->getTableName('marketplace_userdata')], [
                'seller_id' => 'mu.seller_id',
                'title'     => new \Zend_Db_Expr('MAX(mu.shop_title)'),
                'email'     => new \Zend_Db_Expr('MAX(ce.email)'),
            ])
            ->joinLeft(
                ['ce' => $this->resource->getTableName('customer_entity')],
                'ce.entity_id = mu.seller_id',
                []
            )
            ->where('mu.is_seller = ?', 1)
            ->where('mu.seller_id IS NOT NULL')
            ->group('mu.seller_id');

        $options = [];
        foreach ($conn->fetchAll($select) as $row) {
            $sellerId = (int) $row['seller_id'];
            if (!$sellerId) {
                continue;
            }
            $label = trim((string) $row['title']) ?: trim((string) $row['email']) ?: 'Seller';
            $options[] = ['value' => $sellerId, 'label' => sprintf('%s (#%d)', $label, $sellerId)];
        }

        usort($options, static fn ($a, $b) => strcasecmp((string) $a['label'], (string) $b['label']));

        return $this->options = $options;
    }
}
