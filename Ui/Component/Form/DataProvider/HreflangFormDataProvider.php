<?php
declare(strict_types=1);

namespace Panth\Hreflang\Ui\Component\Form\DataProvider;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Form DataProvider for Hreflang Groups that ALSO loads the group's members
 * into the `hreflang_members` key so the admin form's dynamicRows component
 * has data to render. Without this the members editor would always appear
 * empty, even for existing groups.
 */
class HreflangFormDataProvider extends GenericFormDataProvider
{
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        AbstractCollection $collection,
        private readonly ResourceConnection $resource,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $collection, $meta, $data);
    }

    /**
     * @inheritdoc
     */
    public function getData(): array
    {
        $data = parent::getData();

        foreach ($data as $groupId => &$row) {
            if ($groupId === '' || (int) $groupId === 0) {
                $row['hreflang_members'] = [];
                continue;
            }
            $row['hreflang_members'] = $this->loadMembers((int) $groupId);
        }

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadMembers(int $groupId): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_hreflang_member');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table)
                ->where('group_id = ?', $groupId)
                ->order('member_id ASC')
        );

        // dynamicRows + KO checkbox valueMap does strict string comparison
        // on initial hydration, so pass scalars as strings — `1`/`0` not
        // integers — otherwise the Is Default toggle always renders as No.
        foreach ($rows as &$row) {
            $row['member_id'] = (string) (int) $row['member_id'];
            $row['group_id']  = (string) (int) $row['group_id'];
            $row['store_id']  = (string) (int) $row['store_id'];
            $row['entity_id'] = (string) (int) $row['entity_id'];
            $row['is_default'] = (int) $row['is_default'] === 1 ? '1' : '0';
        }

        return $rows;
    }
}
