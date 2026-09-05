<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Second promo card on the PDP "Seller Info" tab (right column).
 * Admin-editable via Content > Blocks (identifier below) - swap the
 * copy, link, or image from there, no code change needed.
 */
class CreateSellerInfoSecondaryPromoBlock implements DataPatchInterface
{
    public const BLOCK_IDENTIFIER = 'pdp_seller_info_promo_secondary';

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
<div class="pdp-seller-info-promo-secondary" style="background:#0d2f28;border-radius:0.75rem;padding:2rem 1.5rem;text-align:center;color:#fff;">
    <div style="font-weight:800;font-size:1rem;letter-spacing:0.05em;">&#9650; EVEREST</div>
    <div style="font-size:0.7rem;letter-spacing:0.1em;color:#a9c9bd;margin-bottom:0.5rem;">WHAT'S YOUR EVEREST?</div>
    <h3 style="font-weight:800;font-size:1.5rem;text-transform:uppercase;margin:0.5rem 0;">Everest Swag</h3>
    <p style="font-size:0.8rem;color:#c8ded6;margin-bottom:1rem;">Rep the Outdoors</p>
    <div style="display:flex;justify-content:center;gap:1.5rem;margin-bottom:1.25rem;font-size:0.75rem;color:#e6f2ee;">
        <span>Hoodies</span>
        <span>Hats</span>
        <span>Gear</span>
    </div>
    <a href="/the-everest-collection" style="display:inline-block;background:#e7c873;color:#0d2f28;font-weight:700;padding:0.6rem 1.25rem;border-radius:9999px;text-decoration:none;font-size:0.85rem;">Shop Branded Gear</a>
</div>
HTML;

        $block = $this->blockFactory->create();
        $block->setIdentifier(self::BLOCK_IDENTIFIER)
            ->setTitle('PDP - Seller Info Tab Secondary Promo Card')
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
