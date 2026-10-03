<?php
declare(strict_types=1);

namespace Panth\Hreflang\Test\Unit;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;

/**
 * Fluent Select / connection doubles that record WHERE clauses and serve queued results.
 */
trait DbStubTrait
{
    /** @var array<int,array{0:mixed,1:mixed}> */
    protected array $wheres = [];

    protected function selectStub(): Select
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'join', 'joinLeft', 'distinct', 'group', 'having', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->wheres[] = [$cond, $value];
            return $select;
        });
        return $select;
    }

    /**
     * @param array<int,mixed> $fetchOne queued fetchOne results
     * @param array<int,array> $fetchAll queued fetchAll results
     * @param array<int,array> $fetchCol queued fetchCol results
     */
    protected function connectionStub(
        array $fetchOne = [],
        array $fetchAll = [],
        array $fetchCol = [],
        string $class = AdapterInterface::class
    ): AdapterInterface {
        $connection = $this->createStub($class);
        $connection->method('select')->willReturnCallback(fn() => $this->selectStub());
        $connection->method('fetchOne')->willReturnCallback(static function () use (&$fetchOne) {
            return $fetchOne === [] ? false : array_shift($fetchOne);
        });
        $connection->method('fetchAll')->willReturnCallback(static function () use (&$fetchAll) {
            return $fetchAll === [] ? [] : array_shift($fetchAll);
        });
        $connection->method('fetchCol')->willReturnCallback(static function () use (&$fetchCol) {
            return $fetchCol === [] ? [] : array_shift($fetchCol);
        });
        $connection->method('quoteInto')->willReturnCallback(
            static fn($text, $value) => str_replace(
                '?',
                is_array($value) ? implode(',', $value) : (string) $value,
                $text
            )
        );
        return $connection;
    }

    protected function resourceStub(AdapterInterface $connection): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    protected function whereValueFor(string $condition): mixed
    {
        foreach ($this->wheres as [$cond, $value]) {
            if ($cond === $condition) {
                return $value;
            }
        }
        return null;
    }

    protected function hasWhere(string $condition): bool
    {
        foreach ($this->wheres as [$cond]) {
            if ($cond === $condition) {
                return true;
            }
        }
        return false;
    }
}
