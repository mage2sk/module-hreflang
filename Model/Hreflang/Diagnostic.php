<?php
declare(strict_types=1);

namespace Panth\Hreflang\Model\Hreflang;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Hreflang\Helper\Config;

class Diagnostic
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    public function runDiagnostics(int $storeId): array
    {
        $issues = [];

        $issues = array_merge($issues, $this->checkStoresWithoutLocale($storeId));
        $issues = array_merge($issues, $this->checkMissingXDefault());
        $issues = array_merge($issues, $this->checkOrphanGroups());
        $issues = array_merge($issues, $this->checkConflictingLocales());
        $issues = array_merge($issues, $this->checkStoresWithoutHreflangConfig());

        return $issues;
    }

    private function checkStoresWithoutLocale(int $storeId): array
    {
        $issues = [];

        try {
            $stores = $this->storeManager->getStores(false);
        } catch (\Throwable) {
            return [];
        }

        foreach ($stores as $store) {
            $sid = (int) $store->getId();
            $locale = (string) $this->config->getValue('general/locale/code', $sid);
            if ($locale === '') {
                $issues[] = [
                    'type'     => 'locale',
                    'severity' => 'error',
                    'message'  => sprintf(
                        'Store "%s" (ID %d) has no locale configured. Hreflang tags cannot be generated.',
                        $store->getName(),
                        $sid
                    ),
                ];
            }
        }

        return $issues;
    }

    private function checkMissingXDefault(): array
    {
        $issues = [];
        $connection = $this->resource->getConnection();
        $groupTable = $this->resource->getTableName('panth_seo_hreflang_group');
        $memberTable = $this->resource->getTableName('panth_seo_hreflang_member');

        if (!$connection->isTableExists($groupTable) || !$connection->isTableExists($memberTable)) {
            return [];
        }

        $select = $connection->select()
            ->from(['g' => $groupTable], ['group_id', 'code'])
            ->joinLeft(
                ['m' => $memberTable],
                'g.group_id = m.group_id AND m.is_default = 1',
                []
            )
            ->where('g.is_active = ?', 1)
            ->where('m.member_id IS NULL')
            ->group('g.group_id');

        $rows = $connection->fetchAll($select);
        foreach ($rows as $row) {
            $issues[] = [
                'type'     => 'x_default',
                'severity' => 'warning',
                'message'  => sprintf(
                    'Group "%s" (ID %d) has no x-default member. Search engines may choose an arbitrary default.',
                    (string) $row['code'],
                    (int) $row['group_id']
                ),
            ];
        }

        return $issues;
    }

    private function checkOrphanGroups(): array
    {
        $issues = [];
        $connection = $this->resource->getConnection();
        $groupTable = $this->resource->getTableName('panth_seo_hreflang_group');
        $memberTable = $this->resource->getTableName('panth_seo_hreflang_member');

        if (!$connection->isTableExists($groupTable) || !$connection->isTableExists($memberTable)) {
            return [];
        }

        $select = $connection->select()
            ->from(['g' => $groupTable], ['group_id', 'code'])
            ->joinLeft(
                ['m' => $memberTable],
                'g.group_id = m.group_id',
                ['member_count' => new \Zend_Db_Expr('COUNT(m.member_id)')]
            )
            ->where('g.is_active = ?', 1)
            ->group('g.group_id')
            ->having('member_count < 2');

        $rows = $connection->fetchAll($select);
        foreach ($rows as $row) {
            $count = (int) $row['member_count'];
            $issues[] = [
                'type'     => 'orphan_group',
                'severity' => 'warning',
                'message'  => sprintf(
                    'Group "%s" (ID %d) has only %d member(s). At least 2 are required for hreflang to be meaningful.',
                    (string) $row['code'],
                    (int) $row['group_id'],
                    $count
                ),
            ];
        }

        return $issues;
    }

    private function checkConflictingLocales(): array
    {
        $issues = [];
        $connection = $this->resource->getConnection();
        $groupTable = $this->resource->getTableName('panth_seo_hreflang_group');
        $memberTable = $this->resource->getTableName('panth_seo_hreflang_member');

        if (!$connection->isTableExists($groupTable) || !$connection->isTableExists($memberTable)) {
            return [];
        }

        $select = $connection->select()
            ->from(['m' => $memberTable], [
                'group_id',
                'locale',
                'dup_count' => new \Zend_Db_Expr('COUNT(m.member_id)'),
            ])
            ->join(
                ['g' => $groupTable],
                'g.group_id = m.group_id',
                ['code']
            )
            ->where('g.is_active = ?', 1)
            ->group(['m.group_id', 'm.locale'])
            ->having('dup_count > 1');

        $rows = $connection->fetchAll($select);
        foreach ($rows as $row) {
            $issues[] = [
                'type'     => 'conflict',
                'severity' => 'error',
                'message'  => sprintf(
                    'Group "%s" (ID %d) has %d members with locale "%s". Each locale must be unique within a group.',
                    (string) $row['code'],
                    (int) $row['group_id'],
                    (int) $row['dup_count'],
                    (string) $row['locale']
                ),
            ];
        }

        return $issues;
    }

    private function checkStoresWithoutHreflangConfig(): array
    {
        $issues = [];
        $connection = $this->resource->getConnection();
        $memberTable = $this->resource->getTableName('panth_seo_hreflang_member');
        $groupTable = $this->resource->getTableName('panth_seo_hreflang_group');

        if (!$connection->isTableExists($memberTable) || !$connection->isTableExists($groupTable)) {
            return [];
        }

        $select = $connection->select()
            ->from(['m' => $memberTable], ['store_id'])
            ->join(
                ['g' => $groupTable],
                'g.group_id = m.group_id',
                []
            )
            ->where('g.is_active = ?', 1)
            ->where('m.store_id != 0')
            ->distinct();

        $storeIds = array_map('intval', $connection->fetchCol($select));

        foreach ($storeIds as $sid) {
            if (!$this->config->isHreflangEnabled($sid)) {
                $storeName = $this->getStoreName($sid);
                $issues[] = [
                    'type'     => 'config',
                    'severity' => 'warning',
                    'message'  => sprintf(
                        'Store "%s" (ID %d) is a member of active hreflang groups but has hreflang disabled in config.',
                        $storeName,
                        $sid
                    ),
                ];
            }
        }

        return $issues;
    }

    private function getStoreName(int $storeId): string
    {
        try {
            return (string) $this->storeManager->getStore($storeId)->getName();
        } catch (\Throwable) {
            return 'Unknown';
        }
    }
}
