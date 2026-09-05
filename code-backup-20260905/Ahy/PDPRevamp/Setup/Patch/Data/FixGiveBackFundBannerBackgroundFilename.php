<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Re-pushes CreateGiveBackFundBannerBlock's content again. The previous
 * refresh patch (UpdateGiveBackFundBannerImage) had already run - and run
 * with content that still pointed at "wysiwyg/home/give-back-fund-bg.jpg",
 * a filename that doesn't actually exist (the real file on disk is
 * give-back-fund-bg.png, wrong extension) - before buildContent() was
 * updated here to point at the trail-scene photo instead. Patches only run
 * once, so editing the source after the fact didn't touch the already-saved
 * block; this pushes the corrected content live.
 */
class FixGiveBackFundBannerBackgroundFilename implements DataPatchInterface
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
        return [UpdateGiveBackFundBannerImage::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
