<?php
declare(strict_types=1);

namespace Panth\Hreflang\Controller\Adminhtml\Hreflang;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Panth\Hreflang\Controller\Adminhtml\AbstractAction;

/**
 * Renders the hreflang group edit form, loading the row by `id` when provided.
 */
class Edit extends AbstractAction implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_Hreflang::hreflang';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly Registry $registry,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritdoc
     */
    public function execute(): Page
    {
        $id = (int) $this->getRequest()->getParam('id');
        $row = [];
        if ($id > 0) {
            $connection = $this->resource->getConnection();
            $row = $connection->fetchRow(
                $connection->select()
                    ->from($this->resource->getTableName('panth_seo_hreflang_group'))
                    ->where('group_id = ?', $id)
            ) ?: [];
        }
        $this->registry->register('panth_hreflang_group', $row, true);

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Panth_Hreflang::hreflang');
        $page->getConfig()->getTitle()->prepend($id ? __('Edit Hreflang Group') : __('New Hreflang Group'));
        return $page;
    }
}
