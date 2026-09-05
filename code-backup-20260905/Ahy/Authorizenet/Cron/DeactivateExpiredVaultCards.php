<?php

namespace Ahy\Authorizenet\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Notification\NotifierInterface;
use Psr\Log\LoggerInterface;

class DeactivateExpiredVaultCards
{
    private $resource;
    private $logger;
    private $notifier;

    public function __construct(
        ResourceConnection $resource,
        LoggerInterface $logger,
        NotifierInterface $notifier
    ) {
        $this->resource = $resource;
        $this->logger = $logger;
        $this->notifier = $notifier;
    }

    /**
     * Monthly job: deactivates vault tokens whose printed expiration has passed.
     * Sets is_active = 0 only. is_visible is left at 1 so the customer still sees
     * the expired card on the account dashboard and can delete it manually.
     *
     * Posts an admin notification on every run so the cron's execution can be
     * confirmed from the admin notification bell without DB or log access.
     */
    public function execute(): void
    {
        $connection = $this->resource->getConnection();
        $tokenTable = $this->resource->getTableName('vault_payment_token');

        $expiredTokenIds = $connection->fetchCol(
            $connection->select()
                ->from($tokenTable, ['entity_id'])
                ->where('is_active = ?', 1)
                ->where('expires_at < ?', new \Zend_Db_Expr('NOW()'))
        );

        $runTimestamp = gmdate('Y-m-d H:i:s');

        if (empty($expiredTokenIds)) {
            $this->logger->info('[ExpireVaultCards] No expired tokens found.');
            $this->sendAdminNotification(
                'Saved Cards Expiry Cron Ran',
                sprintf('Ran at %s UTC. No expired tokens were found.', $runTimestamp)
            );
            return;
        }

        $connection->update(
            $tokenTable,
            ['is_active' => 0],
            ['entity_id IN (?)' => $expiredTokenIds]
        );

        $this->logger->info(sprintf(
            '[ExpireVaultCards] Deactivated %d expired tokens: %s',
            count($expiredTokenIds),
            implode(',', $expiredTokenIds)
        ));

        $this->sendAdminNotification(
            'Saved Cards Expiry Cron Ran',
            sprintf(
                'Ran at %s UTC. Deactivated %d expired token(s). Token IDs: [%s].',
                $runTimestamp,
                count($expiredTokenIds),
                implode(', ', $expiredTokenIds)
            )
        );
    }

    /**
     * Failures from the notifier must never break the cron itself, so this is
     * wrapped in a try/catch and only logged.
     */
    private function sendAdminNotification(string $title, string $description): void
    {
        try {
            $this->notifier->addNotice($title, $description);
        } catch (\Exception $e) {
            $this->logger->error('[ExpireVaultCards] Failed to post admin notification: ' . $e->getMessage());
        }
    }
}
