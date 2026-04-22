<?php
declare(strict_types=1);

namespace Panth\Hreflang\Model\ResourceModel\HreflangGroup\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Real (non-virtual) grid collection for the Hreflang groups admin listing.
 *
 * mainTable + resourceModel bindings are supplied via etc/di.xml.
 */
class Collection extends SearchResult
{
    /** @var string */
    protected $_idFieldName = 'group_id';

    protected function _initSelect(): static
    {
        parent::_initSelect();

        // Attach a member-count aggregate so the grid's Members column shows
        // a number instead of a blank cell. Left-joined so groups with zero
        // members still appear in the listing.
        $memberTable = $this->getTable('panth_seo_hreflang_member');
        $this->getSelect()->joinLeft(
            ['panth_seo_hreflang_member' => $memberTable],
            'panth_seo_hreflang_member.group_id = main_table.group_id',
            ['member_count' => 'COUNT(panth_seo_hreflang_member.member_id)']
        )->group('main_table.group_id');

        return $this;
    }

    /**
     * Map alias so the grid's filter/sort on `member_count` works against
     * the aggregate expression rather than looking for a missing column.
     */
    public function addFieldToFilter($field, $condition = null)
    {
        if ($field === 'member_count') {
            $this->getSelect()->having(
                $this->_getConditionSql('COUNT(panth_seo_hreflang_member.member_id)', $condition)
            );
            return $this;
        }
        return parent::addFieldToFilter($field, $condition);
    }
}
