<?php
declare(strict_types=1);

namespace Panth\Hreflang\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\ActionInterface as IndexerActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\Hreflang\Api\HreflangResolverInterface;
use Psr\Log\LoggerInterface;

class Hreflang implements IndexerActionInterface, MviewActionInterface
{
    public const INDEXER_ID = 'panth_seo_hreflang';

    public const TARGET_TABLE = 'panth_seo_resolved';

    public const TARGET_COLUMN = 'hreflang_payload';

    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly HreflangResolverInterface $hreflangResolver,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    public function executeFull(): void
    {
        $resolvedTable = $this->getTargetTable();
        if ($resolvedTable === null) {
            return;
        }
        $connection = $this->resource->getConnection();
        $memberTable = $this->resource->getTableName('panth_seo_hreflang_member');

        $select = $connection->select()
            ->from($memberTable, ['store_id', 'entity_type', 'entity_id'])
            ->distinct(true);

        foreach ($this->bucketRows($connection->fetchAll($select)) as $key => $ids) {
            [$storeId, $type] = explode(':', $key, 2);
            foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
                $this->updateBatch($resolvedTable, (int) $storeId, $type, $chunk);
            }
        }
    }

    public function execute($ids): void
    {
        if ($ids === []) {
            return;
        }
        $resolvedTable = $this->getTargetTable();
        if ($resolvedTable === null) {
            return;
        }
        $connection = $this->resource->getConnection();
        $memberTable = $this->resource->getTableName('panth_seo_hreflang_member');

        $intIds = array_map('intval', $ids);
        $memberIn = $connection->quoteInto('member_id IN (?)', $intIds);
        $groupIn  = $connection->quoteInto('group_id IN (?)', $intIds);
        $select = $connection->select()
            ->from($memberTable, ['store_id', 'entity_type', 'entity_id'])
            ->where("{$memberIn} OR {$groupIn}")
            ->distinct(true);

        foreach ($this->bucketRows($connection->fetchAll($select)) as $key => $entityIds) {
            [$storeId, $type] = explode(':', $key, 2);
            $this->updateBatch($resolvedTable, (int) $storeId, $type, $entityIds);
        }
    }

    public function executeList(array $ids): void
    {
        $this->execute($ids);
    }

    public function executeRow($id): void
    {
        $this->execute([(int) $id]);
    }

    public function getTargetTable(): ?string
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TARGET_TABLE);
        if (!$connection->isTableExists($table)) {
            return null;
        }
        if (!$connection->tableColumnExists($table, self::TARGET_COLUMN)) {
            return null;
        }
        return $table;
    }

    private function bucketRows(array $rows): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            $key = $row['store_id'] . ':' . $row['entity_type'];
            $buckets[$key][] = (int) $row['entity_id'];
        }
        return $buckets;
    }

    private function updateBatch(string $resolvedTable, int $storeId, string $entityType, array $entityIds): void
    {
        if ($entityIds === []) {
            return;
        }
        $connection = $this->resource->getConnection();

        foreach ($entityIds as $entityId) {
            try {
                $alternates = $this->hreflangResolver->getAlternates($entityType, $entityId, $storeId);
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf(
                        '[panth_hreflang] getAlternates failed store=%d type=%s id=%d: %s',
                        $storeId,
                        $entityType,
                        $entityId,
                        $e->getMessage()
                    )
                );
                continue;
            }

            $payload = $alternates === [] ? null : $this->json->serialize($alternates);

            $connection->update(
                $resolvedTable,
                [self::TARGET_COLUMN => $payload],
                [
                    'store_id = ?'    => $storeId,
                    'entity_type = ?' => $entityType,
                    'entity_id = ?'   => $entityId,
                ]
            );
        }
    }
}
