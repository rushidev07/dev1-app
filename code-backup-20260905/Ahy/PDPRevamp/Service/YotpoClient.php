<?php

namespace Ahy\PDPRevamp\Service;

use Ahy\PDPRevamp\Logger\Logger as YotpoApiLogger;
use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Review\Model\Review as ReviewModel;
use Yotpo\Yotpo\Model\Config as YotpoConfig;

/**
 * Single entry point for every Yotpo API call made from the storefront
 * (reviews, review submission, review votes). Centralizing the calls here
 * keeps the app key/secret out of client-side JS and lets every PDP surface
 * (star/count line, full reviews section, review-quote card) share one
 * cached, authenticated client instead of each hitting Yotpo directly.
 */
class YotpoClient
{
    private const WIDGET_API_BASE = 'https://api-cdn.yotpo.com/v1/widget/';
    private const API_BASE = 'https://api.yotpo.com/';

    private const CACHE_TAG = 'ahy_yotpo_api';
    private const CACHE_TTL = 900; // 15 minutes

    /**
     * Yotpo's widget API caches each per_page URL variant independently and is
     * only eventually consistent, so the same request can answer with a short
     * (or empty) review list while pagination.total still reports the real
     * count. Observed on this account: per_page=5 returned 0 reviews,
     * per_page=100 returned 1 and per_page=150 returned 2 - for a product with
     * 2 - and a given variant's answer changed between calls minutes apart.
     *
     * So: never treat a short list as authoritative, and retry once when the
     * response contradicts its own total. Two attempts is enough to shake off a
     * stale variant without turning one page view into a request storm.
     */
    private const MAX_ATTEMPTS = 3;

    /**
     * Absolute ceiling on reviews pulled in one go, so a wild total from the
     * API can't turn into a multi-megabyte response.
     */
    private const MAX_REVIEWS = 1000;

    /** @var ClientFactory */
    private $clientFactory;

    /** @var YotpoConfig */
    private $yotpoConfig;

    /** @var CacheInterface */
    private $cache;

    /** @var Json */
    private $serializer;

    /** @var YotpoApiLogger */
    private $logger;

    /** @var ResourceConnection */
    private $resourceConnection;

    /** @var GeminiClient */
    private $geminiClient;

    public function __construct(
        ClientFactory $clientFactory,
        YotpoConfig $yotpoConfig,
        CacheInterface $cache,
        Json $serializer,
        YotpoApiLogger $logger,
        ResourceConnection $resourceConnection,
        GeminiClient $geminiClient
    ) {
        $this->clientFactory = $clientFactory;
        $this->yotpoConfig = $yotpoConfig;
        $this->cache = $cache;
        $this->serializer = $serializer;
        $this->logger = $logger;
        $this->resourceConnection = $resourceConnection;
        $this->geminiClient = $geminiClient;
    }

    /**
     * Fetch reviews + bottomline (average score, total review count) for a
     * product. Yotpo is the source of truth; when it has nothing yet for
     * this product (not configured, API error, or simply no reviews
     * synced there), fall back to Magento's own native review data so the
     * storefront isn't left empty while a product's reviews haven't made
     * it into Yotpo.
     */
    /**
     * The per_page sizes getReviews() is actually called with across the
     * storefront, so a vote can clear every cached variant for one product.
     *
     * Kept as a list because the cache key includes per_page: the reviews
     * section asks for 150, getTopReview() for 20 and getBottomline() for 1, so
     * clearing only one of them would leave the others serving a stale count.
     */
    private const CACHED_PER_PAGE_VARIANTS = [1, 20, 150];

    /**
     * Drop this product's cached reviews so the next read goes back to Yotpo.
     *
     * Called after a vote: Yotpo's vote endpoint returns neither the new total
     * nor a meaningful status code, so the only way to show a trustworthy count
     * is to re-fetch rather than to guess locally.
     */
    public function invalidateProductReviews(int $productId): void
    {
        if ($productId < 1) {
            return;
        }

        foreach (self::CACHED_PER_PAGE_VARIANTS as $perPage) {
            $this->cache->remove('ahy_yotpo_reviews_' . $productId . '_' . $perPage);
        }
    }

