<?php
declare(strict_types=1);

namespace Panth\Hreflang\Test\Unit\Model\Hreflang;

use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\CacheContextFactory;
use Panth\Hreflang\Model\Hreflang\PageCacheCleaner;
use Panth\Hreflang\Test\Unit\DbStubTrait;
use PHPUnit\Framework\TestCase;

class PageCacheCleanerTest extends TestCase
{
    use DbStubTrait;

    private array $events = [];

    private function cleaner(array $fetchAll = [], ?CacheContext $context = null): PageCacheCleaner
    {
        $factory = $this->createStub(CacheContextFactory::class);
        $factory->method('create')->willReturn($context ?? new CacheContext());
        $events = $this->createStub(ManagerInterface::class);
        $events->method('dispatch')->willReturnCallback(function (string $name, array $data = []) {
            $this->events[] = [$name, $data];
        });

        return new PageCacheCleaner($this->resourceStub($this->connectionStub([], $fetchAll)), $factory, $events);
    }

    public function testTagsAreBuiltPerEntityTypeAndDeduplicated(): void
    {
        $tags = $this->cleaner([[
            ['entity_type' => 'product', 'entity_id' => '15'],
            ['entity_type' => 'product', 'entity_id' => '15'],
            ['entity_type' => 'category', 'entity_id' => '4'],
            ['entity_type' => 'cms_page', 'entity_id' => '51'],
            ['entity_type' => 'cms', 'entity_id' => '52'],
            ['entity_type' => 'unknown', 'entity_id' => '9'],
            ['entity_type' => 'product', 'entity_id' => '0'],
        ]])->collectTags(['1', 2]);

        $this->assertSame(['cat_p_15', 'cat_c_4', 'cms_p_51', 'cms_p_52'], $tags);
        $this->assertSame([1, 2], $this->whereValueFor('group_id IN (?)'));
    }

    public function testNoValidGroupIdsSkipsTheQuery(): void
    {
        $this->assertSame([], $this->cleaner([[['entity_type' => 'product', 'entity_id' => 1]]])->collectTags([0, -3]));
        $this->assertFalse($this->hasWhere('group_id IN (?)'));
    }

    public function testCleanDispatchesTheCacheContextWithUniqueTags(): void
    {
        $context = new CacheContext();
        $this->cleaner([], $context)->clean(['cat_p_1', 'cat_p_1', '', 'cms_p_2']);

        $this->assertCount(1, $this->events);
        $this->assertSame('clean_cache_by_tags', $this->events[0][0]);
        $this->assertSame($context, $this->events[0][1]['object']);
        $this->assertSame(['cat_p_1', 'cms_p_2'], array_values($context->getIdentities()));
    }

    public function testCleanWithoutTagsDispatchesNothing(): void
    {
        $this->cleaner()->clean(['', '']);
        $this->cleaner()->clean([]);

        $this->assertSame([], $this->events);
    }
}
