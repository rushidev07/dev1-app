<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\Product;

use Magento\Catalog\Model\Product;

/**
 * Single source of truth for the PDP "Key Features" tile grid (see
 * product/view/key-features.phtml), shared with details-description.phtml
 * so both templates agree on the same decision:
 *
 * - If the product has admin-entered rows in the "key_features" attribute
 *   (see Setup/Patch/Data/CreateKeyFeaturesAttribute.php and the
 *   "Key Features" dynamic-rows grid on the product edit page), those are
 *   used as-is - real titles and descriptions, admin-managed, immune to
 *   FlxPoint's description sync.
 * - Otherwise, falls back to whatever bullet content is already in the
 *   product's plain Description field. Two shapes have been observed
 *   across this catalog and both are handled:
 *     1. A real <ul>/<ol> list (the last one in the description, on the
 *        assumption of "intro paragraphs, then a trailing feature list").
 *     2. A single <p> built from multiple "&bull; text<br />" lines with
 *        no real list markup at all (seen on FlxPoint-synced products
 *        that were never given a real <ul>).
 *   Either way, each bullet becomes a title-only tile (no separate
 *   description text, since neither source shape has that split).
 * - Some descriptions (Page Builder's "HTML" content type) store their
 *   tags HTML-entity-encoded as literal text (e.g. "&lt;ul&gt;") rather
 *   than as real markup - decoded before parsing so those are found too.
 * - When falling back, the same bullet content must also be stripped out
 *   of the plain description text (see stripFallbackListFromDescription()),
 *   so it doesn't render twice - once as flat text, once as tiles.
 */
class KeyFeaturesResolver
{
    private const ATTRIBUTE_CODE = 'key_features';

    /**
     * @return array<int, array{title: string, description: string}>
     */
    public function getTiles(Product $product, string $descriptionHtml): array
    {
        $adminRows = $this->getAdminRows($product);
        if ($adminRows) {
            return $adminRows;
        }

        $bullets = $this->extractFallbackListItems($descriptionHtml);
        if (!$bullets) {
            return [];
        }

        return array_map(
            static fn (string $text): array => ['title' => $text, 'description' => ''],
            $bullets
        );
    }

    /**
     * Only meaningful to call when getTiles() ended up using the fallback
     * path (no admin rows) - removes the same bullet content from the
     * plain description text so it isn't shown twice.
     */
    public function stripFallbackListFromDescription(Product $product, string $descriptionHtml): string
    {
        if ($this->getAdminRows($product)) {
            // Admin rows exist, so getTiles() didn't use the fallback list -
            // leave the description exactly as authored.
            return $descriptionHtml;
        }

        $found = $this->findFallbackNode($descriptionHtml);
        if ($found === null) {
            return $descriptionHtml;
        }

        $found['node']->parentNode->removeChild($found['node']);

        return $this->innerHtml($found['doc'], $this->wrapperDiv($found['doc']));
    }

    /**
     * @return array<int, array{title: string, description: string}>
     */
    private function getAdminRows(Product $product): array
    {
        $rows = $product->getData(self::ATTRIBUTE_CODE);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return string[] Plain text of each bullet found, or [] if none.
     */
    private function extractFallbackListItems(string $descriptionHtml): array
    {
        $found = $this->findFallbackNode($descriptionHtml);
        if ($found === null) {
            return [];
        }

        if ($found['type'] === 'list') {
            $items = [];
            foreach ($found['node']->getElementsByTagName('li') as $li) {
                $text = trim($li->textContent);
                if ($text !== '') {
                    $items[] = $text;
                }
            }
            return $items;
        }

        // 'bullet_paragraph': split the <p>'s own content on <br> tags,
        // then strip each line's leading bullet character.
        $innerHtml = $this->innerHtml($found['doc'], $found['node']);
        $lines = preg_split('/<br\s*\/?>/i', $innerHtml) ?: [];

        $items = [];
        foreach ($lines as $line) {
            $text = trim(strip_tags($line));
            $text = preg_replace('/^[\x{2022}\-\*\s]+/u', '', $text) ?? $text;
            $text = trim($text);
            if ($text !== '') {
                $items[] = $text;
            }
        }

        return $items;
    }

    /**
     * @return array{doc: \DOMDocument, node: \DOMElement, type: 'list'|'bullet_paragraph'}|null
     */
    private function findFallbackNode(string $descriptionHtml): ?array
    {
        // Some descriptions (Page Builder's "HTML" content type) store
        // their tags HTML-entity-encoded as literal text rather than real
        // markup - decode first so DOMDocument actually sees real elements.
        $decoded = html_entity_decode($descriptionHtml, ENT_QUOTES | ENT_HTML5);
        if (trim($decoded) === '') {
            return null;
        }

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="utf-8" ?><div>' . $decoded . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();

        // Preferred: a real <ul>/<ol> list. Last one in document order -
        // the common shape across this catalog is intro paragraphs
        // followed by a trailing feature list.
        $lists = [];
        foreach (['ul', 'ol'] as $tag) {
            foreach ($doc->getElementsByTagName($tag) as $node) {
                $lists[] = $node;
            }
        }
        if ($lists) {
            return ['doc' => $doc, 'node' => $lists[count($lists) - 1], 'type' => 'list'];
        }

        // Fallback: a <p> built from multiple "&bull; text<br />" lines
        // with no real list markup - decoding turns "&bull;" into the
        // literal bullet character (U+2022), which is what's matched here.
        foreach ($doc->getElementsByTagName('p') as $p) {
            if (substr_count($p->textContent, "\u{2022}") >= 2) {
                return ['doc' => $doc, 'node' => $p, 'type' => 'bullet_paragraph'];
            }
        }

        return null;
    }

    private function wrapperDiv(\DOMDocument $doc): \DOMElement
    {
        return $doc->getElementsByTagName('div')->item(0);
    }

    private function innerHtml(\DOMDocument $doc, \DOMElement $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $doc->saveHTML($child);
        }

        return $html;
    }
}
