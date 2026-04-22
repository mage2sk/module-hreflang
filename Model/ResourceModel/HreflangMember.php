<?php
declare(strict_types=1);

namespace Panth\Hreflang\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for `panth_seo_hreflang_member`.
 */
class HreflangMember extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('panth_seo_hreflang_member', 'member_id');
    }
}
