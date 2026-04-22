<?php
declare(strict_types=1);

namespace Panth\Hreflang\Ui\Component\Listing\DataProvider;

use Magento\Framework\Api\Filter;
use Magento\Framework\App\ResourceConnection;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Panth\Hreflang\Model\ResourceModel\HreflangGroup\CollectionFactory;

/**
 * Data provider for the hreflang groups admin listing grid.
 *
 * Joins the member table to surface a calculated `member_count` column and
 * ignores filter requests against that virtual column.
 */
class HreflangDataProvider extends AbstractDataProvider
{
    private bool $memberCountJoined = false;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly ResourceConnection $resource,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * @inheritdoc
     */
    public function getData(): array
    {
        $this->joinMemberCount();

        if (!$this->getCollection()->isLoaded()) {
            $this->getCollection()->load();
        }

        $items = [];
        foreach ($this->getCollection() as $item) {
            $items[] = $item->getData();
        }

        return [
            'totalRecords' => $this->getCollection()->getSize(),
            'items' => $items,
        ];
    }

    /**
     * @inheritdoc
     */
    public function addFilter(Filter $filter): void
    {
        $field = $filter->getField();

        if ($field === 'member_count') {
            // member_count is a calculated field, cannot be filtered at DB level easily
            return;
        }

        parent::addFilter($filter);
    }

    /**
     * Join the member table once so the grid can display a member_count column.
     */
    private function joinMemberCount(): void
    {
        if ($this->memberCountJoined) {
            return;
        }
        $this->memberCountJoined = true;

        $memberTable = $this->resource->getTableName('panth_seo_hreflang_member');
        $this->getCollection()->getSelect()->joinLeft(
            ['m' => $memberTable],
            'main_table.group_id = m.group_id',
            ['member_count' => new \Zend_Db_Expr('COUNT(m.member_id)')]
        )->group('main_table.group_id');
    }
}
