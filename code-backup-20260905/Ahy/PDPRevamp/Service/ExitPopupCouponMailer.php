<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Service;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Emails an exit-popup claimant their discount code.
 *
 * etc/email_templates.xml and view/frontend/email/exit_popup_discount.html were
 * already in the module, but nothing ever sent them - the popup showed the code
 * on screen and that was the only copy the customer got. Anyone who closed the
 * tab lost it, with no way to recover it short of re-submitting the same address.
 *
 * Sending is best-effort by design. The claim row and the coupon are already
 * committed by the time this runs, so a mail failure must not fail the claim:
 * the customer still has a valid code on screen. Hence every path here logs and
 * returns rather than throwing.
 *
 * Sent in the frontend area explicitly via emulateAreaCode. Without it, a send
 * triggered from a non-frontend context resolves the template against the admin
 * area and loses the store's header/footer templates.
 */
class ExitPopupCouponMailer
{
    /**
     * Fallback only. The template actually used comes from
     * pdprevamp_exit_popup/general/email_template so an admin can swap in their
     * own without a code change - this constant is what that setting defaults to
     * (etc/config.xml) and what is used if the setting is ever blanked.
     */
    private const DEFAULT_TEMPLATE_ID = 'pdprevamp_exit_popup_discount_email';

    private const XML_PATH_EMAIL_ENABLED = 'pdprevamp_exit_popup/general/send_email';
    private const XML_PATH_SENDER = 'pdprevamp_exit_popup/general/email_sender';
    private const XML_PATH_TEMPLATE = 'pdprevamp_exit_popup/general/email_template';

    private TransportBuilder $transportBuilder;
    private StoreManagerInterface $storeManager;
    private ScopeConfigInterface $scopeConfig;
    private \Magento\Framework\App\State $appState;
    private TimezoneInterface $timezone;
    private ProductRepositoryInterface $productRepository;
    private LoggerInterface $logger;

    public function __construct(
        TransportBuilder $transportBuilder,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\State $appState,
        TimezoneInterface $timezone,
        ProductRepositoryInterface $productRepository,
        LoggerInterface $logger
    ) {
        $this->transportBuilder = $transportBuilder;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->appState = $appState;
        $this->timezone = $timezone;
        $this->productRepository = $productRepository;
        $this->logger = $logger;
    }

