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
        return $this;
    }
}
