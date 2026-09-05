<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Re-pushes CreateGiveBackFundBannerBlock's content (now pointing at the
 * trail-scene background image) to fix the live block. The live content had
 * been edited via Admin's Page Builder "HTML Code" widget and, in the
 * process, an `<img>` tag had been pasted directly inside the button's
 * `href="{{store url='...'}}"` attribute - malformed markup that made the
 * browser unable to parse the surrounding tag, so raw attribute text leaked
 * onto the page instead of rendering as the button. Patches only run once,
 * so simply editing CreateGiveBackFundBannerBlock's source doesn't affect
 * the already-saved (and broken) block - this overwrites it with clean
 * content again.
 */
class UpdateGiveBackFundBannerImage implements DataPatchInterface
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
