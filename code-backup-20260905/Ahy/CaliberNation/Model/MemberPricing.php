<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model;

use Ahy\CaliberNation\Api\Data\MemberPriceInterface;
use Ahy\CaliberNation\Api\Data\MemberPriceInterfaceFactory;
use Ahy\CaliberNation\Api\MemberPricingInterface;
use Ahy\CaliberNation\Model\Service\MemberAccess;
use Ahy\CaliberNation\Model\Service\Pricing\MemberPriceResolver;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * Read-only member-pricing service (P7). Resolves the effective member price for a
 * product and reports whether the authenticated customer is an active member.
 */
class MemberPricing implements MemberPricingInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly MemberPriceResolver $resolver,
        private readonly MemberAccess $memberAccess,
        private readonly UserContextInterface $userContext,
        private readonly MemberPriceInterfaceFactory $resultFactory
    ) {}

    public function getMemberPrice(int $productId): MemberPriceInterface
    {
        $product = $this->productRepository->getById($productId);
        $result  = $this->resolver->resolveForProduct($product);

        $customerId = $this->userContext->getUserType() === UserContextInterface::USER_TYPE_CUSTOMER
            ? (int) $this->userContext->getUserId()
            : 0;

        /** @var MemberPriceInterface $dto */
        $dto = $this->resultFactory->create();
        $dto->setProductId($productId);
        $dto->setRegularPrice($result->regularPrice);
        $dto->setMemberPrice($result->memberPrice);
        $dto->setDiscount($result->discount);
        $dto->setHasDiscount($result->hasDiscount());
        $dto->setIsMember($this->memberAccess->isActiveMember($customerId));

        return $dto;
    }
}
