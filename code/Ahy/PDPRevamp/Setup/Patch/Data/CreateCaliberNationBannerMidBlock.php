<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Wide CaliberNation banner shown above the "Frequently Bought Together"
 * section on PDP. Admin-editable via Content > Blocks (identifier below) -
 * swap the image or link from there, no code change needed.
 */
class CreateCaliberNationBannerMidBlock implements DataPatchInterface
{
    public const BLOCK_IDENTIFIER = 'pdp_calibernation_banner_mid';

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
<a href="{{store url='caliber-nation'}}" class="pdp-cn-banner-mid" style="display:block;">
    <img src="{{media url="wysiwyg/ads/CaliberNationBannermid.png"}}" alt="Caliber Nation - Join Now and Save" style="width:100%;height:auto;" />
</a>
HTML;

        $block = $this->blockFactory->create();
        $block->setIdentifier(self::BLOCK_IDENTIFIER)
            ->setTitle('PDP CaliberNation Banner Mid')
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
