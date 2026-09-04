<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Refreshes the give-back-fund banner CTA from the outlined style to a
 * solid white pill button to match the approved design.
 */
class UpdateGiveBackFundBannerButtonStyle implements DataPatchInterface
{
    private BlockRepositoryInterface $blockRepository;
    private CreateGiveBackFundBannerBlock $bannerBlockPatch;

    public function __construct(
        BlockRepositoryInterface $blockRepository,
        CreateGiveBackFundBannerBlock $bannerBlockPatch
    ) {
        $this->blockRepository = $blockRepository;
        $this->bannerBlockPatch = $bannerBlockPatch;
    }

    public function apply(): self
    {
        try {
            $block = $this->blockRepository->getById(CreateGiveBackFundBannerBlock::BLOCK_IDENTIFIER);
        } catch (NoSuchEntityException $e) {
            return $this;
        }

        $block->setContent($this->bannerBlockPatch->buildContent());
        $this->blockRepository->save($block);

        return $this;
    }

    public static function getDependencies(): array
    {
        return [CreateGiveBackFundBannerBlock::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
