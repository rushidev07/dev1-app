<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Observer;

use Ahy\CaliberNation\Model\Service\ActivityLogger;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Audits saves of the Caliber Nation admin configuration section.
 *
 * Fires on admin_system_config_changed_section_caliber_nation (adminhtml only)
 * and writes one activity-log row recording WHO changed the settings, at WHAT
 * scope, and WHICH config paths changed.
 *
 * NOTE: Magento's config-save event exposes the changed config *paths* but not
 * their previous/new values, so we log the paths (not a before→after diff).
 * That is the correct low-cost audit signal here; full value diffing would need
 * a dedicated audit mechanism.
 */
class LogAdminConfigChange implements ObserverInterface
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly AuthSession $authSession
    ) {}

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();

        $changedPaths = $event->getData('changed_paths');
        // Section saved but nothing actually changed → don't create a noise row.
        if (empty($changedPaths) || !\is_array($changedPaths)) {
            return;
        }

        $website = (string) $event->getData('website');
        $store   = (string) $event->getData('store');
        $scope   = $store !== '' ? "store={$store}" : ($website !== '' ? "website={$website}" : 'default');

        $detail = sprintf(
            'Caliber Nation config saved [%s]; changed: %s',
            $scope,
            implode(', ', $changedPaths)
        );

        $adminId = null;
        if ($this->authSession->isLoggedIn() && $this->authSession->getUser()) {
            $adminId = (int) $this->authSession->getUser()->getId() ?: null;
        }

        $this->activityLogger->log(
            null,
            ActivityLogger::ACTION_CONFIG_CHANGE,
            $detail,
            null,
            $adminId
        );
    }
}
