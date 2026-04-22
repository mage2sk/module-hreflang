<?php
declare(strict_types=1);

namespace Panth\Hreflang\Model\Hreflang;

use Magento\Framework\Model\AbstractModel;
use Panth\Hreflang\Model\ResourceModel\HreflangGroup as GroupResource;

/**
 * Active Record model for a hreflang group.
 */
class Group extends AbstractModel
{
    /** @var string */
    protected $_idFieldName = 'group_id';

    protected function _construct(): void
    {
        $this->_init(GroupResource::class);
    }
}
