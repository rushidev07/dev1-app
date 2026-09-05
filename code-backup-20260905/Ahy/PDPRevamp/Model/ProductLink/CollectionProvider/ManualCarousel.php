<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\ProductLink\CollectionProvider;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\LinkFactory;
use Magento\Catalog\Model\ProductLink\CollectionProviderInterface;

/**
 * Registered twice (once per link type ID, via two virtualTypes in di.xml)
 * against Magento\Catalog\Model\ProductLink\CollectionProvider - the
 * registry ProductLinkRepositoryInterface::getList()/save() consult
 * whenever a product form (see Ui\DataProvider\Product\Form\Modifier\
 * ManualCarousels) loads or saves link data for a custom link type.
 * Without this, admin's product edit page throws "The collection provider
 * isn't registered" as soon as a product has a saved manual link.
 *
 * Uses the same generic Model\Product\Link mechanism (works with any
 * link_type_id via straight SQL, no per-type hardcoding) as
 * RelatedCarouselDataProvider::getManualFallbackProducts() on the
 * storefront side.
 */
class ManualCarousel implements CollectionProviderInterface
{
    private LinkFactory $linkFactory;
    private int $linkTypeId;

    public function __construct(LinkFactory $linkFactory, int $linkTypeId)
    {
        $this->linkFactory = $linkFactory;
        $this->linkTypeId = $linkTypeId;
    }

    /**
     * @inheritdoc
     */
    public function getLinkedProducts(Product $product)
    {
        $linkModel = $this->linkFactory->create();
        $linkModel->setLinkTypeId($this->linkTypeId);

        $collection = $linkModel->getProductCollection();
        $collection->setProduct($product);
        $collection->setPositionOrder();

        return $collection->getItems();
    }
}
