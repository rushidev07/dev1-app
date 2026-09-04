<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Product\View;

use Amasty\Faq\Model\ResourceModel\Question\Collection;
use Amasty\Faq\Model\ResourceModel\Question\CollectionFactory;
use Hyva\Theme\Model\ViewModelRegistry;
use Hyva\Theme\ViewModel\CurrentProduct;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

/**
 * PDP FAQ block. Shows the FAQ questions assigned to this product (or its
 * categories) in Amasty's FAQ & Product Questions module, i.e. the same
 * content editable from the Magento admin under Amasty > FAQ.
 */
class Faq extends Template
{
    /**
     * Keyword -> category map used to derive the FAQ filter pills.
     *
     * Amasty tags are the preferred source, but the questions seeded by
     * code/Ahy/UpdateProductsCSV/custom-script/add_product_faqs.php never got
     * tag rows, so untagged questions are bucketed by matching these keywords
     * against the question title. Declaration order is the pill display order;
     * the first category with a match wins, so keep the more specific buckets
     * (Everest, Policy) above the broad ones.
     */
    private const CATEGORY_KEYWORDS = [
        'Sizing' => [
            'size', 'sizes', 'sizing', 'fit', 'fits', 'age', 'age range', 'measurement', 'measurements',
            'torso', 'chart', 'dimension', 'dimensions', 'length', 'width', 'capacity', 'volume',
            'small', 'medium', 'large',
            // Deliberately NOT child/kids/youth here: "how much weight can a
            // child safely carry" is a Safety question, and any genuinely
            // size-related wording already trips 'size'/'fit'/'age'.
        ],
        'Materials' => [
            'fabric', 'fabrics', 'material', 'materials', 'clothing', 'cloth', 'textile',
            'cotton', 'polyester', 'nylon', 'fleece', 'wool', 'merino', 'leather', 'suede',
            'denim', 'canvas', 'mesh', 'down', 'insulation', 'insulated', 'lining', 'shell',
            'waterproof', 'water-resistant', 'weatherproof', 'breathable', 'ripstop',
            'warm', 'warmth', 'temperature', 'winter', 'summer', 'season', 'seasons',
        ],
        'Safety' => [
            'safe', 'safely', 'safety', 'hazard', 'warning', 'risk', 'secure', 'reflective',
            'weight limit', 'load', 'certified', 'certification', 'recall', 'toxic', 'bpa',
        ],
        'Features' => [
            'feature', 'features', 'compartment', 'compartments', 'pocket', 'pockets',
            'hood', 'hooded', 'zipper', 'zip', 'strap', 'straps', 'buckle', 'hydration',
            'bladder', 'sleeve', 'cuff', 'collar', 'customize', 'customise', 'compatible',
            'compatibility', 'adjustable', 'removable', 'label', 'labeled', 'labelled',
        ],
        'Care' => [
            'wash', 'washing', 'washable', 'care', 'clean', 'cleaning', 'dry', 'dryer',
            'tumble', 'iron', 'bleach', 'detergent', 'maintain', 'maintenance', 'store', 'storage',
        ],
        'Policy' => [
            'return', 'returns', 'policy', 'warranty', 'guarantee', 'refund', 'exchange',
            'shipping', 'ship', 'delivery', 'cancel', 'cancellation',
        ],
        'Everest' => [
            'everest', 'give back', 'giveback', 'fund', 'donation', 'donate', 'charity',
            'nonprofit', 'foundation', 'mission',
        ],
    ];

    /**
     * Bucket for questions no keyword matched. Always sorted last in the pills.
     */
    private const CATEGORY_OTHER = 'Other';

    /** @var array<int, string[]> Memoised per question_id - the template asks twice. */
    private array $categoryCache = [];

    private CollectionFactory $collectionFactory;
    private ViewModelRegistry $viewModelRegistry;
    private CustomerSession $customerSession;
    private StoreManagerInterface $storeManager;

