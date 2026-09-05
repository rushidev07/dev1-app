<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Swaps the seller-info promo cards' Page Builder banner images to the
 * latest CaliberNation / AmericanStandardGold designs. Admins can still
 * replace them from Content > Blocks > this block > click the image >
 * Insert/Edit Image, no code change needed - this patch only updates the
 * currently-referenced file, the Page Builder row/figure markup itself
 * (added via admin since the earlier Update*Image patches ran) is left
 * untouched so it stays fully editable in Page Builder.
 */
class UpdateSellerInfoPromoCardBannerImages implements DataPatchInterface
{
    private BlockRepositoryInterface $blockRepository;

    public function __construct(BlockRepositoryInterface $blockRepository)
    {
        $this->blockRepository = $blockRepository;
    }

    public function apply(): self
    {
        $this->swapImage(
            CreateSellerInfoPrimaryPromoBlock::BLOCK_IDENTIFIER,
            'ChatGPT_Image_Jul_31_2026_11_28_54_AM.png',
            'rating-asg-banner.png'
        );

        $this->swapImage(
            CreateSellerInfoSecondaryPromoBlock::BLOCK_IDENTIFIER,
            'ChatGPT_Image_Jul_31_2026_11_38_57_AM.png',
            'rating-calliber-banner.png'
        );

        return $this;
    }

    private function swapImage(string $blockIdentifier, string $oldFile, string $newFile): void
    {
        try {
            $block = $this->blockRepository->getById($blockIdentifier);
        } catch (NoSuchEntityException $e) {
            return;
        }

        $block->setContent(str_replace($oldFile, $newFile, $block->getContent()));
        $this->blockRepository->save($block);
    }

    public static function getDependencies(): array
    {
        return [
            UpdateSellerInfoPrimaryPromoBlockImage::class,
            UpdateSellerInfoSecondaryPromoBlockImage::class,
        ];
    }

    public function getAliases(): array
    {
        return [];
    }
}
