<?php
declare(strict_types=1);

namespace Panth\Hreflang\Test\Unit\Ui\Component\Listing\DataProvider;

use Magento\Framework\Api\Filter;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\App\ResourceConnection;
use Panth\Hreflang\Model\ResourceModel\HreflangGroup\Collection;
use Panth\Hreflang\Model\ResourceModel\HreflangGroup\CollectionFactory;
use Panth\Hreflang\Ui\Component\Listing\DataProvider\HreflangDataProvider;
use PHPUnit\Framework\TestCase;

class HreflangDataProviderTest extends TestCase
{
    private array $joins = [];
    private array $selectWheres = [];
    private array $fieldFilters = [];
    private bool $loaded = false;

    private function provider(array $items = []): HreflangDataProvider
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn($c) => '`' . $c . '`');
        $connection->method('quoteInto')->willReturnCallback(
            static fn($text, $value) => str_replace('?', "'" . $value . "'", $text)
        );

        $select = $this->createStub(Select::class);
        $select->method('getConnection')->willReturn($connection);
        $select->method('joinLeft')->willReturnCallback(function ($name, $cond, $cols) use ($select) {
            $this->joins[] = [$name, $cond, (string) $cols['member_count']];
            return $select;
        });
        $select->method('group')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond) use ($select) {
            $this->selectWheres[] = $cond;
            return $select;
        });

        $collection = $this->createStub(Collection::class);
        $collection->method('getSelect')->willReturn($select);
        $collection->method('isLoaded')->willReturnCallback(fn() => $this->loaded);
        $collection->method('load')->willReturnCallback(function () use ($collection) {
            $this->loaded = true;
            return $collection;
        });
        $collection->method('getIterator')->willReturnCallback(static fn() => new \ArrayIterator($items));
        $collection->method('getSize')->willReturn(count($items));
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $cond) use ($collection) {
            $this->fieldFilters[] = [$field, $cond];
            return $collection;
        });

        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getTableName')->willReturnArgument(0);

        return new HreflangDataProvider('n', 'group_id', 'id', $factory, $resource);
    }

    private function filter(string $field, $value, string $type = 'eq'): Filter
    {
        return new Filter(['field' => $field, 'value' => $value, 'condition_type' => $type]);
    }

    public function testGetDataReturnsItemsWithMemberCountJoinedOnce(): void
    {
        $provider = $this->provider([new DataObject(['group_id' => 1, 'member_count' => 2])]);

        $first = $provider->getData();
        $provider->getData();

        $this->assertSame(['totalRecords' => 1, 'items' => [['group_id' => 1, 'member_count' => 2]]], $first);
        $this->assertCount(1, $this->joins);
        $this->assertSame('COUNT(m.member_id)', $this->joins[0][2]);
        $this->assertSame(['m' => 'panth_seo_hreflang_member'], $this->joins[0][0]);
        $this->assertTrue($this->loaded);
    }

    public function testMemberCountFilterIsIgnored(): void
    {
        $this->provider()->addFilter($this->filter('member_count', '3'));
        $this->assertSame([], $this->fieldFilters);
        $this->assertSame([], $this->selectWheres);
    }

    public function testFulltextSearchesCodeTypeAndNotesWithEscapedWildcards(): void
    {
        $this->provider()->addFilter($this->filter('fulltext', ' 50%_off '));

        $this->assertSame([
            "`main_table.code` LIKE '%50\\%\\_off%' OR `main_table.entity_type` LIKE '%50\\%\\_off%'"
            . " OR `main_table.notes` LIKE '%50\\%\\_off%'",
        ], $this->selectWheres);
    }

    public function testBlankOrNonScalarFulltextIsIgnored(): void
    {
        $provider = $this->provider();
        $provider->addFilter($this->filter('fulltext', '   '));
        $provider->addFilter($this->filter('fulltext', ['a']));
        $this->assertSame([], $this->selectWheres);
    }

    public function testOtherFiltersFallThroughToTheCollection(): void
    {
        $this->provider()->addFilter($this->filter('entity_type', 'product'));
        $this->assertSame([['entity_type', ['eq' => 'product']]], $this->fieldFilters);
    }
}
