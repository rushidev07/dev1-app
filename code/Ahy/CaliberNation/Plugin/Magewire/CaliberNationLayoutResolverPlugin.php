<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\Plugin\Magewire;

use Magewirephp\Magewire\Component;
use Magewirephp\Magewire\Model\Component\Resolver\Layout;
use Magewirephp\Magewire\Model\RequestInterface;

/**
 * Fixes Magewire AJAX reconstruction for components defined on entity-specific
 * CMS page layout handles (e.g. cms_page_view_id_caliber-nation).
 *
 * Magewire stores the action-level handle (cms_page_view) in the fingerprint,
 * but our block is defined under cms_page_view_id_caliber-nation. This plugin
 * patches the fingerprint handle before the resolver tries to find the block.
 */
class CaliberNationLayoutResolverPlugin
{
    /**
     * Maps Magewire block names → the layout handle they actually live under.
     */
    private const BLOCK_HANDLE_MAP = [
        'caliber_nation_membership_signup' => 'cms_page_view_id_caliber-nation',
        'caliber_nation_membership_signup_register' => 'customer_account_create',
    ];

    public function beforeReconstruct(Layout $subject, RequestInterface $request): array
    {
        $blockName = $request->getFingerprint('name');

        if ($blockName !== null && isset(self::BLOCK_HANDLE_MAP[$blockName])) {
            $fingerprint = $request->getFingerprint();
            $fingerprint['handle'] = self::BLOCK_HANDLE_MAP[$blockName];
            $request->setFingerprint($fingerprint);
        }

        return [$request];
    }
}
