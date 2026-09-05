<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Sponsor banner shown next to the "Frequently Bought Together" section.
 * Admin-editable via Content > Blocks (identifier below) - swap the image,
 * link, or replace the whole block content from there, no code change
 * needed.
 */
class CreateFbtSponsorBannerBlock implements DataPatchInterface
{
    public const BLOCK_IDENTIFIER = 'pdp_fbt_sponsor_banner';

    private BlockRepositoryInterface $blockRepository;
    private BlockInterfaceFactory $blockFactory;

    public function __construct(
        BlockRepositoryInterface $blockRepository,
        BlockInterfaceFactory $blockFactory
    ) {
        $this->blockRepository = $blockRepository;
        $this->blockFactory = $blockFactory;
    }

    public function apply(): self
    {
        try {
            $this->blockRepository->getById(self::BLOCK_IDENTIFIER);
            return $this;
        } catch (NoSuchEntityException $e) {
            // block does not exist yet - create it below
        }

        $content = <<<HTML
<a href="https://www.sellmark.com" target="_blank" rel="noopener" class="pdp-fbt-sponsor-banner" style="display:block;">
    <img src="{{media url="wysiwyg/ads/sellmark-banner.png"}}" alt="Sellmark - Top Brands. Unbeatable Value. Adventure Ready." style="width:100%;height:auto;border-radius:0.75rem;" />
</a>
HTML;

        $block = $this->blockFactory->create();
        $block->setIdentifier(self::BLOCK_IDENTIFIER)
            ->setTitle('PDP - Frequently Bought Together Sponsor Banner')
            ->setContent($content)
            ->setIsActive(1)
            ->setData('stores', [0]);
        $this->blockRepository->save($block);

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
