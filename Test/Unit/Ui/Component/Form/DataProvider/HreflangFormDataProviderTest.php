<?php
declare(strict_types=1);

namespace Panth\Hreflang\Test\Unit\Ui\Component\Form\DataProvider;

use Magento\Framework\DataObject;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\Hreflang\Test\Unit\DbStubTrait;
use Panth\Hreflang\Ui\Component\Form\DataProvider\GenericFormDataProvider;
use Panth\Hreflang\Ui\Component\Form\DataProvider\HreflangFormDataProvider;
use PHPUnit\Framework\TestCase;

class HreflangFormDataProviderTest extends TestCase
{
    use DbStubTrait;

    private function collection(array $items): AbstractCollection
    {
        $collection = $this->createStub(AbstractCollection::class);
        $collection->method('getItems')->willReturn($items);
        return $collection;
    }

    public function testGenericProviderKeysRowsByIdAndCachesResult(): void
    {
        $collection = $this->createMock(AbstractCollection::class);
        $collection->expects($this->once())->method('getItems')->willReturn([
            new DataObject(['id' => 3, 'code' => 'a']),
            new DataObject(['id' => 4, 'code' => 'b']),
        ]);
        $provider = new GenericFormDataProvider('n', 'group_id', 'id', $collection);

        $data = $provider->getData();
        $this->assertSame([3 => ['id' => 3, 'code' => 'a'], 4 => ['id' => 4, 'code' => 'b']], $data);
        $this->assertSame($data, $provider->getData());
    }

    public function testGenericProviderReturnsEmptyPlaceholderForNewRecords(): void
    {
        $provider = new GenericFormDataProvider('n', 'group_id', 'id', $this->collection([]));
        $this->assertSame(['' => []], $provider->getData());
    }

    public function testMembersAreLoadedAndNormalisedPerGroup(): void
    {
        $connection = $this->connectionStub([], [[
            ['member_id' => 11, 'group_id' => 3, 'store_id' => 1, 'entity_id' => 5, 'is_default' => 1, 'locale' => 'en-GB'],
            ['member_id' => 12, 'group_id' => 3, 'store_id' => 2, 'entity_id' => 5, 'is_default' => '0', 'locale' => 'de-DE'],
        ]]);
        $provider = new HreflangFormDataProvider(
            'n',
            'group_id',
            'id',
            $this->collection([new DataObject(['id' => 3, 'code' => 'a'])]),
            $this->resourceStub($connection)
        );

        $members = $provider->getData()[3]['hreflang_members'];

        $this->assertSame(
            ['member_id' => '11', 'group_id' => '3', 'store_id' => '1', 'entity_id' => '5', 'is_default' => '1', 'locale' => 'en-GB'],
            $members[0]
        );
        $this->assertSame('0', $members[1]['is_default']);
        $this->assertSame(3, $this->whereValueFor('group_id = ?'));
    }

    public function testNewRecordGetsEmptyMemberList(): void
    {
        $provider = new HreflangFormDataProvider(
            'n',
            'group_id',
            'id',
            $this->collection([]),
            $this->resourceStub($this->connectionStub())
        );

        $this->assertSame(['' => ['hreflang_members' => []]], $provider->getData());
        $this->assertSame([], $this->wheres);
    }
}
