<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CreateGiveBackFundBannerBlock implements DataPatchInterface
{
    public const BLOCK_IDENTIFIER = 'home_give_back_fund_banner';

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
            // block does not exist yet — create it below
        }

        $block = $this->blockFactory->create();
        $block->setIdentifier(self::BLOCK_IDENTIFIER)
            ->setTitle('Home - Give Back Fund Banner')
            ->setContent($this->buildContent())
            ->setIsActive(1)
            ->setData('stores', [0]);
        $this->blockRepository->save($block);

        return $this;
    }

    /**
     * Public so it can be reused to refresh an already-existing block's
     * content without needing a new patch class - patches only run once
     * and never re-apply to existing rows.
     *
     * The block content is intentionally just the background image
     * directive - heading, copy, and button markup live in
     * give-back-banner.phtml instead. That HTML previously lived here as a
     * Tailwind-classed string, but Tailwind's build only scans *.phtml
     * files, so those classes were always purged from the compiled CSS and
     * the banner rendered unstyled. Keeping only the image here means
     * admins can still swap it from Admin > Content > Blocks without a
     * deploy, while the styling that must compile reliably stays in code.
     */
    public function buildContent(): string
    {
        return '{{media url="wysiwyg/trail-scene-hQPPKttk7bXk6NUwDqkNsr.png"}}';
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
