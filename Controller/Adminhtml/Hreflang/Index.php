<?php
declare(strict_types=1);

namespace Panth\Hreflang\Controller\Adminhtml\Hreflang;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Panth\Hreflang\Controller\Adminhtml\AbstractAction;

/**
 * Renders the hreflang group listing page.
 */
class Index extends AbstractAction implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_Hreflang::hreflang';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritdoc
     */
    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_Hreflang::hreflang');
        $page->getConfig()->getTitle()->prepend(__('Hreflang Mapping'));
        return $page;
    }
}
