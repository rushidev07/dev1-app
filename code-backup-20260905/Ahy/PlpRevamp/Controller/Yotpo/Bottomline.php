<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Controller\Yotpo;

use Ahy\PlpRevamp\Service\Yotpo\BottomlineFetcher;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

class Bottomline implements HttpGetActionInterface
{
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly RequestInterface  $request,
        private readonly JsonFactory       $jsonFactory,
        private readonly CacheInterface    $cache,
        private readonly BottomlineFetcher $fetcher,
    ) {}

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'public, max-age=3600', true);

        $raw = (array)$this->request->getParam('ids', []);
        $ids = array_values(array_unique(array_filter(array_map('intval', $raw))));

        if (empty($ids)) {
            return $result->setData([]);
        }

        $cacheKey = 'ahy_yotpo_bl_' . md5(implode(',', $ids));
        $cached   = $this->cache->load($cacheKey);
        if ($cached !== false) {
            return $result->setData(json_decode($cached, true) ?? []);
        }

        $reviews = $this->fetcher->fetch($ids);

        // Only cache products that succeeded — transient failures must not be stored as "0 reviews"
        $cacheable = array_filter($reviews, static fn($r) => empty($r['_error']));
        if (!empty($cacheable)) {
            $this->cache->save(
                (string)json_encode($cacheable),
                $cacheKey,
                ['ahy_yotpo_bottomline'],
                self::CACHE_TTL
            );
        }

        // Strip internal error flag before sending to client
        $clientData = array_map(static function (array $r): array {
            unset($r['_error']);
            return $r;
        }, $reviews);

        return $result->setData($clientData);
    }
}
