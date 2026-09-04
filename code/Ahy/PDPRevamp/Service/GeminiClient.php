<?php

namespace Ahy\PDPRevamp\Service;

use Ahy\PDPRevamp\Helper\Data as PDPRevampHelper;
use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Generates the short punchy phrase stored in the pdp_descriptors attribute
 * (e.g. "Ultralight, Waterproof, Trail-Ready") via the Gemini API, from a
 * product's name, short description and category. See
 * Ahy\PDPRevamp\Setup\Patch\Data\CreatePdpDescriptorsAttribute for the
 * attribute this feeds and Console\Command\GenerateAiDescriptors for the
 * bulk command that calls this.
 */
class GeminiClient
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /** Reject anything implausibly long - a sign the model ignored the format instruction. */
    private const MAX_RESULT_LENGTH = 120;

    /** Full descriptions can run long; cap what's sent so the prompt (and token cost) stays small. */
    private const MAX_DESCRIPTION_LENGTH = 2000;

    private ClientFactory $clientFactory;
    private Json $serializer;
    private LoggerInterface $logger;
    private PDPRevampHelper $helper;

    public function __construct(
        ClientFactory $clientFactory,
        Json $serializer,
        LoggerInterface $logger,
        PDPRevampHelper $helper
    ) {
        $this->clientFactory = $clientFactory;
        $this->serializer = $serializer;
        $this->logger = $logger;
        $this->helper = $helper;
    }

    public function isConfigured(): bool
    {
        return $this->helper->getGeminiApiKey() !== null;
    }

    /**
     * Returns a comma-separated, 3-phrase descriptor line, or null when the
     * key isn't configured, the request fails, or the model's answer doesn't
     * look usable.
     */
    public function generateDescriptors(string $productName, string $description, string $categoryPath): ?string
    {
        $apiKey = $this->helper->getGeminiApiKey();
        if ($apiKey === null) {
            $this->logger->error('[GeminiClient] generateDescriptors skipped: no Gemini API key configured');
            return null;
        }

        $prompt = $this->buildPrompt($productName, $this->stripHtml($description), $categoryPath);
        $model = $this->helper->getGeminiModel();

        try {
            $client = $this->clientFactory->create();
            $response = $client->request(
                'POST',
                self::API_BASE . rawurlencode($model) . ':generateContent',
                [
                    'query' => ['key' => $apiKey],
                    'json' => [
                        'contents' => [
                            ['parts' => [['text' => $prompt]]],
                        ],
                        'generationConfig' => [
                            'temperature' => 0.4,
                            'maxOutputTokens' => 40,
                        ],
                    ],
                    'headers' => ['Accept' => 'application/json'],
                ]
            );

            $payload = $this->serializer->unserialize((string) $response->getBody());
            $text = $payload['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (!is_string($text) || trim($text) === '') {
                $this->logger->error('[GeminiClient] generateDescriptors got no usable text for "' . $productName . '"');
                return null;
            }

            return $this->sanitize($text);
        } catch (GuzzleException $exception) {
            $this->logger->error('[GeminiClient] request failed for "' . $productName . '": ' . $exception->getMessage());
            return null;
        } catch (\InvalidArgumentException $exception) {
            $this->logger->error('[GeminiClient] could not decode response for "' . $productName . '": ' . $exception->getMessage());
            return null;
        }
    }

    /**
     * Returns up to 3 short highlight tags extracted from a customer review
     * (e.g. "Great for Kids", "Waterproof", "Lightweight"), or an empty array
     * when the key isn't configured, the request fails, or the review text
     * doesn't support any confident tag. Consumed by
     * Ahy\PDPRevamp\Service\YotpoClient, which caches the result per review
     * so this is only ever called once per review, not on every page view.
     */
    public function generateReviewTags(string $reviewTitle, string $reviewContent): array
    {
        $apiKey = $this->helper->getGeminiApiKey();
        if ($apiKey === null) {
            return [];
        }

        $reviewText = trim(trim($reviewTitle) . '. ' . trim($this->stripHtml($reviewContent)));
        if ($reviewText === '' || $reviewText === '.') {
            return [];
        }

        $prompt = $this->buildReviewTagsPrompt($reviewText);
        $model = $this->helper->getGeminiModel();

        try {
            $client = $this->clientFactory->create();
            $response = $client->request(
                'POST',
                self::API_BASE . rawurlencode($model) . ':generateContent',
                [
                    'query' => ['key' => $apiKey],
                    'json' => [
                        'contents' => [
                            ['parts' => [['text' => $prompt]]],
                        ],
                        'generationConfig' => [
                            'temperature' => 0.4,
                            'maxOutputTokens' => 30,
                        ],
                    ],
                    'headers' => ['Accept' => 'application/json'],
                ]
            );

            $payload = $this->serializer->unserialize((string) $response->getBody());
            $text = $payload['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (!is_string($text) || trim($text) === '') {
                return [];
            }

            return $this->sanitizeTags($text);
        } catch (GuzzleException $exception) {
            $this->logger->error('[GeminiClient] generateReviewTags request failed: ' . $exception->getMessage());
            return [];
        } catch (\InvalidArgumentException $exception) {
            $this->logger->error('[GeminiClient] generateReviewTags could not decode response: ' . $exception->getMessage());
            return [];
        }
    }

    /**
     * Resolves a creative/marketing color-option label (e.g. a fishing-lure
     * paint name like "Candy Apple Craw") to a single representative hex
     * code, for labels that don't match any standard CSS color name.
     * Returns null when the key isn't configured, the request fails, or the
     * model's answer isn't a valid #RRGGBB code. Consumed by
     * Ahy\PDPRevamp\Service\AiColorHexResolver, which saves the result into
     * Magento's own native visual swatch (eav_attribute_option_swatch) so a
     * given option is only ever sent to Gemini once, never from a live
     * storefront request.
     */
    public function resolveColorHex(string $colorLabel): ?string
    {
        $apiKey = $this->helper->getGeminiApiKey();
        if ($apiKey === null) {
            $this->logger->error('[GeminiClient] resolveColorHex skipped: no Gemini API key configured');
            return null;
        }

        $label = trim($colorLabel);
        if ($label === '') {
            return null;
        }

        $prompt = $this->buildColorHexPrompt($label);
        $model = $this->helper->getGeminiModel();

        try {
            $client = $this->clientFactory->create();
            $response = $client->request(
                'POST',
                self::API_BASE . rawurlencode($model) . ':generateContent',
                [
                    'query' => ['key' => $apiKey],
                    'json' => [
                        'contents' => [
                            ['parts' => [['text' => $prompt]]],
                        ],
                        'generationConfig' => [
                            'temperature' => 0.2,
                            'maxOutputTokens' => 10,
                        ],
                    ],
                    'headers' => ['Accept' => 'application/json'],
                ]
            );

            $payload = $this->serializer->unserialize((string) $response->getBody());
            $text = $payload['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (!is_string($text) || trim($text) === '') {
                $this->logger->error('[GeminiClient] resolveColorHex got no usable text for "' . $label . '"');
                return null;
            }

            return $this->sanitizeHex($text);
        } catch (GuzzleException $exception) {
            $this->logger->error('[GeminiClient] resolveColorHex request failed for "' . $label . '": ' . $exception->getMessage());
            return null;
        } catch (\InvalidArgumentException $exception) {
            $this->logger->error('[GeminiClient] resolveColorHex could not decode response for "' . $label . '": ' . $exception->getMessage());
            return null;
        }
    }

    /**
     * Resolves many color labels in a single Gemini request instead of one
     * request per label. For a full-catalog backfill, network round-trips
     * (not tokens) dominate wall-clock time, so batching cuts it roughly by
     * the batch size compared to calling resolveColorHex() once per label.
     * Returns only the labels the model gave a valid #RRGGBB for - a caller
     * should treat any label missing from the result as unresolved.
     *
     * @param string[] $labels
     * @return array<string, string> label => hex
     */
    public function resolveColorHexBatch(array $labels): array
    {
        $apiKey = $this->helper->getGeminiApiKey();
        if ($apiKey === null || empty($labels)) {
            return [];
        }

        $prompt = $this->buildColorHexBatchPrompt($labels);
        $model = $this->helper->getGeminiModel();

        try {
            $client = $this->clientFactory->create();
            $response = $client->request(
                'POST',
                self::API_BASE . rawurlencode($model) . ':generateContent',
                [
                    'query' => ['key' => $apiKey],
                    'json' => [
                        'contents' => [
                            ['parts' => [['text' => $prompt]]],
                        ],
                        'generationConfig' => [
                            'temperature' => 0.2,
                            'maxOutputTokens' => 40 * count($labels),
                            'responseMimeType' => 'application/json',
                        ],
                    ],
                    'headers' => ['Accept' => 'application/json'],
                ]
            );

            $payload = $this->serializer->unserialize((string) $response->getBody());
            $text = $payload['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (!is_string($text) || trim($text) === '') {
                $this->logger->error('[GeminiClient] resolveColorHexBatch got no usable text for a batch of ' . count($labels));
                return [];
            }

            return $this->parseHexBatchResponse($text, $labels);
        } catch (GuzzleException $exception) {
            $this->logger->error('[GeminiClient] resolveColorHexBatch request failed: ' . $exception->getMessage());
            return [];
        } catch (\InvalidArgumentException $exception) {
            $this->logger->error('[GeminiClient] resolveColorHexBatch could not decode response: ' . $exception->getMessage());
            return [];
        }
    }

    /**
     * @param string[] $labels
     */
    private function buildColorHexBatchPrompt(array $labels): string
    {
        $numbered = [];
        foreach ($labels as $i => $label) {
            $numbered[] = ($i + 1) . '. "' . $label . '"';
        }
        $list = implode("\n", $numbered);

        return <<<PROMPT
You are matching creative/marketing product color names to a single
representative hex color code each, for color swatches shown on an
e-commerce site. These names are often used for fishing lures, apparel, or
similar retail products and may describe a multi-color pattern (e.g.
"Chartreuse Black Back") or an abstract/thematic name (e.g. "Ghost",
"Sleepover").

Color names:
$list

For EVERY name above, pick a single 6-digit hex color code in the exact
format #RRGGBB that best visually represents it. If a name describes a
multi-color pattern, pick its single most dominant or most distinctive
color. Make your best reasonable guess even for abstract or thematic names
- never refuse or omit one.

Respond with ONLY a JSON object mapping each exact color name (as given
above, unchanged) to its hex code, e.g.:
{"Ghost": "#E5E4E2", "Chartreuse Black Back": "#DFFF00"}

No markdown, no explanation, no code fences - just the raw JSON object,
with exactly one entry per name above.
PROMPT;
    }

    /**
     * @param string[] $labels
     * @return array<string, string>
     */
    private function parseHexBatchResponse(string $text, array $labels): array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text) ?? $text;

        try {
            $decoded = $this->serializer->unserialize($text);
        } catch (\InvalidArgumentException $exception) {
            $this->logger->error('[GeminiClient] resolveColorHexBatch got non-JSON response: ' . substr($text, 0, 200));
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $labelSet = array_flip($labels);
        $result = [];
        foreach ($decoded as $label => $hex) {
            if (!isset($labelSet[$label]) || !is_string($hex)) {
                continue;
            }
            $hex = trim($hex, " \t\n\r\0\x0B\"'.");
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) === 1) {
                $result[$label] = strtoupper($hex);
            }
        }

        return $result;
    }

    private function buildColorHexPrompt(string $colorLabel): string
    {
        return <<<PROMPT
You are matching a creative/marketing product color name to a single
representative hex color code, for a color swatch shown on an e-commerce
site. These names are often used for fishing lures, apparel, or similar
retail products and may describe a multi-color pattern (e.g. "Chartreuse
Black Back") or an abstract/thematic name (e.g. "Ghost", "Sleepover").

Color name: "$colorLabel"

Respond with ONLY a single 6-digit hex color code in the exact format
#RRGGBB that best visually represents this color name. If the name
describes a multi-color pattern, pick its single most dominant or most
distinctive color. Make your best reasonable guess even for abstract or
thematic names - never refuse.

Respond with only the hex code. No explanation, no markdown, no quotes.
PROMPT;
    }

    private function sanitizeHex(string $text): ?string
    {
        $text = trim($text, " \t\n\r\0\x0B\"'.");

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $text) === 1 ? strtoupper($text) : null;
    }

    private function buildReviewTagsPrompt(string $reviewText): string
    {
        if (mb_strlen($reviewText) > self::MAX_DESCRIPTION_LENGTH) {
            $reviewText = mb_substr($reviewText, 0, self::MAX_DESCRIPTION_LENGTH);
        }

        return <<<PROMPT
You are extracting short highlight tags from a customer product review shown on an e-commerce site, in the style of "Great for Kids", "Waterproof", "Lightweight".

Review: "$reviewText"

Respond with 1 to 3 short tags separated by ", " - each tag 1-4 words, Title Case, describing a real, concrete point actually made in the review (a use case, a quality, who it's good for). Do not invent anything not stated or clearly implied by the review text. If the review doesn't support any confident tag, respond with just: none

Example of the exact format required: Great for Kids, Waterproof, Lightweight

Respond with only the comma-separated tags (or the word none). No quotes, no markdown, no explanation.
PROMPT;
    }

    /**
     * @return string[]
     */
    private function sanitizeTags(string $text): array
    {
        $text = trim($text, " \t\n\r\0\x0B\"'.");
        if ($text === '' || strcasecmp($text, 'none') === 0) {
            return [];
        }

        $tags = array_map('trim', explode(',', $text));
        $tags = array_filter($tags, static function (string $tag): bool {
            return $tag !== '' && mb_strlen($tag) <= 40;
        });

        return array_values(array_slice($tags, 0, 3));
    }

    private function buildPrompt(string $productName, string $description, string $categoryPath): string
    {
        $context = 'Product name: ' . $productName;
        if ($description !== '') {
            $context .= "\nDescription: " . $description;
        }
        if ($categoryPath !== '') {
            $context .= "\nCategory: " . $categoryPath;
        }

        return <<<PROMPT
You are writing a short product highlight line shown directly under a product title on an e-commerce site.

$context

Respond with EXACTLY 3 short selling-point phrases separated by ", " - each phrase 1-2 words, Title Case, describing a real, concrete attribute of this product (material, use case, key feature). Do not invent specs not implied by the context above.

Example of the exact format required: Ultralight, Waterproof, Trail-Ready

Respond with only the 3 comma-separated phrases. No quotes, no markdown, no explanation, no trailing period.
PROMPT;
    }

    /**
     * Product descriptions are rich-text/HTML; Gemini only needs the plain wording.
     */
    private function stripHtml(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim((string) $text);

        return mb_strlen($text) > self::MAX_DESCRIPTION_LENGTH
            ? mb_substr($text, 0, self::MAX_DESCRIPTION_LENGTH)
            : $text;
    }

    private function sanitize(string $text): ?string
    {
        $text = trim($text, " \t\n\r\0\x0B\"'.");
        $text = preg_replace('/\s+/', ' ', $text);

        if ($text === '' || strlen($text) > self::MAX_RESULT_LENGTH || !str_contains($text, ',')) {
            return null;
        }

        return $text;
    }
}

