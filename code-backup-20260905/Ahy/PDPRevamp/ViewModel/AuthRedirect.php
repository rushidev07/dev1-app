<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\ViewModel;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Lets a phtml register "return here after login" before rendering an
 * inline sign-in link (e.g. the Yotpo review form's "Please sign in to
 * write a review" prompt). A bare customer/account/login link with nothing
 * else behind it sends the shopper to the generic account dashboard instead
 * of back to what they were doing, since Magento's login controller only
 * knows to return them to a specific page when this is set.
 *
 * A view model rather than a block method because neither yotpo_reviews.phtml
 * nor yotpo_js.phtml has a dedicated block class to hang this off of (both
 * render via the default Magento\Framework\View\Element\Template block) -
 * adding one would mean a class="..." layout XML change, which is risky
 * here specifically: the theme's catalog_product_view.xml redeclares both
 * of these same block names without a class attribute and loads after the
 * module's layout, so it would likely win and silently strip any class
 * added only in the module (see EXTERNAL_DEPENDENCIES.md's account of this
 * exact template-merge-order problem for these two blocks).
 *
 * Mirrors the working pattern already used elsewhere in this codebase -
 * Webkul\Marketplace\Block\Feedbackcollection::setCustomerSessionAfterAuthUrl().
 */
class AuthRedirect implements ArgumentInterface
{
    private CustomerSession $customerSession;

    public function __construct(CustomerSession $customerSession)
    {
        $this->customerSession = $customerSession;
    }

    public function isLoggedIn(): bool
    {
        return $this->customerSession->isLoggedIn();
    }

    /**
     * Call once, server-side, before rendering a "sign in" link that should
     * return the shopper to $url rather than the account dashboard.
     * Magento's own login flow reads this automatically - nothing else
     * needs to happen at click time.
     */
    public function setAfterAuthUrl(string $url): void
    {
        $this->customerSession->setAfterAuthUrl($url);
    }
}
