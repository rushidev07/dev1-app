<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service;

use Ahy\CaliberNation\Model\Config;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Single place that sends the Caliber Nation transactional emails (welcome,
 * renewal, cancellation). Best-effort: a send failure never breaks the caller.
 * Emails are disabled on local (logged, not delivered) — verify via SMTP Email Logs.
 */
class MembershipEmailService
{
    public function __construct(
        private readonly Config $config,
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @param array<string,mixed> $vars extra template vars (merged over the defaults)
     */
    public function send(string $templateId, int $customerId, array $vars = []): bool
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            $store    = $this->storeManager->getStore();
            $storeId  = (int) $store->getId();

            $vars = array_merge([
                'first_name'  => $customer->getFirstname() ?: 'Member',
                'store_name'  => $store->getName(),
                'account_url' => $this->urlBuilder->getUrl('customer/account'),
            ], $vars);

            $this->inlineTranslation->suspend();
            $transport = $this->transportBuilder
                ->setTemplateIdentifier($templateId)
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars($vars)
                ->setFromByScope($this->config->getEmailSender((string) $storeId) ?: 'general', $storeId)
                ->addTo($customer->getEmail())
                ->getTransport();
            $transport->sendMessage();
            return true;
        } catch (\Exception $e) {
            $this->logger->warning('[CaliberNation] email "' . $templateId . '" failed: ' . $e->getMessage());
            return false;
        } finally {
            $this->inlineTranslation->resume();
        }
    }
}
