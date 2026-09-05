<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Controller\Index;

use Ahy\PDPRevamp\Model\ResourceModel\ReviewVote;
use Ahy\PDPRevamp\Service\YotpoClient;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Casts or retracts a customer's "helpful" vote on a review.
 *
 * Helpful is the only direction - there is no "not helpful" vote.
 *
 * Voting requires being logged in, and each customer gets one vote per review.
 * Neither can be enforced by Yotpo: the vote request it accepts carries no
 * customer identifier, and it answers 200 OK to every request - including
 * nonexistent review ids and invalid vote types - so it can neither attribute a
 * vote nor report whether one was counted. The local
 * ahy_pdprevamp_review_vote table is therefore the record of who voted, while
 * Yotpo remains the source of truth for the counts themselves.
 *
 * Previously the storefront tracked "have I voted" purely through a CSS class on
 * the thumb icon, so a page reload cleared it and the same visitor - logged in
 * or not - could vote again on every load.
 */
class Vote implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private Context $context;
    private JsonFactory $resultJsonFactory;
    private YotpoClient $yotpoClient;
    private Json $serializer;
    private CustomerSession $customerSession;
    private ReviewVote $reviewVote;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        YotpoClient $yotpoClient,
        Json $serializer,
        CustomerSession $customerSession,
        ReviewVote $reviewVote
    ) {
        $this->context = $context;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->yotpoClient = $yotpoClient;
        $this->serializer = $serializer;
        $this->customerSession = $customerSession;
        $this->reviewVote = $reviewVote;
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();

        try {
            $payload = $this->serializer->unserialize($this->context->getRequest()->getContent() ?: '{}');
        } catch (\InvalidArgumentException $e) {
            $payload = [];
        }
        if (!is_array($payload)) {
            $payload = [];
        }

        $reviewId = trim((string) ($payload['review_id'] ?? ''));
        $voteType = (string) ($payload['vote_type'] ?? '');
        $productId = (int) ($payload['product_id'] ?? 0);

        // Retracting is an explicit request, never inferred from "you already
        // hold this direction". Without this flag a duplicate submit of the same
        // direction - a double click, or a template whose click handler fires on
        // render - reads as a toggle and silently deletes a vote the customer
        // meant to keep.
        $wantsRetraction = !empty($payload['unvote']);

        if ($reviewId === '' || !ReviewVote::isValidVoteType($voteType)) {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'error' => 'review_id and vote_type (up) are required',
            ]);
        }

        // Anonymous visitors are refused before Yotpo is contacted at all. This
        // is also what stops this endpoint being an open vote-stuffing target,
        // given it is deliberately CSRF-exempt for the storefront's fetch() call.
        if (!$this->customerSession->isLoggedIn()) {
            return $result->setHttpResponseCode(401)->setData([
                'success' => false,
                'requires_login' => true,
                'message' => __('Please sign in to vote on reviews.'),
            ]);
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        $existingVote = $this->reviewVote->getVote($reviewId, $customerId);

        // A retraction requires both an explicit unvote flag and the customer
        // actually holding that direction. Re-sending the same direction without
        // the flag is therefore a no-op rather than a toggle, so a duplicate
        // request cannot remove a vote by accident.
        $isRetraction = $wantsRetraction && $existingVote === $voteType;

        if (!$wantsRetraction && $existingVote === $voteType) {
            return $result->setData([
                'success' => true,
                'my_vote' => $existingVote,
                'previous_vote' => $existingVote,
                'unchanged' => true,
            ]);
        }

        try {
            if ($isRetraction) {
                $this->reviewVote->removeVote($reviewId, $customerId);
            } else {
                $this->reviewVote->saveVote($reviewId, $customerId, $voteType);
            }
        } catch (\Throwable $e) {
            // Includes the unique-constraint path: if a concurrent request won
            // the race, the vote is already recorded and this one is a no-op.
            return $result->setHttpResponseCode(409)->setData([
                'success' => false,
                'my_vote' => $existingVote,
                'message' => __('Your vote could not be saved. Please try again.'),
            ]);
        }

        // Deliberately NOT forwarded to Yotpo any more.
        //
        // Yotpo's public vote endpoint does not persist what we send it: it
        // answers 200 OK and leaves votes_up untouched (confirmed on dev - a
        // recorded vote left Yotpo's votes_up at 8, and the live API returns 200
        // OK even for a nonexistent review id or vote/sideways). Calling it
        // therefore added a blocking HTTP round trip per click in exchange for
        // nothing.
        //
        // The vote lives in ahy_pdprevamp_review_vote instead, and
        // Controller/Index/Reviews adds this module's tallies on top of Yotpo's
        // own number when the reviews list is served. Yotpo's count remains the
        // baseline for votes cast through its widget.
        //
        // The cached reviews payload still has to be dropped: it holds the
        // pre-vote totals, and without this the new count would not appear until
        // the cache's 15 minute lifetime expired.
        if ($productId > 0) {
            $this->yotpoClient->invalidateProductReviews($productId);
        }

        return $result->setData([
            'success' => true,
            'my_vote' => $isRetraction ? null : $voteType,
            'previous_vote' => $existingVote,
        ]);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
