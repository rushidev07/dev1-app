<?php

namespace Ahy\PDPRevamp\Controller\Index;

use Ahy\PDPRevamp\Logger\Logger as YotpoApiLogger;
use Ahy\PDPRevamp\Service\ReviewPhotoUploader;
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
 * Submitting a review requires being logged in - the same rule, and the
 * same shape of rejection (401, requires_login: true), as
 * Controller\Index\Vote. Enforced here rather than left to the storefront
 * form alone: this endpoint is CSRF-exempt for the same reason Vote is
 * (a plain fetch() call, no form-key token), so without a server-side
 * check it would be an open door for anyone who can craft the POST body
 * themselves, signed in or not.
 */
class Review implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /** @var Context */
    private $context;

    /** @var JsonFactory */
    private $resultJsonFactory;

    /** @var YotpoClient */
    private $yotpoClient;

    /** @var Json */
    private $serializer;

    private CustomerSession $customerSession;

    /** @var YotpoApiLogger */
    private $logger;

    private ReviewPhotoUploader $photoUploader;

    /** Mirrors Yotpo's own images/process limit - see YotpoClient::uploadReviewImages(). */
    private const MAX_IMAGES = 4;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        YotpoClient $yotpoClient,
        Json $serializer,
        CustomerSession $customerSession,
        YotpoApiLogger $logger,
        ReviewPhotoUploader $photoUploader
    ) {
        $this->context = $context;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->yotpoClient = $yotpoClient;
        $this->serializer = $serializer;
        $this->customerSession = $customerSession;
        $this->logger = $logger;
        $this->photoUploader = $photoUploader;
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->customerSession->isLoggedIn()) {
            return $result->setHttpResponseCode(401)->setData([
                'requires_login' => true,
                'message' => __('Please sign in to write a review.'),
            ]);
        }

        $request = $this->context->getRequest();
        $payload = $this->serializer->unserialize($request->getContent() ?: '{}');

        $this->logger->info('createReview request: ' . $this->serializer->serialize($this->loggablePayload($payload)));

        $required = ['sku', 'product_title', 'product_url', 'display_name', 'email', 'review_content', 'review_title', 'review_score'];
        foreach ($required as $field) {
            if (empty($payload[$field]) && $payload[$field] !== 0) {
                $this->logger->error("createReview rejected: $field is required");
                return $result->setHttpResponseCode(400)->setData(['error' => "$field is required"]);
            }
        }

        $response = $this->yotpoClient->createReview($payload);
        $this->logger->info('createReview response: ' . $this->serializer->serialize($response));

        if (!empty($response['image_upload_token']) && !empty($payload['images']) && is_array($payload['images'])) {
            $this->uploadReviewPhotos((string) $response['image_upload_token'], $payload['images']);
        }

        return $result->setData($response);
    }

    /**
     * Yotpo only accepts publicly reachable image URLs (see
     * YotpoClient::uploadReviewImages()), so each base64 photo the shopper
     * attached is saved to our own media storage first. A photo that fails
     * to save or upload is just dropped - it must not fail the review
     * itself, which createReview() has already saved by this point.
     */
    private function uploadReviewPhotos(string $imageUploadToken, array $images): void
    {
        $imageUrls = [];
        foreach (array_slice($images, 0, self::MAX_IMAGES) as $index => $dataUrl) {
            if (!is_string($dataUrl)) {
                continue;
            }
            $url = $this->photoUploader->saveBase64Photo($dataUrl, 'review_photo_' . $index);
            if ($url !== null) {
                $imageUrls[] = $url;
            }
        }

        if ($imageUrls) {
            $this->yotpoClient->uploadReviewImages($imageUploadToken, $imageUrls);
        }
    }

    /**
     * The images field carries base64 data URLs that can run to several MB
     * each - unfit for a log line, and irrelevant to diagnosing a rejected
     * submission, so it's replaced with just a count here.
     */
    private function loggablePayload(array $payload): array
    {
        if (isset($payload['images']) && is_array($payload['images'])) {
            $payload['images'] = count($payload['images']) . ' image(s) omitted';
        }

        return $payload;
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
