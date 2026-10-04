<?php
declare(strict_types=1);

namespace Panth\Hreflang\Model\Hreflang;

use Magento\Framework\Model\AbstractModel;
use Panth\Hreflang\Model\ResourceModel\HreflangGroup as GroupResource;

class Group extends AbstractModel
{
    protected $_idFieldName = 'group_id';

    protected function _construct(): void
    {
        $this->_init(GroupResource::class);
    }
}
