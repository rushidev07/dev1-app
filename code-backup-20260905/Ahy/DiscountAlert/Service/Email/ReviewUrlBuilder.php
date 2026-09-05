<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Service\Email;

use Ahy\DiscountAlert\Api\Data\RunInterface;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;

/**
 * Builds the admin deep link carried by the alert email's review button.
 */
class ReviewUrlBuilder
{
    public const ROUTE_RUN_VIEW = 'ahy_discount_alert/run/view';

    public function __construct(
        private readonly BackendUrlInterface $backendUrl
    ) {}

    /**
     * Cron and CLI have no admin session and therefore cannot mint the admin URL secret
     * key, so the link is built without one; the run token identifies the run and admin
     * authentication plus the ACL guard the page itself.
     */
    public function build(RunInterface $run): string
    {
        return (string) $this->backendUrl->getUrl(self::ROUTE_RUN_VIEW, [
            'run_id'    => (int) $run->getRunId(),
            'token'     => $run->getToken(),
            '_nosecret' => true,
        ]);
    }
}
