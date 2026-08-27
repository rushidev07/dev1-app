<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Model;

/**
 * Normalizes the `ahy_featured_brands` attribute value into a display-ready list.
 *
 * The attribute is stored as a JSON string of rows: {name, link, image}. Depending on
 * how the category was loaded, the raw value reaches us as:
 *   - a JSON string (EAV collection load — backend afterLoad not invoked)
 *   - an array of rows where `image` is a filename string
 *   - an array of rows where `image` is the fileUploader array [{name,url,...}]
 *
 * This class flattens all three shapes to: [['label' => string, 'url' => string, 'image' => string], ...]
 * where `image` is the stored filename (not yet a full URL — call buildImageUrl() for that).
 */
class FeaturedBrandsParser
{
    /**
     * @param mixed $raw Raw attribute value (JSON string, array of rows, or empty)
     * @return array<int, array{label: string, url: string, image: string}>
     */
    public function parse($raw): array
    {
        $rows = $this->toRows($raw);
        $out  = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string)($row['name'] ?? ''));
            if ($label === '') {
                continue;
            }
            $out[] = [
                'label' => $label,
                'url'   => $this->normalizeLink(trim((string)($row['link'] ?? '')), $label),
                'image' => $this->extractImage($row['image'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Legacy fallback: parse the old comma-separated `ahy_featured_brand_names` field.
     * Supports "Display Label|url-slug" entries. Images are unavailable in this format.
     *
     * @return array<int, array{label: string, url: string, image: string}>
     */
    public function parseLegacy(string $namesText): array
    {
        $namesText = trim($namesText);
        if ($namesText === '') {
            return [];
        }

        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $namesText))) as $entry) {
            if (str_contains($entry, '|')) {
                [$label, $slug] = array_map('trim', explode('|', $entry, 2));
            } else {
                $label = $entry;
                $slug  = '';
            }
            if ($label === '') {
                continue;
            }
            $out[] = [
                'label' => $label,
                'url'   => $this->normalizeLink($slug, $label),
                'image' => '',
            ];
        }

        return $out;
    }

    /**
     * Build a full, escapable image URL from a stored image reference.
     *
     * @param string $image        Stored value (filename, /media path, or absolute URL)
     * @param string $mediaBaseUrl store media base URL (…/media/)
     * @param string $webBaseUrl   store web base URL (…/)
     */
    public function buildImageUrl(string $image, string $mediaBaseUrl, string $webBaseUrl): string
    {
        $image = trim($image);
        if ($image === '' || $image === 'no_selection') {
            return '';
        }
        if (preg_match('#^https?://#i', $image)) {
            return $image;
        }

        $relative = ltrim($image, '/');
        if (str_starts_with($relative, 'media/')) {
            return rtrim($webBaseUrl, '/') . '/' . $relative;
        }
        if (str_starts_with($relative, 'catalog/')) {
            return rtrim($mediaBaseUrl, '/') . '/' . $relative;
        }

        // Bare filename — uploaded via the category image uploader (pub/media/catalog/category)
        return rtrim($mediaBaseUrl, '/') . '/catalog/category/' . $relative;
    }

    /**
     * @param mixed $raw
     * @return array<int, mixed>
     */
    private function toRows($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * @param mixed $image
     */
    private function extractImage($image): string
    {
        if (is_array($image)) {
            $first = reset($image);
            if (is_array($first)) {
                return (string)($first['name'] ?? $first['file'] ?? '');
            }
            return (string)$first;
        }
        return (string)$image;
    }

    private function normalizeLink(string $link, string $label): string
    {
        if ($link === '') {
            $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), '-');
            return $slug === '' ? '#' : '/' . $slug;
        }
        if (preg_match('#^https?://#i', $link)) {
            return $link;
        }
        return '/' . ltrim($link, '/');
    }
}
