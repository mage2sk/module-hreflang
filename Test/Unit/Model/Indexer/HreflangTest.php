<?php
declare(strict_types=1);

namespace Panth\Hreflang\Test\Unit\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\Hreflang\Api\HreflangResolverInterface;
use Panth\Hreflang\Model\Indexer\Hreflang;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HreflangTest extends TestCase
{
    private array $updates = [];
    private array $wheres = [];

    private function build(
        AdapterInterface $connection,
        HreflangResolverInterface $resolver,
        ?LoggerInterface $logger = null
    ): Hreflang {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new Hreflang($resource, $resolver, new Json(), $logger ?? $this->createStub(LoggerInterface::class));
    }

    private function connection(array $rows): AdapterInterface
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('distinct')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond) use ($select) {
            $this->wheres[] = $cond;
            return $select;
        });
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('tableColumnExists')->willReturn(true);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($rows);
        $connection->method('quoteInto')->willReturnCallback(
            static fn($text, $value) => str_replace('?', implode(',', (array) $value), $text)
        );
        $connection->method('update')->willReturnCallback(function ($table, $bind, $where) {
            $this->updates[] = [$table, $bind, $where];
            return 1;
        });
        return $connection;
    }

    private function resolverReturning(array $alternates): HreflangResolverInterface
    {
        $resolver = $this->createStub(HreflangResolverInterface::class);
        $resolver->method('getAlternates')->willReturn($alternates);
        return $resolver;
    }

    public function testReindexDoesNothingWithoutResolvedTable(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(false);
        $connection->expects($this->never())->method('select');
        $connection->expects($this->never())->method('update');
        $resolver = $this->createMock(HreflangResolverInterface::class);
        $resolver->expects($this->never())->method('getAlternates');

        $indexer = $this->build($connection, $resolver);
        $this->assertNull($indexer->getTargetTable());
        $indexer->executeFull();
        $indexer->execute([1, 2]);
    }

    public function testNoTargetWhenPayloadColumnMissing(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('tableColumnExists')->willReturn(false);
        $indexer = $this->build($connection, $this->createStub(HreflangResolverInterface::class));
        $this->assertNull($indexer->getTargetTable());
    }

    public function testTargetTableIsReturnedWhenColumnExists(): void
    {
        $indexer = $this->build($this->connection([]), $this->createStub(HreflangResolverInterface::class));
        $this->assertSame('panth_seo_resolved', $indexer->getTargetTable());
    }

    public function testWritesPayloadWhenResolvedTableExists(): void
    {
        $connection = $this->connection([
            ['store_id' => '1', 'entity_type' => 'product', 'entity_id' => '5'],
        ]);

        $this->build($connection, $this->resolverReturning([['locale' => 'en-GB']]))->executeFull();

        $this->assertSame([[
            'panth_seo_resolved',
            ['hreflang_payload' => '[{"locale":"en-GB"}]'],
            ['store_id = ?' => 1, 'entity_type = ?' => 'product', 'entity_id = ?' => 5],
        ]], $this->updates);
    }

    public function testEmptyAlternatesClearThePayload(): void
    {
        $connection = $this->connection([
            ['store_id' => '2', 'entity_type' => 'category', 'entity_id' => '9'],
        ]);

        $this->build($connection, $this->resolverReturning([]))->executeFull();

        $this->assertSame(['hreflang_payload' => null], $this->updates[0][1]);
    }

    public function testRowsAreGroupedPerStoreAndEntityType(): void
    {
        $connection = $this->connection([
            ['store_id' => '1', 'entity_type' => 'product', 'entity_id' => '5'],
            ['store_id' => '2', 'entity_type' => 'product', 'entity_id' => '5'],
            ['store_id' => '1', 'entity_type' => 'product', 'entity_id' => '6'],
        ]);
        $calls = [];
        $resolver = $this->createStub(HreflangResolverInterface::class);
        $resolver->method('getAlternates')->willReturnCallback(
            static function ($type, $id, $store) use (&$calls) {
                $calls[] = [$type, $id, $store];
                return [];
            }
        );

        $this->build($connection, $resolver)->executeFull();

        $this->assertSame([['product', 5, 1], ['product', 6, 1], ['product', 5, 2]], $calls);
        $this->assertCount(3, $this->updates);
    }

    public function testResolverFailureIsLoggedAndSkipsOnlyThatEntity(): void
    {
        $connection = $this->connection([
            ['store_id' => '1', 'entity_type' => 'cms', 'entity_id' => '3'],
            ['store_id' => '1', 'entity_type' => 'cms', 'entity_id' => '4'],
        ]);
        $resolver = $this->createStub(HreflangResolverInterface::class);
        $resolver->method('getAlternates')->willReturnCallback(static function ($type, $id) {
            if ($id === 3) {
                throw new \RuntimeException('broken');
            }
            return [['locale' => 'en-GB']];
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            '[panth_hreflang] getAlternates failed store=1 type=cms id=3: broken'
        );

        $this->build($connection, $resolver, $logger)->executeFull();

        $this->assertCount(1, $this->updates);
        $this->assertSame(4, $this->updates[0][2]['entity_id = ?']);
    }

    public function testExecuteWithEmptyIdsIsANoOp(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('isTableExists');
        $this->build($connection, $this->createStub(HreflangResolverInterface::class))->execute([]);
    }

    public function testPartialReindexMatchesMemberOrGroupIds(): void
    {
        $connection = $this->connection([
            ['store_id' => '3', 'entity_type' => 'product', 'entity_id' => '7'],
        ]);

        $this->build($connection, $this->resolverReturning([['locale' => 'fr-FR']]))->execute(['4', 5]);

        $this->assertSame(['member_id IN (4,5) OR group_id IN (4,5)'], $this->wheres);
        $this->assertSame('[{"locale":"fr-FR"}]', $this->updates[0][1]['hreflang_payload']);
    }

    public function testExecuteRowAndListDelegateToExecute(): void
    {
        $connection = $this->connection([
            ['store_id' => '1', 'entity_type' => 'product', 'entity_id' => '2'],
        ]);
        $indexer = $this->build($connection, $this->resolverReturning([]));

        $indexer->executeRow('8');
        $indexer->executeList([9]);

        $this->assertSame([
            'member_id IN (8) OR group_id IN (8)',
            'member_id IN (9) OR group_id IN (9)',
        ], $this->wheres);
        $this->assertCount(2, $this->updates);
    }
}
