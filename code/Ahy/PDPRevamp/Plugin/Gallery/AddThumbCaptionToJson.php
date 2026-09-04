<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin\Gallery;

use Magento\Catalog\Block\Product\View\Gallery;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Adds "thumb_caption" to each entry of the PDP gallery JSON consumed by
 * product/view/gallery.phtml, so the thumbnail overlay can render the
 * admin-entered caption.
 *
 * Deliberately a new key rather than a rewrite of the existing "caption":
 * core sets caption to the image label falling back to the product name
 * (Magento\Catalog\Block\Product\View\Gallery::getGalleryImagesJson), and the
 * template still uses it for the img alt/title attributes. Overloading it would
 * couple the visible overlay to the accessibility text and change alt output on
 * every product - so caption keeps its meaning and this key carries ours.
 *
 * Matching is by position: core builds its JSON by iterating getGalleryImages()
 * in order, so the Nth decoded entry corresponds to the Nth collection item.
 * The value itself reaches the collection through AddCaptionColumn.
 */
class AddThumbCaptionToJson
{
    private const JSON_KEY = 'thumb_caption';

    private Json $serializer;

    public function __construct(Json $serializer)
    {
        $this->serializer = $serializer;
    }

    public function afterGetGalleryImagesJson(Gallery $subject, $result)
    {
        if (!is_string($result) || $result === '') {
            return $result;
        }

        try {
            $items = $this->serializer->unserialize($result);
        } catch (\InvalidArgumentException $e) {
            return $result;
        }

        if (!is_array($items) || $items === []) {
            return $result;
        }

        $captions = [];
        foreach ($subject->getGalleryImages() as $image) {
            $caption = $image->getData(AddCaptionColumn::COLUMN);
            $captions[] = is_string($caption) ? trim($caption) : '';
        }

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            // The placeholder entry core appends when a product has no images
            // has no counterpart in the collection - default it to empty.
            $items[$index][self::JSON_KEY] = $captions[$index] ?? '';
        }

        return $this->serializer->serialize($items);
    }
}
