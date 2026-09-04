<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Turns the secondary seller-info promo card into a single full-banner image.
 * Admins replace the placeholder via Content > Blocks > this block > click
 * the image > Insert/Edit Image (and can update the link via Insert/Edit
 * Link), no code change needed.
 */
class UpdateSellerInfoSecondaryPromoBlockImage implements DataPatchInterface
{
    private BlockRepositoryInterface $blockRepository;

    public function __construct(BlockRepositoryInterface $blockRepository)
    {
        $this->blockRepository = $blockRepository;
    }

    public function apply(): self
    {
        try {
            $block = $this->blockRepository->getById(CreateSellerInfoSecondaryPromoBlock::BLOCK_IDENTIFIER);
        } catch (NoSuchEntityException $e) {
            return $this;
        }

        $content = <<<HTML
<a href="/the-everest-collection" class="pdp-seller-info-promo-secondary" style="display:block;border-radius:0.75rem;overflow:hidden;">
    <img src="data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20width='460'%20height='400'%3E%3Crect%20width='100%25'%20height='100%25'%20fill='%23123b32'/%3E%3Ctext%20x='50%25'%20y='50%25'%20fill='%23e7c873'%20font-family='sans-serif'%20font-size='18'%20text-anchor='middle'%20dominant-baseline='middle'%3EUpload%20banner%20image%3C/text%3E%3C/svg%3E" alt="Everest Swag - Rep the Outdoors" style="display:block;width:100%;height:auto;" />
</a>
HTML;

        $block->setContent($content);
        $this->blockRepository->save($block);

        return $this;
    }

    public static function getDependencies(): array
    {
        return [CreateSellerInfoSecondaryPromoBlock::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
