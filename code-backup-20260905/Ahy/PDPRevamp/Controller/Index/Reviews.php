<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Controller\Index;

use Ahy\PDPRevamp\Model\ResourceModel\ReviewVote;
use Ahy\PDPRevamp\Service\YotpoClient;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Reviews for a product, as consumed by the PDP reviews section.
 *
 * Also stamps the current customer's own vote onto each review, which is what
 * lets the storefront show a filled thumb after a page reload instead of losing
 * that state (it used to live only in a CSS class).
 *
 * The stamping happens here rather than inside YotpoClient::getReviews() on
 * purpose: that method's result is cached for 15 minutes under a shared cache
 * tag, and Varnish full page cache is enabled on this install. Per-customer data
 * must therefore never enter it, or the first visitor's votes would be served to
 * everybody. Adding it after the fact keeps the cached payload
 * customer-agnostic, and this controller is already an uncached AJAX endpoint.
 */
class Reviews implements HttpGetActionInterface
{
    private Context $context;
    private JsonFactory $resultJsonFactory;
    private YotpoClient $yotpoClient;
    private CustomerSession $customerSession;
    private ReviewVote $reviewVote;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        YotpoClient $yotpoClient,
        CustomerSession $customerSession,
        ReviewVote $reviewVote
    ) {
        $this->context = $context;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->yotpoClient = $yotpoClient;
        $this->customerSession = $customerSession;
        $this->reviewVote = $reviewVote;
    }

    public function execute(): ResultInterface
    {
        $request = $this->context->getRequest();
        $productId = (int) $request->getParam('product_id');
        $perPage = (int) $request->getParam('per_page', 150);

        $result = $this->resultJsonFactory->create();

        if (!$productId) {
            return $result->setHttpResponseCode(400)->setData(['error' => 'product_id is required']);
        }

        $data = $this->yotpoClient->getReviews($productId, $perPage);

        return $result->setData($this->addCustomerVotes($data));
    }

    /**
     * Adds "my_vote" to every review, folds this module's own vote tallies into
     * the displayed count, and adds "can_vote" to the response root.
     *
     * my_vote is "up" or null. Only helpful votes exist - there is no
     * "not helpful" direction.
     *
     * The count needs adjusting because Yotpo does not persist the votes we send
     * it: its public vote endpoint returns 200 OK and leaves votes_up unchanged,
     * so a vote cast here would vanish from the display on the next page load
     * even though it is recorded locally. Yotpo's number is treated as the
     * baseline (votes cast through its own widget) and this module's tally is
     * added on top.
     */
    private function addCustomerVotes(array $data): array
    {
        $isLoggedIn = $this->customerSession->isLoggedIn();
        $data['can_vote'] = $isLoggedIn;

        if (!isset($data['response']['reviews']) || !is_array($data['response']['reviews'])) {
            return $data;
        }

        $reviews = $data['response']['reviews'];

        $reviewIds = [];
        foreach ($reviews as $review) {
            if (is_array($review) && isset($review['id'])) {
                $reviewIds[] = (string) $review['id'];
            }
        }

        // Two batched queries for the whole page, not one pair per review: the
        // tallies (everyone's votes) and, when signed in, this customer's own.
        $tallies = $this->reviewVote->getVoteTallies($reviewIds);
        $myVotes = $isLoggedIn
            ? $this->reviewVote->getVotesByReviewIds($reviewIds, (int) $this->customerSession->getCustomerId())
            : [];

        foreach ($reviews as $index => $review) {
            if (!is_array($review)) {
                continue;
            }

            $reviewId = isset($review['id']) ? (string) $review['id'] : '';

            $reviews[$index]['votes_up'] = (int) ($review['votes_up'] ?? 0) + ($tallies[$reviewId] ?? 0);

            // Guests read the count but hold no vote of their own.
            $reviews[$index]['my_vote'] = $myVotes[$reviewId] ?? null;
        }

        $data['response']['reviews'] = $reviews;

        return $data;
    }
}
