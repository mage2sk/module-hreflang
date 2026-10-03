<?php
declare(strict_types=1);

namespace Panth\Hreflang\Controller\Adminhtml\Hreflang;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Ui\Component\MassAction\Filter;
use Panth\Hreflang\Controller\Adminhtml\AbstractAction;
use Panth\Hreflang\Model\ResourceModel\HreflangGroup\CollectionFactory;

class MassDelete extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_Hreflang::hreflang';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $ids = array_map('intval', $collection->getAllIds());
            if ($ids === []) {
                $this->messageManager->addErrorMessage(__('Please select at least one group.'));
                return $resultRedirect->setPath('*/*/');
            }

            $deleted = $this->resource->getConnection()->delete(
                $this->resource->getTableName('panth_seo_hreflang_group'),
                ['group_id IN (?)' => $ids]
            );

            $this->messageManager->addSuccessMessage(
                __('A total of %1 record(s) have been deleted.', (int) $deleted)
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $resultRedirect->setPath('*/*/');
    }
}
