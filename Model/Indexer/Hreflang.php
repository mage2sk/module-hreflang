<?php
declare(strict_types=1);

namespace Panth\Hreflang\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\ActionInterface as IndexerActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\Hreflang\Api\HreflangResolverInterface;
use Psr\Log\LoggerInterface;

/**
 * Indexer for panth_seo_hreflang: rebuilds the `hreflang_payload` JSON column
 * on `panth_seo_resolved` for every member referenced by a hreflang group.
 *
 * The `panth_seo_resolved` table is not owned by this module (it lives in the
 * sibling Panth Advanced SEO module). We therefore skip the write step if the
 * table is absent; the Resolver still serves live alternates from the member
 * tables in that case, so disabling the sibling module does not break output.
 */
class Hreflang implements IndexerActionInterface, MviewActionInterface
{
    public const INDEXER_ID = 'panth_seo_hreflang';

    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly HreflangResolverInterface $hreflangResolver,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Full reindex entry point — walks every member row.
     */
    public function executeFull(): void
    {
        $connection = $this->resource->getConnection();
        $memberTable = $this->resource->getTableName('panth_seo_hreflang_member');

        $select = $connection->select()
            ->from($memberTable, ['store_id', 'entity_type', 'entity_id'])
            ->distinct(true);

        $rows = $connection->fetchAll($select);
        $buckets = [];
        foreach ($rows as $row) {
            $key = $row['store_id'] . ':' . $row['entity_type'];
            $buckets[$key][] = (int) $row['entity_id'];
        }

        foreach ($buckets as $key => $ids) {
            [$storeId, $type] = explode(':', $key, 2);
            foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
                $this->updateBatch((int) $storeId, $type, $chunk);
            }
        }
    }

    /**
     * Incremental reindex by member/group IDs.
     *
     * @param int[] $ids
     */
    public function execute($ids): void
    {
        if ($ids === []) {
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

        $rows = $connection->fetchAll($select);
        $buckets = [];
        foreach ($rows as $row) {
            $key = $row['store_id'] . ':' . $row['entity_type'];
            $buckets[$key][] = (int) $row['entity_id'];
        }
        foreach ($buckets as $key => $entityIds) {
            [$storeId, $type] = explode(':', $key, 2);
            $this->updateBatch((int) $storeId, $type, $entityIds);
        }
    }

    /**
     * @param int[] $ids
     */
    public function executeList(array $ids): void
    {
        $this->execute($ids);
    }

    /**
     * @param int $id
     */
    public function executeRow($id): void
    {
        $this->execute([(int) $id]);
    }

    /**
     * Recompute alternates for a batch of entity IDs and persist to resolved table.
     *
     * @param int[] $entityIds
     */
    private function updateBatch(int $storeId, string $entityType, array $entityIds): void
    {
        if ($entityIds === []) {
            return;
        }
        $connection = $this->resource->getConnection();
        $resolvedTable = $this->resource->getTableName('panth_seo_resolved');

        // panth_seo_resolved is owned by the sibling Advanced SEO module. If it
        // is not installed we compute alternates for completeness (in case a
        // listener cares) but skip the write.
        $hasResolvedTable = $connection->isTableExists($resolvedTable);

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

            if (!$hasResolvedTable) {
                continue;
            }

            $payload = $alternates === [] ? null : $this->json->serialize($alternates);

            $connection->update(
                $resolvedTable,
                ['hreflang_payload' => $payload],
                [
                    'store_id = ?'    => $storeId,
                    'entity_type = ?' => $entityType,
                    'entity_id = ?'   => $entityId,
                ]
            );
        }
    }
}
