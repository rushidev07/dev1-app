<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Swatches\Model\Swatch;

/**
 * Reads Magento's own native visual-swatch hex per `color` attribute option
 * (eav_attribute_option_swatch, default scope) - the same value shown/edited
 * in Stores > Attributes > Product > color's "Manage Swatch" grid. Populated
 * either by an admin directly in that grid, or by
 * Ahy\PDPRevamp\Service\AiColorHexResolver for options an admin never set.
 */
class NativeColorSwatch
{
    private const TABLE = 'eav_attribute_option_swatch';

    private ResourceConnection $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @param int[] $optionIds
     * @return array<int, string> option_id => raw swatch value (a hex, an
     *  uploaded image's relative path, or a comma-paired mix of either -
     *  see getTwoColorOptionsForAttribute()), for any option with a swatch
     *  set (excludes only the placeholder "empty" swatch type)
     */
    public function getHexByOptionIds(array $optionIds): array
    {
        if (!$optionIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE), ['option_id', 'value'])
            ->where('option_id IN (?)', $optionIds)
            ->where('store_id = ?', 0)
            ->where('type != ?', Swatch::SWATCH_TYPE_EMPTY)
            // A blank value with a non-empty type happens when Magento
            // auto-creates a placeholder row for a newly added swatch
            // option, before anyone picks an actual color - without this,
            // such a row reads as "already set" (blocking both the PDP
            // render and AiColorHexResolver's backfill, which trusts this
            // same method to decide what still needs resolving) even though
            // there is no real hex to show.
            ->where('value != ?', '');

        return array_map('strval', $connection->fetchPairs($select));
    }

    /**
     * @param int[] $optionIds
     * @return int[] the subset of $optionIds that already have a visual-color swatch set
     */
    public function getOptionIdsWithSwatch(array $optionIds): array
    {
        return array_map('intval', array_keys($this->getHexByOptionIds($optionIds)));
    }

    /**
     * @param int[] $optionIds
     * @return array<int, array<int, string>> option_id => [store_id => label]
     */
    public function getOptionLabelsByStore(array $optionIds): array
    {
        if (!$optionIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('eav_attribute_option_value'),
                ['option_id', 'store_id', 'value']
            )
            ->where('option_id IN (?)', $optionIds);

        $labels = [];
        foreach ($connection->fetchAll($select) as $row) {
            $labels[(int) $row['option_id']][(int) $row['store_id']] = (string) $row['value'];
        }

        return $labels;
    }

    /**
     * Sets one option's label for one store scope (0 = Admin/default scope),
     * inserting a new eav_attribute_option_value row if that scope doesn't
     * have one yet - used by the "Two-Color Swatches" admin panel to let an
     * admin rename an existing two-color option directly, independent of
     * the native "Manage Swatch" grid's own (also still present, unchanged)
     * label fields for that same option, so editing one can never silently
     * clobber the other.
     */
    public function saveOptionLabel(int $optionId, int $storeId, string $label): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('eav_attribute_option_value');

        $valueId = $connection->fetchOne(
            $connection->select()
                ->from($table, 'value_id')
                ->where('option_id = ?', $optionId)
                ->where('store_id = ?', $storeId)
        );

        if ($valueId) {
            $connection->update($table, ['value' => $label], ['value_id = ?' => $valueId]);
        } else {
            $connection->insert($table, ['option_id' => $optionId, 'store_id' => $storeId, 'value' => $label]);
        }
    }

    /**
     * Every two-color (comma-paired) swatch option on the given attribute,
     * for the admin "Two-Color Swatches" panel - see
     * Plugin\Catalog\Model\Product\Attribute\DataProvider\AddTwoColorSwatchData.
     * Each half of the pair may be a hex ("#RRGGBB") or an uploaded image's
     * relative path (leading "/", written by the admin panel's "Upload a
     * file" option) - not filtered by swatch type here, since
     * determineSwatchType() (Magento_Swatches) classifies the whole
     * comma-joined value by its first character alone, so a pair with an
     * image second half is still stored under type visual-color if the
     * first half is a hex, and vice versa.
     *
     * @return array<int, array{option_id: int, label: string, first: string, second: string, labels: array<int, string>}>
     */
    public function getTwoColorOptionsForAttribute(int $attributeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['eao' => $this->resourceConnection->getTableName('eav_attribute_option')],
                ['option_id']
            )
            ->joinInner(
                ['eaov' => $this->resourceConnection->getTableName('eav_attribute_option_value')],
                'eaov.option_id = eao.option_id AND eaov.store_id = 0',
                ['label' => 'value']
            )
            ->joinInner(
                ['eaos' => $this->resourceConnection->getTableName(self::TABLE)],
                'eaos.option_id = eao.option_id AND eaos.store_id = 0 AND eaos.type != '
                    . (int) Swatch::SWATCH_TYPE_EMPTY,
                ['value']
            )
            ->where('eao.attribute_id = ?', $attributeId)
            ->where('eaos.value LIKE ?', '%,%');

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $parts = array_map('trim', explode(',', (string) $row['value'], 2));
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                continue;
            }

            $rows[] = [
                'option_id' => (int) $row['option_id'],
                'label' => (string) $row['label'],
                'first' => $parts[0],
                'second' => $parts[1],
            ];
        }

        if ($rows) {
            $labelsByOption = $this->getOptionLabelsByStore(array_column($rows, 'option_id'));
            foreach ($rows as &$row) {
                $row['labels'] = $labelsByOption[$row['option_id']] ?? [0 => $row['label']];
            }
            unset($row);
        }

        return $rows;
    }
}