    /**
     * True when the mail was handed to the transport, false on any failure or
     * when sending is switched off.
     *
     * The caller should not treat false as a failed claim - see the class note.
     */
    public function send(
        string $email,
        string $couponCode,
        int $discountPercent,
        ?string $expiresAt = null,
        ?int $productId = null
    ): bool {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            $store = $this->storeManager->getStore();
            $storeId = (int) $store->getId();

            $expiresLabel = $this->formatExpiry($expiresAt);
            $productName = $this->getProductName($productId);
            $templateId = $this->getTemplateId();

            // emulateAreaCode rather than setting the area directly: the claim
            // arrives on a frontend controller today, but the same service is the
            // natural place to re-send from an admin action or a cron, and those
            // would otherwise render the template against the wrong area.
            $transport = $this->appState->emulateAreaCode(
                Area::AREA_FRONTEND,
                function () use ($email, $couponCode, $discountPercent, $expiresLabel, $productName, $storeId, $templateId) {
                    $this->transportBuilder
                        ->setTemplateIdentifier($templateId)
                        ->setTemplateOptions([
                            'area' => Area::AREA_FRONTEND,
                            'store' => $storeId,
                        ])
                        ->setTemplateVars([
                            'coupon_code' => $couponCode,
                            'discount_percent' => $discountPercent,
                            // Pre-formatted for display - see formatExpiry().
                            'expires_at' => $expiresLabel,
                            'product_name' => $productName,
                        ])
                        ->setFromByScope($this->getSender(), $storeId)
                        ->addTo($email);

                    return $this->transportBuilder->getTransport();
                }
            );

            $transport->sendMessage();

            return true;
        } catch (\Throwable $exception) {
            // The customer already has a working code on screen, so this is a
            // degraded outcome rather than a failed claim.
            $this->logger->error(
                '[ExitPopupCouponMailer] could not email code ' . $couponCode
                . ': ' . $exception->getMessage()
            );

            return false;
        }
    }

    /**
     * The expiry rendered in the store's own timezone, to the hour.
     *
     * "2026-08-29 05:09:12" becomes "Fri, Aug 28 at 1 AM ET".
     *
     * Two deliberate choices:
     *
     * 1. Converted out of UTC. ExitPopupCouponService::applyExpiry() stores UTC,
     *    and the raw value was being shown to customers as-is - so a deadline of
     *    05:09 UTC read as 5am to someone whose actual cut-off was 1:09am Eastern,
     *    nearly four hours earlier than the email implied. The zone comes from the
     *    store's own general/locale/timezone (America/New_York here) rather than a
     *    hardcoded one, so it stays correct if that setting changes.
     *
     * 2. Rounded DOWN, never up. Rounding to the nearest hour would let the email
     *    advertise up to 59 minutes the customer does not actually have. Flooring
     *    can understate the deadline by up to an hour, which costs them nothing.
     *
     * Returns null when there is no expiry, which the template's {{depend}} uses
     * to drop the line entirely.
     */
    private function formatExpiry(?string $expiresAt): ?string
    {
        if (!$expiresAt) {
            return null;
        }

        try {
            $utc = new \DateTime((string) $expiresAt, new \DateTimeZone('UTC'));
            $utc->setTimezone($this->storeTimezone());

            // Floor to the hour - see note 2 above.
            $utc->setTime((int) $utc->format('G'), 0, 0);

            // "Fri, Aug 28 at 1 AM EDT" - %-style padding avoided so it reads
            // "1 AM" rather than "01 AM".
            return $utc->format('D, M j') . ' at ' . $utc->format('g A T');
        } catch (\Throwable $exception) {
            $this->logger->error(
                '[ExitPopupCouponMailer] could not format expiry "' . $expiresAt
                . '": ' . $exception->getMessage()
            );

            // Better to show the raw value than to silently drop the deadline.
            return $expiresAt;
        }
    }

    /**
     * The store's configured timezone, falling back to UTC.
     *
     * TimezoneInterface::getConfigTimezone() reads general/locale/timezone, which
     * is the same value the admin grid renders dates in - so the email and the
     * admin agree.
     */
    private function storeTimezone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone($this->timezone->getConfigTimezone());
        } catch (\Throwable $exception) {
            return new \DateTimeZone('UTC');
        }
    }

    /**
     * The name of the product the code was claimed on, or null.
     *
     * Named in the email because the discount is not a general one - the sales
     * rule's actions filter restricts it to products with pdp_exit_popup_enabled
     * set, so a customer applying it to anything else sees no discount and no
     * explanation. Saying which product it works on up front is the honest
     * version of "your exclusive 20% off code".
     *
     * A missing or deleted product yields null rather than an error; the template
     * then falls back to wording that names no product.
     */
    private function getProductName(?int $productId): ?string
    {
        if (!$productId || $productId < 1) {
            return null;
        }

        try {
            $product = $this->productRepository->getById($productId);
            $name = trim((string) $product->getName());

            return $name !== '' ? $name : null;
        } catch (\Throwable $exception) {
            // Deleted product, or one not visible in this scope - not worth
            // failing the email over.
            return null;
        }
    }

    /**
     * Whether the code is delivered by email.
     *
     * Public because the controller has to know before it builds its response:
     * when this is on the code is withheld from the JSON entirely, so the browser
     * never receives it. Suppressing it in the template instead would leave the
     * code sitting in the response body for anyone reading devtools or the
     * network tab, which defeats the point of email-only delivery.
     */
    /**
     * The configured template, or the module's own as a fallback.
     *
     * A store-scoped setting rather than a constant, so the wording can be edited
     * under Marketing > Email Templates and selected here without a deploy - the
     * same pattern core uses for its own transactional emails. Numeric values are
     * valid: that is what Magento stores when an admin-created template is
     * selected, and TransportBuilder accepts either form.
     */
    private function getTemplateId(): string
    {
        $configured = trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_TEMPLATE,
            ScopeInterface::SCOPE_STORE
        ));

        return $configured !== '' ? $configured : self::DEFAULT_TEMPLATE_ID;
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_EMAIL_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Falls back to general rather than assuming a custom identity exists - an
     * unconfigured sender otherwise throws inside setFromByScope.
     */
    private function getSender(): string
    {
        $sender = trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_SENDER,
            ScopeInterface::SCOPE_STORE
        ));

        return $sender !== '' ? $sender : 'general';
    }
}
