<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CreateCaliberNationBannerBlock implements DataPatchInterface
{
    public const BLOCK_IDENTIFIER = 'pdp_calibernation_banner';

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
            ->setTitle('PDP CaliberNation Banner')
            ->setContent($this->buildContent())
            ->setIsActive(1)
            ->setData('stores', [0]);
        $this->blockRepository->save($block);

        return $this;
    }

    /**
     * Public so it can be reused to refresh an already-existing block's
     * content (e.g. after an icon/copy change) without needing a new patch
     * class - patches only run once and never re-apply to existing rows.
     */
    public function buildContent(): string
    {
        $perks = [
            [
                'Member Discounts',
                'Save up to 90% on top gear &amp; more.',
                '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
            ],
            [
                'Free Priority Shipping',
                'Enjoy free priority shipping on eligible items.',
                '<path d="M10 17h4V5H2v12h3"/><path d="M20 17h2v-3.34a4 4 0 0 0-1.17-2.83L19 9h-5v8h1"/><circle cx="7.5" cy="17.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
            ],
            [
                'Early-Bird Access',
                'Get early access to sales &amp; new products.',
                '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            ],
            [
                'Spotlight Deals',
                'Unlock exclusive deals only members get.',
                '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
            ],
            [
                'Event Access',
                'Savings &amp; access to events, venues &amp; entertainment.',
                '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
            ],
            [
                'Travel &amp; More',
                'Save on travel, food &amp; everyday essentials.',
                '<path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.4 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/>',
            ],
        ];

        $perkHtml = '';
        foreach ($perks as [$title, $caption, $iconPaths]) {
            $perkHtml .= '<div style="flex:1;min-width:110px;max-width:150px;text-align:center;padding:0 10px;border-left:1px solid rgba(30,49,84,.15);">'
                . '<div style="width:56px;height:56px;margin:0 auto 8px;border-radius:50%;background:#f5efe4;display:flex;align-items:center;justify-content:center;">'
                . '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#1e3154" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' . $iconPaths . '</svg>'
                . '</div>'
                . '<div style="font-weight:800;font-size:13px;letter-spacing:.02em;color:#1e3154;text-transform:uppercase;line-height:1.2;">' . $title . '</div>'
                . '<div style="font-size:11.5px;color:#4a5a74;margin-top:4px;line-height:1.35;">' . $caption . '</div>'
                . '</div>';
        }

        $content = <<<HTML
<div class="pdp-cn-banner" style="display:flex;align-items:stretch;background:#1e3154;overflow:hidden;">
  <div style="display:flex;align-items:center;gap:24px;padding:20px 28px;color:#fff;min-width:340px;">
    <div style="text-align:center;">
      <svg width="64" height="40" viewBox="0 0 64 40" fill="none" stroke="#fff" stroke-width="2"><path d="M6 34L24 8l10 14 6-8 18 20"/></svg>
      <div style="font-size:15px;font-weight:700;letter-spacing:.03em;">caliber<span style="color:#c9553e;">.</span>nation</div>
      <div style="font-size:10px;opacity:.75;">by everest</div>
    </div>
    <div>
      <div style="font-size:26px;font-weight:900;letter-spacing:.02em;line-height:1.1;">JOIN CALIBER NATION</div>
      <div style="font-size:14px;opacity:.9;margin-top:6px;">Exclusive perks. Epic savings.<br/>A community built for adventurers.</div>
    </div>
  </div>
  <div class="pdp-cn-perks" style="flex:1;display:flex;align-items:center;justify-content:center;background:#efe7d7;padding:16px 8px;">
    {$perkHtml}
  </div>
  <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:20px 32px;background:#efe7d7;">
    <div style="font-size:20px;font-weight:900;color:#1e3154;line-height:1.25;">GEAR UP.<br/>SAVE BIG.<br/>STAY ADVENTUROUS.</div>
    <a href="{{store url='caliber-nation'}}" style="background:#c9553e;color:#fff;font-weight:800;font-size:14px;letter-spacing:.05em;padding:12px 34px;border-radius:24px;text-decoration:none;">JOIN NOW</a>
  </div>
</div>
<style>
@media (max-width: 1024px) { .pdp-cn-perks { display:none !important; } }
@media (max-width: 640px) { .pdp-cn-banner { flex-direction:column; } }
</style>
HTML;

        return $content;
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