<?php
declare(strict_types=1);

namespace Panth\Hreflang\Model\ResourceModel\HreflangGroup\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class Collection extends SearchResult
{
    protected $_idFieldName = 'group_id';

    protected function _initSelect(): static
    {
        parent::_initSelect();

        $memberTable = $this->getTable('panth_seo_hreflang_member');
        $this->getSelect()->joinLeft(
            ['panth_seo_hreflang_member' => $memberTable],
            'panth_seo_hreflang_member.group_id = main_table.group_id',
            ['member_count' => 'COUNT(panth_seo_hreflang_member.member_id)']
        )->group('main_table.group_id');

        return $this;
    }

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
