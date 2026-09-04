<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Product\View;

use Hyva\Theme\Model\ViewModelRegistry;
use Hyva\Theme\ViewModel\CurrentProduct;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * PDP "Video" tab (see product/view/product-video.phtml). Reads whatever
 * video is attached to the product's own media gallery via Magento's
 * standard Add Video admin dialog (media_type "external-video") - the same
 * data Ahy_VideoUpload's admin-side fixes and Ahy\PDPRevamp\Controller\
 * Adminhtml\Video\Upload target - rather than a separate custom field.
 *
 * Only the FIRST attached video is shown; a product with more than one is
 * an edge case this tab doesn't attempt to handle (no carousel/tab-within-
 * tab), matching the reference design's single embedded player.
 */
class ProductVideo extends Template
{
    private ViewModelRegistry $viewModelRegistry;

    public function __construct(
        Context $context,
        ViewModelRegistry $viewModelRegistry,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->viewModelRegistry = $viewModelRegistry;
    }

    public function getProduct(): Product
    {
        /** @var CurrentProduct $currentProduct */
        $currentProduct = $this->viewModelRegistry->require(CurrentProduct::class);
        return $currentProduct->get();
    }

    /**
     * @return array{embedUrl: string, title: string, description: string}|null
     */
    public function getVideo(): ?array
    {
        foreach ($this->getProduct()->getMediaGalleryEntries() ?? [] as $entry) {
            if ($entry->getMediaType() !== 'external-video') {
                continue;
            }

            $videoContent = $entry->getExtensionAttributes()
                ? $entry->getExtensionAttributes()->getVideoContent()
                : null;
            if (!$videoContent || !$videoContent->getVideoUrl()) {
                continue;
            }

            $embedUrl = $this->buildEmbedUrl($videoContent->getVideoUrl());
            if (!$embedUrl) {
                // A url we can't recognize (or a bare local .mp4, which needs
                // a <video> tag rather than an iframe) - nothing sensible to
                // embed here, so this entry is skipped rather than shown broken.
                continue;
            }

            return [
                'embedUrl' => $embedUrl,
                'title' => (string) $videoContent->getVideoTitle(),
                'description' => $this->truncateDescription((string) $videoContent->getVideoDescription()),
            ];
        }

        return null;
    }

    /**
     * YouTube/Vimeo embed iframe src, or null if the URL isn't recognized.
     * Same id-extraction patterns as the main gallery's own video detection
     * (product/view/gallery.phtml's getVideoData()), ported to PHP so both
     * places agree on what counts as a playable video URL.
     */
    private function buildEmbedUrl(string $videoUrl): ?string
    {
        if (preg_match('/youtube\.com|youtu\.be|youtube-nocookie\.com/', $videoUrl)) {
            if (preg_match(
                '/^.*(?:(?:youtu\.be\/|v\/|vi\/|u\/\w\/|embed\/)|(?:(?:watch)?\?v(?:i)?=|&v(?:i)?=))([^#&?]*).*/',
                $videoUrl,
                $matches
            ) && $matches[1] !== '') {
                return 'https://www.youtube.com/embed/' . $matches[1];
            }
            return null;
        }

        if (preg_match('/vimeo\.com/', $videoUrl)) {
            if (preg_match(
                '#https?://(?:www\.|player\.)?vimeo\.com/(?:channels/(?:\w+/)?|groups/([^/]*)/videos/|album/(\d+)/video/|video/|)(\d+)(?:$|/|\?)#',
                $videoUrl,
                $matches
            ) && !empty($matches[3])) {
                return 'https://player.vimeo.com/video/' . $matches[3];
            }
            return null;
        }

        return null;
    }

    /**
     * Long-form creator/marketing boilerplate (common on stock Vimeo/YouTube
     * videos not authored for this product) is cut down to a short caption,
     * matching the reference design, rather than dumping the whole source
     * description under the player.
     *
     * Picks the first paragraph that reads like real sentence content -
     * skipping short lines and bare URLs - rather than always taking the
     * literal first line: real stock descriptions commonly open with
     * "Follow on: <social link>" boilerplate before the actual summary,
     * which would otherwise become the caption instead of anything useful.
     */
    private function truncateDescription(string $description): string
    {
        // Real-world video descriptions (this one included) commonly use
        // \r\n line endings, which \n{2,} alone would never match - the LF
        // characters aren't adjacent to each other with a CR sitting
        // between them, so the whole description would be treated as one
        // giant paragraph instead of being split at all.
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($description));
        $paragraphs = preg_split('/\n{2,}/', $normalized) ?: [];

        $chosen = '';
        foreach ($paragraphs as $paragraph) {
            $candidate = trim(preg_replace('/\s+/', ' ', $paragraph) ?? '');
            // Not just "is this bare a URL" - a "Follow on:\nhttps://..."
            // style line joined by a single newline (so still one
            // "paragraph" after the \n{2,} split above) contains a link
            // too, and reads exactly as much like boilerplate.
            $containsUrl = (bool) preg_match('#https?://#', $candidate);
            if ($candidate !== '' && !$containsUrl && mb_strlen($candidate) >= 30) {
                $chosen = $candidate;
                break;
            }
        }

        if ($chosen === '') {
            // Nothing met that bar (e.g. a description that's only short
            // lines/links) - fall back to whatever the first paragraph was
            // rather than showing no caption at all.
            $chosen = trim(preg_replace('/\s+/', ' ', $paragraphs[0] ?? '') ?? '');
        }

        if (mb_strlen($chosen) > 220) {
            $chosen = rtrim(mb_substr($chosen, 0, 220)) . '…';
        }

        return $chosen;
    }
}