    public function getReviews(int $productId, int $perPage = 150): array
    {
        $cacheKey = 'ahy_yotpo_reviews_' . $productId . '_' . $perPage;
        $cached = $this->cache->load($cacheKey);
        if ($cached) {
            return $this->serializer->unserialize($cached);
        }

        $data = $this->fetchFromYotpo($productId, $perPage);

        // Only fall back when Yotpo could not be reached or answered with an
        // error - null. A successful response with zero reviews is a real
        // answer and must be shown as such: previously "empty" also meant
        // "failed", so a product with no Yotpo reviews silently rendered
        // Magento's native ones instead, leaving the star line (Yotpo) and the
        // reviews list (native) disagreeing on the same page.
        if ($data === null) {
            $data = $this->getNativeReviewsFallback($productId, $perPage);
        }

        $data = $this->enrichWithAiTags($data);

        $this->cache->save(
            $this->serializer->serialize($data),
            $cacheKey,
            [self::CACHE_TAG],
            self::CACHE_TTL
        );

        return $data;
    }

    /**
     * Fills each review's custom_fields.tags with AI-generated highlight
     * tags (e.g. "Great for Kids", "Waterproof") - the storefront review
     * card already renders whatever is there (see yotpo_reviews.phtml), so
     * no frontend change is needed, only this data. Tags are generated once
     * per review and cached forever in ahy_pdprevamp_review_tags, so a
     * review already tagged never calls Gemini again even after this
     * response's own 15-minute cache expires and the reviews are re-fetched
     * from Yotpo.
     */
    private function enrichWithAiTags(array $data): array
    {
        $reviews = $data['response']['reviews'] ?? [];
        if (!$reviews) {
            return $data;
        }

        foreach ($reviews as &$review) {
            $reviewId = (string) ($review['id'] ?? '');
            if ($reviewId === '') {
                continue;
            }

            $tags = $this->getCachedTags($reviewId);
            if ($tags === null) {
                $tags = $this->geminiClient->generateReviewTags(
                    (string) ($review['title'] ?? ''),
                    (string) ($review['content'] ?? '')
                );
                $this->saveTags($reviewId, $tags);
            }

            if ($tags) {
                $review['custom_fields'] = array_merge(
                    is_array($review['custom_fields'] ?? null) ? $review['custom_fields'] : [],
                    ['tags' => $tags]
                );
            }
        }
        unset($review);

        $data['response']['reviews'] = $reviews;

        return $data;
    }

