<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\SellerParticipation;

use Ahy\CaliberNation\Model\ResourceModel\SellerParticipation\CollectionFactory;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * Form data provider for the Seller Participation admin form.
 */
class DataProvider extends AbstractDataProvider
{
    /** @var array<int,array<string,mixed>>|null */
    private ?array $loadedData = null;

    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly DataPersistorInterface $dataPersistor,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData(): array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }
        $this->loadedData = [];
        foreach ($this->collection->getItems() as $item) {
            $data = $item->getData();
            // DECIMAL(12,4) reads back as "20.0000" — show 2 places in the form input.
            if (isset($data['discount_value']) && is_numeric($data['discount_value'])) {
                $data['discount_value'] = number_format((float) $data['discount_value'], 2, '.', '');
            }
            $this->loadedData[$item->getId()] = $data;
        }

        $persisted = $this->dataPersistor->get('caliber_nation_seller_participation');
        if (!empty($persisted)) {
            $id = $persisted['entity_id'] ?? null;
            $this->loadedData[$id] = $persisted;
            $this->dataPersistor->clear('caliber_nation_seller_participation');
        }

        return $this->loadedData;
    }
}
