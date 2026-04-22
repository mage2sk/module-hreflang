<?php
declare(strict_types=1);

namespace Panth\Hreflang\Controller\Adminhtml\Hreflang;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Panth\Hreflang\Controller\Adminhtml\AbstractAction;

/**
 * Deletes a single hreflang group (and its members, via ON DELETE CASCADE).
 */
class Delete extends AbstractAction implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_Hreflang::hreflang';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritdoc
     */
    public function execute(): Redirect
    {
        $id = (int) $this->getRequest()->getParam('id');
        $resultRedirect = $this->resultRedirectFactory->create();
        if ($id > 0) {
            try {
                $this->resource->getConnection()->delete(
                    $this->resource->getTableName('panth_seo_hreflang_group'),
                    ['group_id = ?' => $id]
                );
                $this->messageManager->addSuccessMessage(__('Hreflang group deleted.'));
            } catch (\Throwable $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            }
        }
        return $resultRedirect->setPath('*/*/');
    }
}
