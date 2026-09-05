<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Category;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Option source of catalog categories for the Member Price Rule form.
 * Categories that already have a rule are shown as disabled (greyed out).
 * When editing an existing record the current category is excluded from
 * the disabled set.
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

        // Fetch already-used category IDs, excluding the record being edited.
        $currentEntityId = (int) $this->request->getParam('entity_id');
        $usedSelect = $conn->select()
            ->from($this->resource->getTableName('ahy_caliber_nation_member_price_rule'), ['target_id']);
        if ($currentEntityId) {
            $usedSelect->where('entity_id != ?', $currentEntityId);
        }
        $usedCategories = array_flip(array_map('intval', $conn->fetchCol($usedSelect)));

        $nameAttrId = (int) $conn->fetchOne(
            $conn->select()
                ->from(['a' => $this->resource->getTableName('eav_attribute')], ['attribute_id'])
                ->join(['t' => $this->resource->getTableName('eav_entity_type')], 't.entity_type_id = a.entity_type_id', [])
                ->where('a.attribute_code = ?', 'name')
                ->where('t.entity_type_code = ?', 'catalog_category')
        );

        $rows = $conn->fetchAll(
            $conn->select()
                ->from(['e' => $this->resource->getTableName('catalog_category_entity')], ['entity_id', 'path', 'level'])
                ->joinLeft(
                    ['v' => $this->resource->getTableName('catalog_category_entity_varchar')],
                    'v.entity_id = e.entity_id AND v.attribute_id = ' . $nameAttrId . ' AND v.store_id = 0',
                    ['name' => 'v.value']
                )
        );

        // id => name map for breadcrumb building.
        $names = [];
        foreach ($rows as $r) {
            $names[(int) $r['entity_id']] = (string) ($r['name'] ?? '');
        }

        $options = [];
        foreach ($rows as $r) {
            if ((int) $r['level'] < 2) {
                continue;
            }
            $id = (int) $r['entity_id'];
            $alreadyUsed = isset($usedCategories[$id]);
            $segments = array_slice(array_map('intval', explode('/', (string) $r['path'])), 2);
            $crumbs = [];
            foreach ($segments as $segId) {
                $crumbs[] = $names[$segId] ?? ('#' . $segId);
            }
            $label = $crumbs ? implode(' > ', $crumbs) : ($names[$id] ?: 'Category');
            $option = [
                'value' => $id,
                'label' => $alreadyUsed
                    ? sprintf('%s (#%d) — Already Has Rule', $label, $id)
                    : sprintf('%s (#%d)', $label, $id),
            ];
            if ($alreadyUsed) {
                $option['disabled'] = true;
            }
            $options[] = $option;
        }

        usort($options, static fn ($a, $b) => strcasecmp((string) $a['label'], (string) $b['label']));

        return $this->options = $options;
    }
}
