<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Controller\Index;

use Ahy\PDPRevamp\Model\ResourceModel\ExitCoupon;
use Ahy\PDPRevamp\Service\ExitPopupCouponMailer;
use Ahy\PDPRevamp\Service\ExitPopupCouponService;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Serialize\Serializer\Json;

class Subscribe implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * Per-IP throttle. The endpoint is deliberately CSRF-exempt so the popup's
     * fetch() can reach it without a form key, and it now hands out real discount
     * codes - so without a limit it is a code-farming endpoint open to anyone.
     */
    private const RATE_LIMIT_ATTEMPTS = 5;
    private const RATE_LIMIT_WINDOW_SECONDS = 3600;
    private const RATE_LIMIT_CACHE_PREFIX = 'ahy_exit_popup_claim_';

    private Context $context;
    private JsonFactory $resultJsonFactory;
    private Json $serializer;
    private CustomerSession $customerSession;
    private ExitPopupCouponService $couponService;
    private ExitCoupon $exitCoupon;
    private CacheInterface $cache;
    private ExitPopupCouponMailer $couponMailer;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Json $serializer,
        CustomerSession $customerSession,
        ExitPopupCouponService $couponService,
        ExitCoupon $exitCoupon,
        CacheInterface $cache,
        ExitPopupCouponMailer $couponMailer
    ) {
        $this->context = $context;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->serializer = $serializer;
        $this->customerSession = $customerSession;
        $this->couponService = $couponService;
        $this->exitCoupon = $exitCoupon;
        $this->cache = $cache;
        $this->couponMailer = $couponMailer;
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();
        $request = $this->context->getRequest();

        try {
            $payload = $this->serializer->unserialize($request->getContent() ?: '{}');
        } catch (\InvalidArgumentException $e) {
            $payload = [];
        }
        if (!is_array($payload)) {
            $payload = [];
        }

        $email = trim((string) ($payload['email'] ?? ''));
        $productId = (int) ($payload['product_id'] ?? 0);

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => __('Please enter a valid email address.'),
            ]);
        }

        if (!$this->withinRateLimit($request)) {
            return $result->setHttpResponseCode(429)->setData([
                'success' => false,
                'message' => __('Too many attempts. Please try again later.'),
            ]);
        }

        $customerId = $this->customerSession->isLoggedIn()
            ? (int) $this->customerSession->getCustomerId()
            : null;

        $emailOnly = $this->couponMailer->isEnabled();

        $existing = $this->exitCoupon->getClaimByEmail($email);
        if ($existing !== null) {
            if ($customerId !== null) {
                $this->exitCoupon->linkCustomerByEmail($email, $customerId);
            }

            if ($this->isClaimSpent($existing)) {
                return $result->setHttpResponseCode(409)->setData([
                    'success' => false,
                    'already_claimed' => true,
                    'already_used' => true,
                    'message' => __('You have already used this offer.'),
                ]);
            }

            if ($this->isClaimExpired($existing)) {
                return $result->setHttpResponseCode(409)->setData([
                    'success' => false,
                    'already_claimed' => true,
                    'expired' => true,
                    'message' => __('Your discount code has expired.'),
                ]);
            }
            if ($emailOnly) {
                $this->couponMailer->send(
                    $email,
                    (string) $existing['coupon_code'],
                    $this->couponService->getDiscountPercent(),
                    $existing['expires_at'] ?? null,
                    // The product from the original claim, not whichever PDP this
                    // resubmit came from - the coupon is tied to the first one.
                    isset($existing['product_id']) ? (int) $existing['product_id'] : null
                );

                return $result->setData([
                    'success' => true,
                    'already_claimed' => true,
                    'emailed' => true,
                    'message' => __('You have already claimed this offer - we have emailed your code again.'),
                ]);
            }

            return $result->setData([
                'success' => true,
                'coupon_code' => $existing['coupon_code'],
                'already_claimed' => true,
                'message' => __('You have already claimed this offer - here is your code again.'),
            ]);
        }

        if ($customerId !== null && $this->exitCoupon->customerHasClaim($customerId)) {
            return $result->setHttpResponseCode(409)->setData([
                'success' => false,
                'already_claimed' => true,
                'message' => __('You have already claimed this offer on your account.'),
            ]);
        }

        $code = $this->couponService->generateCode();
        if ($code === null) {
            return $result->setHttpResponseCode(500)->setData([
                'success' => false,
                'message' => __('Something went wrong. Please try again.'),
            ]);
        }

        try {
            $this->exitCoupon->saveClaim(
                $email,
                $code,
                $customerId,
                $productId,
                $this->couponService->getExpiryFor($code)
            );
        } catch (\Throwable $e) {
            // Lost a race against a concurrent claim on the same address - the
            // other request won, so return its code rather than failing.
            $existing = $this->exitCoupon->getClaimByEmail($email);
            if ($existing !== null) {
                $raced = [
                    'success' => true,
                    'already_claimed' => true,
                ];

                if ($emailOnly) {
                    $this->couponMailer->send(
                        $email,
                        (string) $existing['coupon_code'],
                        $this->couponService->getDiscountPercent(),
                        $existing['expires_at'] ?? null,
                        isset($existing['product_id']) ? (int) $existing['product_id'] : null
                    );
                    $raced['emailed'] = true;
                } else {
                    $raced['coupon_code'] = $existing['coupon_code'];
                }

                return $result->setData($raced);
            }

            return $result->setHttpResponseCode(500)->setData([
                'success' => false,
                'message' => __('Something went wrong. Please try again.'),
            ]);
        }

        $discountPercent = $this->couponService->getDiscountPercent();
        $emailed = $this->couponMailer->send(
            $email,
            $code,
            $discountPercent,
            $this->couponService->getExpiryFor($code),
            $productId
        );

        $response = [
            'success' => true,
            'discount_percent' => $discountPercent,
            'emailed' => $emailed,
        ];

        if (!$emailOnly) {
            $response['coupon_code'] = $code;
        }

        return $result->setData($response);
    }
    private function isClaimSpent(array $claim): bool
    {
        if (!array_key_exists('times_used', $claim) || $claim['times_used'] === null) {
            return true;
        }

        return (int) $claim['times_used'] > 0;
    }
    private function isClaimExpired(array $claim): bool
    {
        $expiresAt = $claim['expires_at'] ?? null;

        if (!$expiresAt) {
            return false;
        }

        try {
            $expiry = new \DateTime((string) $expiresAt, new \DateTimeZone('UTC'));
            $now = new \DateTime('now', new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            // An unparseable stored date should not lock the customer out.
            return false;
        }

        return $expiry < $now;
    }

    /**
     * Cache-backed per-IP counter. Deliberately coarse: it exists to stop
     * scripted code farming, not to police a customer mistyping their address.
     */
    private function withinRateLimit(RequestInterface $request): bool
    {
        $ip = method_exists($request, 'getClientIp') ? (string) $request->getClientIp() : '';
        if ($ip === '') {
            return true;
        }

        $key = self::RATE_LIMIT_CACHE_PREFIX . md5($ip);
        $attempts = (int) $this->cache->load($key);

        if ($attempts >= self::RATE_LIMIT_ATTEMPTS) {
            return false;
        }

        $this->cache->save(
            (string) ($attempts + 1),
            $key,
            [],
            self::RATE_LIMIT_WINDOW_SECONDS
        );

        return true;
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
