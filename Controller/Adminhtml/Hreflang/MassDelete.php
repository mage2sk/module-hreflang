<?php
declare(strict_types=1);

namespace Panth\Hreflang\Controller\Adminhtml\Hreflang;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Panth\Hreflang\Controller\Adminhtml\AbstractAction;

/**
 * Mass-delete handler for the hreflang group listing.
 *
 * Accepts the `selected` / `excluded` / `namespace` params posted by the
 * Magento UI listing grid massaction component.
 */
class MassDelete extends AbstractAction implements HttpPostActionInterface
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
        $resultRedirect = $this->resultRedirectFactory->create();
        $selected = (array) $this->getRequest()->getParam('selected', []);
        $excluded = $this->getRequest()->getParam('excluded');

        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_hreflang_group');

            if ($excluded === 'false') {
                // "Select all" with no excluded rows — wipe everything.
                $deleted = $connection->delete($table);
            } else {
                $ids = [];
                if (is_array($excluded) && $excluded !== []) {
                    // "Select all" excluding specific IDs.
                    $ids = array_map('intval', $connection->fetchCol(
                        $connection->select()
                            ->from($table, ['group_id'])
                            ->where('group_id NOT IN (?)', array_map('intval', $excluded))
                    ));
                } else {
                    $ids = array_map('intval', $selected);
                }
                if ($ids === []) {
                    $this->messageManager->addErrorMessage(__('Please select at least one group.'));
                    return $resultRedirect->setPath('*/*/');
                }
                $deleted = $connection->delete($table, ['group_id IN (?)' => $ids]);
            }

            $this->messageManager->addSuccessMessage(
                __('A total of %1 record(s) have been deleted.', (int) $deleted)
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $resultRedirect->setPath('*/*/');
    }
}
