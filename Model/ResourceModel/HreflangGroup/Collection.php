<?php
declare(strict_types=1);

namespace Panth\Hreflang\Model\ResourceModel\HreflangGroup;

use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\Hreflang\Model\Hreflang\Group as GroupModel;
use Panth\Hreflang\Model\ResourceModel\HreflangGroup as GroupResource;

/**
 * Standard (non-grid) collection for hreflang groups.
 *
 * Used by the admin form data provider to load a single row for edit.
 */
class Collection extends AbstractCollection implements SearchResultInterface
{
    /** @var string */
    protected $_idFieldName = 'group_id';

    /**
     * @var \Magento\Framework\Api\Search\AggregationInterface
     */
    private $aggregations;

    protected function _construct(): void
    {
        $this->_init(GroupModel::class, GroupResource::class);
    }

    /**
     * @inheritdoc
     */
    public function getAggregations()
    {
        return $this->aggregations;
    }

    /**
     * @inheritdoc
     */
    public function setAggregations($aggregations)
    {
        $this->aggregations = $aggregations;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getSearchCriteria()
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function setSearchCriteria(?SearchCriteriaInterface $searchCriteria = null)
    {
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function getTotalCount()
    {
        return $this->getSize();
    }

    /**
     * @inheritdoc
     */
    public function setTotalCount($totalCount)
    {
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setItems(?array $items = null)
    {
        return $this;
    }
}
