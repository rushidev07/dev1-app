<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Historical fix, kept for the record on environments where it already
 * ran: re-pushes the give-back-fund banner content after an earlier
 * attempt to fix its unstyled rendering. The actual root cause - Tailwind
 * arbitrary-value classes living in this module's .php files, which
 * Tailwind's build never scans (it only scans *.phtml) - was later
 * resolved by SimplifyGiveBackFundBannerBlockToImageOnly, which moved all
 * styled markup into give-back-banner.phtml and reduced this CMS block to
 * just the admin-editable background image.
 */
class FixGiveBackFundBannerBackgroundStyle implements DataPatchInterface
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
