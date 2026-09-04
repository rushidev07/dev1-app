<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Seller;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Option source of Webkul marketplace sellers for the Seller Participation form.
 * Sellers already in the participation table are shown as disabled (greyed out).
 * When editing an existing record the current seller is excluded from the disabled set.
 */
class Options implements OptionSourceInterface
{
    private ?array $options = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly RequestInterface $request
    ) {}

    public function toOptionArray(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $conn = $this->resource->getConnection();

        // Fetch already-participating seller IDs, excluding the record being edited.
        $currentEntityId = (int) $this->request->getParam('entity_id');
        $participatingSelect = $conn->select()
            ->from($this->resource->getTableName('ahy_caliber_nation_seller_participation'), ['seller_id']);
        if ($currentEntityId) {
            $participatingSelect->where('entity_id != ?', $currentEntityId);
        }
        $participatingSellers = array_flip(array_map('intval', $conn->fetchCol($participatingSelect)));

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
            $alreadyParticipating = isset($participatingSellers[$sellerId]);
            $label = trim((string) $row['title']) ?: trim((string) $row['email']) ?: 'Seller';
            $option = [
                'value' => $sellerId,
                'label' => $alreadyParticipating
                    ? sprintf('%s (#%d) — Already Participating', $label, $sellerId)
                    : sprintf('%s (#%d)', $label, $sellerId),
            ];
            if ($alreadyParticipating) {
                $option['disabled'] = true;
            }
            $options[] = $option;
        }

        usort($options, static fn ($a, $b) => strcasecmp((string) $a['label'], (string) $b['label']));

        return $this->options = $options;
    }
}
