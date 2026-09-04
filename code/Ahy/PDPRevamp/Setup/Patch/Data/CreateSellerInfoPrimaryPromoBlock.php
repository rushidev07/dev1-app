<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * First promo card on the PDP "Seller Info" tab (right column).
 * Admin-editable via Content > Blocks (identifier below) - swap the
 * copy, link, or image from there, no code change needed.
 */
class CreateSellerInfoPrimaryPromoBlock implements DataPatchInterface
{
    public const BLOCK_IDENTIFIER = 'pdp_seller_info_promo_primary';

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
<div class="pdp-seller-info-promo-primary" style="background:#1a1410;border:1px solid #7a5c1e;border-radius:0.75rem;padding:2rem 1.5rem;text-align:center;color:#f3e6c9;">
    <div style="width:56px;height:56px;margin:0 auto 0.75rem;border:2px solid #d4af37;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#d4af37;font-size:1.5rem;">$</div>
    <div style="letter-spacing:0.1em;font-size:0.75rem;color:#d4af37;font-weight:700;">AMERICAN STANDARD GOLD</div>
    <h3 style="font-weight:800;font-size:1.375rem;line-height:1.2;text-transform:uppercase;margin:0.5rem 0;color:#fff;">Protect Your Wealth With Gold</h3>
    <p style="font-size:0.8rem;color:#c9bfa3;margin-bottom:1rem;">Secure your future with precious metals. Trusted by thousands of investors.</p>
    <a href="https://www.americanstandardgold.com" target="_blank" rel="noopener" style="display:inline-block;background:#d4af37;color:#1a1410;font-weight:700;padding:0.6rem 1.25rem;border-radius:9999px;text-decoration:none;font-size:0.85rem;">GET FREE GOLD GUIDE</a>
</div>
HTML;

        $block = $this->blockFactory->create();
        $block->setIdentifier(self::BLOCK_IDENTIFIER)
            ->setTitle('PDP - Seller Info Tab Primary Promo Card')
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
