<?php
declare(strict_types=1);

namespace Panth\Hreflang\Controller\Adminhtml\Hreflang;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\Hreflang\Controller\Adminhtml\AbstractAction;

/**
 * Persists the submitted hreflang group row (insert or update).
 */
class Save extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_Hreflang::hreflang';

    /** Entity types accepted for a hreflang group. */
    private const ALLOWED_ENTITY_TYPES = ['product', 'category', 'cms_page'];

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritdoc
     */
    public function execute(): Redirect
    {
        $data = (array) $this->getRequest()->getPostValue();
        $resultRedirect = $this->resultRedirectFactory->create();
        if ($data === []) {
            return $resultRedirect->setPath('*/*/');
        }
        $id = (int) ($data['group_id'] ?? 0);
        $entityType = (string) ($data['entity_type'] ?? 'product');
        if (!in_array($entityType, self::ALLOWED_ENTITY_TYPES, true)) {
            $this->messageManager->addErrorMessage(__('Invalid entity type.'));
            return $resultRedirect->setPath('*/*/');
        }
        $row = [
            'code' => mb_substr((string) ($data['code'] ?? ''), 0, 64),
            'entity_type' => $entityType,
            'notes' => (string) ($data['notes'] ?? ''),
            'is_active' => (int) ($data['is_active'] ?? 1),
            'updated_at' => $this->dateTime->gmtDate(),
        ];

        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_hreflang_group');
            if ($id > 0) {
                $connection->update($table, $row, ['group_id = ?' => $id]);
            } else {
                $row['created_at'] = $this->dateTime->gmtDate();
                $connection->insert($table, $row);
                $id = (int) $connection->lastInsertId($table);
            }
            $this->messageManager->addSuccessMessage(__('Hreflang group saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $resultRedirect->setPath('*/*/');
    }
}
