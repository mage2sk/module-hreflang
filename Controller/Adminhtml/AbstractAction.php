<?php
declare(strict_types=1);

namespace Panth\Hreflang\Controller\Adminhtml;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;

/**
 * Shared base for Panth Hreflang admin controllers.
 */
abstract class AbstractAction extends Action
{
    public const ADMIN_RESOURCE = 'Panth_Hreflang::hreflang';

    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    /**
     * @inheritdoc
     */
    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(static::ADMIN_RESOURCE);
    }
}
