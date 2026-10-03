<?php
declare(strict_types=1);

namespace Panth\Hreflang\Block\Adminhtml\Hreflang\Edit;

use Magento\Backend\Block\Template;

class EntityFinder extends Template
{
    protected $_template = 'Panth_Hreflang::hreflang/entity-finder.phtml';

    public function getSearchUrl(): string
    {
        return $this->getUrl('panth_hreflang/hreflang/entitysearch');
    }
}