    /**
     * @return string[]|null Null means "never generated yet", not "generated as empty".
     */
    private function getCachedTags(string $reviewId): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($connection->getTableName('ahy_pdprevamp_review_tags'), ['tags'])
                ->where('review_id = ?', $reviewId)
        );

        if ($row === false) {
            return null;
        }

        $tags = (string) $row['tags'];

        return $tags === '' ? [] : explode(',', $tags);
    }

    private function saveTags(string $reviewId, array $tags): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->insertOnDuplicate(
            $connection->getTableName('ahy_pdprevamp_review_tags'),
            ['review_id' => $reviewId, 'tags' => implode(',', $tags)],
            ['tags']
        );
    }

    /**
     * Yotpo reviews for a product, paging past the API's 100-per-request cap
     * so callers can still ask for "all of them".
     *
     * Returns null - and only null - when Yotpo could not be consulted at all,
     * which is the signal getReviews() uses to fall back to native reviews. An
     * empty review list with a valid response is returned as-is.
     */
    private function fetchFromYotpo(int $productId, int $perPage): ?array
    {
        $appKey = $this->getAppKey();
        if (!$appKey) {
            $this->logger->error('getReviews skipped for product ' . $productId . ': no Yotpo app key configured');
            return null;
        }

        // Step 1: the dedicated bottomline endpoint is the only consistently
        // correct source for the real count - the reviews endpoint's own
        // pagination.total comes back as 0 on exactly the requests where the
        // review list is wrongly empty, so it cannot be used to detect that.
        $bottomline = $this->requestBottomline($appKey, $productId);
        if ($bottomline === null) {
            return null;
        }

        $total = (int) ($bottomline['total_review'] ?? 0);

        // Genuinely no Yotpo reviews. Authoritative, so return an empty list
        // rather than letting the caller fall back to native reviews.
        if ($total < 1) {
            return $this->composeResponse([], $bottomline);
        }

        // Step 2: ask for exactly as many as exist. Asking for far more than a
        // product has is what makes Yotpo answer with an empty set (per_page=150
        // returned 0 reviews for a product with 1, while per_page=20 returned
        // it), and its page-2 responses can be empty on products that do have a
        // page 2 - so one right-sized request beats paging.
        $requestSize = min($total, self::MAX_REVIEWS);
        $reviews = [];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $payload = $this->requestReviews($appKey, $productId, $requestSize);

            if ($payload !== null) {
                $returned = $payload['response']['reviews'] ?? [];
                if (is_array($returned) && count($returned) > count($reviews)) {
                    $reviews = $returned;
                }
                if (count($reviews) >= $total) {
                    break;
                }
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                $this->logger->error(sprintf(
                    'getReviews got %d of %d reviews for product %d (attempt %d/%d), retrying',
                    count($reviews),
                    $total,
                    $productId,
                    $attempt,
                    self::MAX_ATTEMPTS
                ));
            }
        }

        if ($reviews === []) {
            // Bottomline says reviews exist but we could not retrieve any -
            // treat as a failure so the native fallback can cover the gap.
            $this->logger->error(sprintf(
                'getReviews could not retrieve any of %d reviews for product %d',
                $total,
                $productId
            ));
            return null;
        }

        if (count($reviews) < $total) {
            $this->logger->error(sprintf(
                'getReviews returning %d of %d reviews for product %d - Yotpo served a short list',
                count($reviews),
                $total,
                $productId
            ));
        }

        return $this->composeResponse($reviews, $bottomline);
    }

    /**
     * Yotpo-shaped response using the authoritative bottomline totals, so the
     * star line and "N reviews" label stay correct even when the list itself
     * comes back short.
     */
    private function composeResponse(array $reviews, array $bottomline): array
    {
        return [
            'status' => ['code' => 200, 'message' => 'OK'],
            'response' => [
                'reviews' => $reviews,
                'bottomline' => [
                    'average_score' => (float) ($bottomline['average_score'] ?? 0),
                    'total_review' => (int) ($bottomline['total_review'] ?? 0),
                ],
                'pagination' => [
                    'page' => 1,
                    'per_page' => count($reviews),
                    'total' => (int) ($bottomline['total_review'] ?? 0),
                ],
            ],
        ];
    }

    /**
     * The bottomline endpoint (average score + review count). Separate URL from
     * reviews.json, and reliable where that one is not.
     */
    private function requestBottomline(string $appKey, int $productId): ?array
    {
        try {
            $client = $this->clientFactory->create();
            $response = $client->request(
                'GET',
                self::WIDGET_API_BASE . $appKey . '/products/' . $productId . '/bottomline',
                ['headers' => ['Accept' => 'application/json']]
            );

            $payload = $this->serializer->unserialize((string) $response->getBody());
            $bottomline = $payload['response']['bottomline'] ?? null;

            if (!is_array($bottomline)) {
                $this->logger->error('getReviews got no bottomline for product ' . $productId);
                return null;
            }

            return $bottomline;
        } catch (GuzzleException $exception) {
            $this->logger->error(
                'bottomline failed for product ' . $productId . ': ' . $exception->getMessage()
            );
            return null;
        } catch (\InvalidArgumentException $exception) {
            $this->logger->error(
                'bottomline could not be decoded for product ' . $productId . ': ' . $exception->getMessage()
            );
            return null;
        }
    }

    /**
     * One call to the reviews endpoint. Null on transport error, on a non-2xx
     * payload, or on a body we cannot parse - anything where the review list
     * cannot be trusted. Deliberately no "page" parameter: Yotpo's widget
     * pagination is unreliable on this account (page=2 returned an empty list
     * for a product whose total was 2), so walking pages risks dropping
     * reviews rather than collecting them.
     */
    private function requestReviews(string $appKey, int $productId, int $perPage): ?array
    {
        try {
            $client = $this->clientFactory->create();
            $response = $client->request(
                'GET',
                self::WIDGET_API_BASE . $appKey . '/products/' . $productId . '/reviews.json',
                [
                    'query' => ['per_page' => $perPage],
                    'headers' => ['Accept' => 'application/json'],
                ]
            );

            $payload = $this->serializer->unserialize((string) $response->getBody());
            if (!is_array($payload) || !isset($payload['response'])) {
                $this->logger->error(
                    'getReviews got an unusable payload for product ' . $productId
                    . ' (per_page ' . $perPage . ')'
                );
                return null;
            }

            $code = (int) ($payload['status']['code'] ?? 200);
            if ($code >= 400) {
                $this->logger->error(
                    'getReviews returned status ' . $code . ' for product ' . $productId
                    . ': ' . (string) ($payload['status']['message'] ?? '')
                );
                return null;
            }

            return $payload;
        } catch (GuzzleException $exception) {
            $this->logger->error(
                'getReviews failed for product ' . $productId
                . ' (per_page ' . $perPage . '): ' . $exception->getMessage()
            );
            return null;
        } catch (\InvalidArgumentException $exception) {
            // Non-JSON body - the serializer throws rather than returning null.
            $this->logger->error(
                'getReviews could not decode the response for product ' . $productId . ': ' . $exception->getMessage()
            );
            return null;
        }
    }

    /**
     * Builds a Yotpo-shaped reviews response (same "response.reviews" /
     * "response.bottomline" structure as the real API) from Magento's own
     * approved product reviews, so every consumer of getReviews() /
     * getBottomline() / getTopReview() works the same regardless of which
     * source the data came from.
     */
    private function getNativeReviewsFallback(int $productId, int $perPage): array
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(['r' => $connection->getTableName('review')], ['review_id', 'created_at'])
            ->join(
                ['re' => $connection->getTableName('review_entity')],
                're.entity_id = r.entity_id',
                []
            )
            ->join(
                ['rd' => $connection->getTableName('review_detail')],
                'rd.review_id = r.review_id',
                ['detail', 'nickname', 'title']
            )
            ->joinLeft(
                ['rov' => $connection->getTableName('rating_option_vote')],
                'rov.review_id = r.review_id',
                ['avg_percent' => new \Zend_Db_Expr('AVG(rov.percent)')]
            )
            ->where('re.entity_code = ?', 'product')
            ->where('r.entity_pk_value = ?', $productId)
            ->where('r.status_id = ?', ReviewModel::STATUS_APPROVED)
            ->group('r.review_id')
            ->order('r.created_at DESC')
            ->limit($perPage);

        $rows = $connection->fetchAll($select);

        $reviews = [];
        foreach ($rows as $row) {
            $percent = $row['avg_percent'] !== null ? (float) $row['avg_percent'] : null;

            $reviews[] = [
                'id' => 'native_' . $row['review_id'],
                'content' => (string) $row['detail'],
                'title' => (string) $row['title'],
                'score' => $percent !== null ? round($percent / 20, 1) : 0,
                'votes_up' => 0,
                'created_at' => (new \DateTime($row['created_at']))->format(\DateTime::ATOM),
                'user' => ['display_name' => (string) $row['nickname']],
            ];
        }

        // Bottomline totals reflect every approved review for the product,
        // not just the (possibly perPage=1, for quick bottomline-only
        // calls) page of reviews fetched above.
        $totalsSelect = $connection->select()
            ->from(['r' => $connection->getTableName('review')], [])
            ->join(
                ['re' => $connection->getTableName('review_entity')],
                're.entity_id = r.entity_id',
                []
            )
            ->joinLeft(
                ['rov' => $connection->getTableName('rating_option_vote')],
                'rov.review_id = r.review_id',
                []
            )
            ->where('re.entity_code = ?', 'product')
            ->where('r.entity_pk_value = ?', $productId)
            ->where('r.status_id = ?', ReviewModel::STATUS_APPROVED)
            ->columns([
                'total_review' => new \Zend_Db_Expr('COUNT(DISTINCT r.review_id)'),
                'average_percent' => new \Zend_Db_Expr('AVG(rov.percent)'),
            ]);
        $totals = $connection->fetchRow($totalsSelect);

        $averagePercent = $totals['average_percent'] !== null ? (float) $totals['average_percent'] : null;

        return [
            'response' => [
                'reviews' => $reviews,
                'bottomline' => [
                    'average_score' => $averagePercent !== null ? round($averagePercent / 20, 1) : 0,
                    'total_review' => (int) ($totals['total_review'] ?? 0),
                ],
            ],
        ];
    }

    /**
     * Just the bottomline (average score, review count) for a single
     * product, without the review list - used by grids/carousels that show
     * many products' star ratings at once and don't need full review text.
     *
     * @return array{average_score: float, total_reviews: int}
     */
    public function getBottomline(int $productId): array
    {
        $data = $this->getReviews($productId, 1);
        $bottomline = $data['response']['bottomline'] ?? [];

        return [
            'average_score' => (float) ($bottomline['average_score'] ?? 0),
            'total_reviews' => (int) ($bottomline['total_review'] ?? 0),
        ];
    }

    /**
     * Return the highest-rated real review for a product, or null when there
     * are none yet - no fabricated fallback.
     *
     * Ordered by score first: sorting on votes_up alone left this at the mercy
     * of Yotpo's default order, because votes_up is 0 for almost every review,
     * which meant the newest review won - a 1-star one could end up as the
     * highlighted quote. Votes then recency only break ties between reviews
     * that already share the top score.
     */
    public function getTopReview(int $productId): ?array
    {
        $data = $this->getReviews($productId, 20);
        $reviews = $data['response']['reviews'] ?? [];
        if (empty($reviews)) {
            return null;
        }

        usort($reviews, static function (array $a, array $b): int {
            // Descending on score, then votes, then recency.
            return [
                (float) ($b['score'] ?? 0),
                (int) ($b['votes_up'] ?? 0),
                strtotime((string) ($b['created_at'] ?? '')) ?: 0,
            ] <=> [
                (float) ($a['score'] ?? 0),
                (int) ($a['votes_up'] ?? 0),
                strtotime((string) ($a['created_at'] ?? '')) ?: 0,
            ];
        });

        $top = $reviews[0];

        return [
            'content' => $top['content'] ?? '',
            'score' => (float) ($top['score'] ?? 0),
            'reviewer_name' => $top['user']['display_name'] ?? __('Verified Buyer'),
        ];
    }

    /**
     * Submit a new review on behalf of a storefront customer.
     *
     * Yotpo's create-review endpoint has no parameter for attaching images
     * directly (no base64, no file upload) - any 'images' key here is
     * stripped before the request goes out, since sending one caused Yotpo
     * to reject the whole submission. A successful response instead carries
     * an image_upload_token; the caller uses that with uploadReviewImages()
     * to attach photos in a second call, once they have public URLs.
     */
    public function createReview(array $payload): array
    {
        $appKey = $this->getAppKey();
        if (!$appKey) {
            return ['status' => ['message' => 'Yotpo is not configured']];
        }

        unset($payload['images']);
        $payload['appkey'] = $appKey;

        try {
            $client = $this->clientFactory->create();
            $response = $client->request(
                'POST',
                self::API_BASE . 'v1/widget/reviews',
                [
                    'json' => $payload,
                    'headers' => ['Accept' => 'application/json'],
                ]
            );

            return $this->serializer->unserialize((string) $response->getBody());
        } catch (GuzzleException $exception) {
            $detail = $exception->getMessage();
            $yotpoMessage = null;
            if ($exception instanceof RequestException && $exception->getResponse()) {
                $body = (string) $exception->getResponse()->getBody();
                $detail .= ' | response body: ' . $body;
                try {
                    $yotpoMessage = $this->serializer->unserialize($body)['status']['message'] ?? null;
                } catch (\InvalidArgumentException $jsonException) {
                    // Not JSON - fall through to the generic message below.
                }
            }
            $this->logger->error('createReview failed: ' . $detail);
            return ['status' => ['message' => $yotpoMessage ?? 'Something went wrong. Please try again.']];
        }
    }

    /**
     * Attach photos to a review just created via createReview(), using the
     * image_upload_token from its response. Yotpo requires publicly
     * reachable URLs here (never base64/data URLs), and a private
     * X-Yotpo-Token account token distinct from the widget appkey - see
     * getAccessToken(). Never throws: one review's photos failing to attach
     * (bad token, Yotpo outage) must not affect the review itself, which is
     * already saved by this point.
     */
    public function uploadReviewImages(string $imageUploadToken, array $imageUrls): bool
    {
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            $this->logger->error('uploadReviewImages skipped: could not obtain a Yotpo access token');
            return false;
        }

        try {
            $client = $this->clientFactory->create();
            $client->request(
                'POST',
                self::API_BASE . 'images/process',
                [
                    'json' => [
                        'image_upload_token' => $imageUploadToken,
                        'image_urls' => $imageUrls,
                    ],
                    'headers' => [
                        'Accept' => 'application/json',
                        'X-Yotpo-Token' => $accessToken,
                    ],
                ]
            );

            return true;
        } catch (GuzzleException $exception) {
            $detail = $exception->getMessage();
            if ($exception instanceof RequestException && $exception->getResponse()) {
                $detail .= ' | response body: ' . (string) $exception->getResponse()->getBody();
            }
            $this->logger->error('uploadReviewImages failed: ' . $detail);
            return false;
        }
    }

    /**
     * Exchanges the account's appkey/secret for a short-lived utoken via
     * Yotpo's OAuth client-credentials flow. Needed only for
     * uploadReviewImages() - createReview() and the read endpoints
     * authenticate with the appkey alone. Fetched fresh each call rather
     * than cached: photo uploads are rare (one per review with photos), so
     * the extra request isn't worth the risk of reusing a token past its
     * undocumented expiry.
     */
    private function getAccessToken(): ?string
    {
        $appKey = $this->getAppKey();
        $secret = $this->yotpoConfig->getSecret();
        if (!$appKey || !$secret) {
            return null;
        }

        try {
            $client = $this->clientFactory->create();
            $response = $client->request(
                'POST',
                self::API_BASE . 'oauth/token',
                [
                    'json' => [
                        'client_id' => $appKey,
                        'client_secret' => $secret,
                        'grant_type' => 'client_credentials',
                    ],
                    'headers' => ['Accept' => 'application/json'],
                ]
            );

            $body = $this->serializer->unserialize((string) $response->getBody());
            return $body['access_token'] ?? null;
        } catch (GuzzleException $exception) {
            $this->logger->error('Yotpo oauth/token request failed: ' . $exception->getMessage());
            return null;
        }
    }

    /**
     * Vote a review up or down.
     */
    /**
     * No longer called: Yotpo's public vote endpoint does not persist votes sent
     * this way. It answers 200 OK and leaves votes_up unchanged - confirmed on
     * dev, where a recorded vote left Yotpo's votes_up at 8, and against the live
     * API, which returns 200 OK even for a nonexistent review id or an invalid
     * direction like vote/sideways. Votes are stored in
     * ahy_pdprevamp_review_vote and folded into the displayed counts by
     * Controller/Index/Reviews instead.
     *
     * Kept rather than deleted because it is the only implementation of this
     * call: if the endpoint's authentication requirements are ever established
     * (it sends no app key or token today, which is the likeliest reason the
     * votes are dropped), this is where that belongs.
     */
    public function voteReview(string $reviewId, string $voteType, bool $unvote = false): array
    {
        try {
            $client = $this->clientFactory->create();
            $uri = self::API_BASE . 'reviews/' . $reviewId . '/vote/' . $voteType;
            if ($unvote) {
                $uri .= '/true';
            }
            $response = $client->request('POST', $uri, ['headers' => ['Accept' => 'application/json']]);

            return $this->serializer->unserialize((string) $response->getBody());
        } catch (GuzzleException $exception) {
            $this->logger->error('voteReview failed for review ' . $reviewId . ': ' . $exception->getMessage());
            return ['status' => ['message' => 'Something went wrong. Please try again.']];
        }
    }

    private function getAppKey(): ?string
    {
        $appKey = $this->yotpoConfig->getAppKey();
        return $appKey ?: null;
    }
}
