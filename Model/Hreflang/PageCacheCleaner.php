<?php
declare(strict_types=1);

namespace Panth\Hreflang\Model\Hreflang;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Cms\Model\Page;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\CacheContextFactory;

class PageCacheCleaner
{
    private const TAG_PREFIXES = [
        'product'  => Product::CACHE_TAG,
        'category' => Category::CACHE_TAG,
        'cms_page' => Page::CACHE_TAG,
        'cms'      => Page::CACHE_TAG,
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CacheContextFactory $cacheContextFactory,
        private readonly EventManager $eventManager
    ) {
    }

    public function collectTags(array $groupIds): array
    {
        $groupIds = array_values(array_filter(array_map('intval', $groupIds), static fn (int $id): bool => $id > 0));
        if ($groupIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('panth_seo_hreflang_member'), ['entity_type', 'entity_id'])
                ->where('group_id IN (?)', $groupIds)
        );

        $tags = [];
        foreach ($rows as $row) {
            $prefix = self::TAG_PREFIXES[(string) ($row['entity_type'] ?? '')] ?? null;
            $entityId = (int) ($row['entity_id'] ?? 0);
            if ($prefix !== null && $entityId > 0) {
                $tags[$prefix . '_' . $entityId] = true;
            }
        }

        return array_keys($tags);
    }

    public function clean(array $tags): void
    {
        $tags = array_values(array_unique(array_filter(array_map('strval', $tags), static fn (string $t): bool => $t !== '')));
        if ($tags === []) {
            return;
        }

        $cacheContext = $this->cacheContextFactory->create();
        $cacheContext->registerTags($tags);
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $cacheContext]);
    }
}
