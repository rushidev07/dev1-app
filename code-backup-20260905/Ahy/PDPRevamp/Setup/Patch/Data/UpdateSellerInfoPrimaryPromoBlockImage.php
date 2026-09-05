<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Adds an admin-editable image slot to the primary seller-info promo card.
 * Admins can replace the placeholder via Content > Blocks > this block >
 * click the image > Insert/Edit Image, no code change needed.
 */
class UpdateSellerInfoPrimaryPromoBlockImage implements DataPatchInterface
{
    private BlockRepositoryInterface $blockRepository;

    public function __construct(BlockRepositoryInterface $blockRepository)
    {
        $this->blockRepository = $blockRepository;
    }

    public function apply(): self
    {
        try {
            $block = $this->blockRepository->getById(CreateSellerInfoPrimaryPromoBlock::BLOCK_IDENTIFIER);
        } catch (NoSuchEntityException $e) {
            return $this;
        }

        $content = <<<HTML
<div class="pdp-seller-info-promo-primary" style="background:#1a1410;border:1px solid #7a5c1e;border-radius:0.75rem;overflow:hidden;color:#f3e6c9;text-align:center;">
    <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='140'%3E%3Crect width='100%25' height='100%25' fill='%232a2118'/%3E%3C/svg%3E" alt="American Standard Gold banner" style="display:block;width:100%;height:140px;object-fit:cover;" />
    <div style="padding:2rem 1.5rem;">
        <div style="width:56px;height:56px;margin:0 auto 0.75rem;border:2px solid #d4af37;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#d4af37;font-size:1.5rem;">$</div>
        <div style="letter-spacing:0.1em;font-size:0.75rem;color:#d4af37;font-weight:700;">AMERICAN STANDARD GOLD</div>
        <h3 style="font-weight:800;font-size:1.375rem;line-height:1.2;text-transform:uppercase;margin:0.5rem 0;color:#fff;">Protect Your Wealth With Gold</h3>
        <p style="font-size:0.8rem;color:#c9bfa3;margin-bottom:1rem;">Secure your future with precious metals. Trusted by thousands of investors.</p>
        <a href="https://www.americanstandardgold.com" target="_blank" rel="noopener" style="display:inline-block;background:#d4af37;color:#1a1410;font-weight:700;padding:0.6rem 1.25rem;border-radius:9999px;text-decoration:none;font-size:0.85rem;">GET FREE GOLD GUIDE</a>
    </div>
</div>
HTML;

        $block->setContent($content);
        $this->blockRepository->save($block);

        return $this;
    }

    public static function getDependencies(): array
    {
        return [CreateSellerInfoPrimaryPromoBlock::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
