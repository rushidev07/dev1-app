<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Observer\Category;

use Ahy\PlpRevamp\Model\Category\FeaturedCategoryManager;
use Magento\Catalog\Model\Category;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates the "Featured Products" child whenever a NEW category is saved, and
 * assigns it to the new category's ahy_featured_source_id.
 *
 * Only fires for newly created categories — editing an existing one never
 * triggers it, so an admin who deliberately clears the field does not have it
 * silently refilled. Use ahy:plp:featured-categories:create to backfill.
 *
 * Re-entrancy: saving the child dispatches this same event. The child carries
 * ahy_is_featured_container=1, which isEligible() rejects. The parent's own
 * attribute write goes through saveAttribute(), which does not dispatch a save
 * event at all. The $running latch is a third line of defence.
 */
class CreateFeaturedCategoryOnSave implements ObserverInterface
{
    private bool $running = false;

    public function __construct(
        private readonly FeaturedCategoryManager $manager,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(Observer $observer): void
    {
        if ($this->running) {
            return;
        }

        $category = $observer->getEvent()->getData('category');
        if (!$category instanceof Category) {
            return;
        }

        // Only on creation. isObjectNew() is still true here; origData has no
        // entity_id for a row that did not exist before this save, which covers
        // the versions where the flag is reset earlier.
        $isNew = $category->isObjectNew() || !$category->getOrigData('entity_id');
        if (!$isNew) {
            return;
        }

        if (!$this->manager->isEligible($category)) {
            return;
        }

        $this->running = true;
        try {
            $childId = $this->manager->create($category);
            $this->logger->info(sprintf(
                '[Ahy_PlpRevamp] Created Featured Products category #%d for new category #%d (%s).',
                $childId,
                (int) $category->getId(),
                (string) $category->getName()
            ));
        } catch (\Throwable $e) {
            // Never let this break the admin's category save — the backfill command
            // can repair anything missed here.
            $this->logger->error(sprintf(
                '[Ahy_PlpRevamp] Could not create Featured Products category for #%d: %s',
                (int) $category->getId(),
                $e->getMessage()
            ));
        } finally {
            $this->running = false;
        }
    }
}