    public function __construct(
        Context $context,
        CollectionFactory $collectionFactory,
        ViewModelRegistry $viewModelRegistry,
        CustomerSession $customerSession,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->collectionFactory = $collectionFactory;
        $this->viewModelRegistry = $viewModelRegistry;
        $this->customerSession = $customerSession;
        $this->storeManager = $storeManager;
    }

    public function getProduct(): \Magento\Catalog\Model\Product
    {
        /** @var CurrentProduct $currentProduct */
        $currentProduct = $this->viewModelRegistry->require(CurrentProduct::class);
        return $currentProduct->get();
    }

    /**
     * @return \Amasty\Faq\Model\Question[]
     */
    public function getFaqs(): array
    {
        $productId = (int) $this->getProduct()->getId();

        /** @var Collection $collection */
        $collection = $this->collectionFactory->create();
        $collection->addProductFilter($productId);
        $collection->addFrontendFilters(
            $this->customerSession->isLoggedIn(),
            (int) $this->storeManager->getStore()->getId(),
            null,
            (int) $this->customerSession->getCustomerGroupId()
        );
        $collection->getSelect()->group('main_table.question_id');

        return $collection->getItems();
    }

    /**
     * Categories across all questions, used as the FAQ filter pills. Ordered
     * to match CATEGORY_KEYWORDS so the pills read the same on every product,
     * with any Amasty-tag names appended and "Other" pinned last.
     *
     * @param \Amasty\Faq\Model\Question[] $faqs
     * @return string[]
     */
    public function getCategories(array $faqs): array
    {
        $present = [];
        foreach ($faqs as $faq) {
            foreach ($this->getQuestionCategories($faq) as $category) {
                $present[$category] = true;
            }
        }

        $ordered = [];
        foreach (array_keys(self::CATEGORY_KEYWORDS) as $category) {
            if (isset($present[$category])) {
                $ordered[] = $category;
                unset($present[$category]);
            }
        }
        // Anything left is an Amasty tag name we don't have a keyword bucket
        // for; keep it, but push Other to the very end.
        $hasOther = isset($present[self::CATEGORY_OTHER]);
        unset($present[self::CATEGORY_OTHER]);
        $ordered = array_merge($ordered, array_keys($present));
        if ($hasOther) {
            $ordered[] = self::CATEGORY_OTHER;
        }

        return $ordered;
    }

    /**
     * Categories for a single question. Real Amasty tags win when the question
     * has them; otherwise fall back to the keyword match so the filter pills
     * still work on untagged questions.
     *
     * @param \Amasty\Faq\Model\Question $faq
     * @return string[]
     */
    public function getQuestionCategories($faq): array
    {
        $questionId = (int) $faq->getQuestionId();
        if (isset($this->categoryCache[$questionId])) {
            return $this->categoryCache[$questionId];
        }

        $categories = [];
        foreach ($faq->getTags() as $tag) {
            $title = trim((string) $tag->getTitle());
            if ($title !== '') {
                $categories[] = $title;
            }
        }

        if (!$categories) {
            $categories = [$this->matchCategory((string) $faq->getTitle())];
        }

        return $this->categoryCache[$questionId] = array_values(array_unique($categories));
    }

    /**
     * Comma-separated categories for a single question, used to drive the
     * Alpine.js filter-pill visibility check on the frontend.
     *
     * @param \Amasty\Faq\Model\Question $faq
     */
    public function getTagTitlesCsv($faq): string
    {
        return implode(',', $this->getQuestionCategories($faq));
    }

    /**
     * First category whose keyword list matches the text, else Other. Matching
     * is whole-word and case-insensitive so "benefit" doesn't score as "fit"
     * and "download" doesn't score as "down".
     */
    private function matchCategory(string $text): string
    {
        foreach (self::CATEGORY_KEYWORDS as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/iu', $text)) {
                    return $category;
                }
            }
        }

        return self::CATEGORY_OTHER;
    }
}
