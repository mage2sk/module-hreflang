<?php
declare(strict_types=1);

namespace Panth\Hreflang\Controller\Adminhtml\Hreflang;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Panth\Hreflang\Controller\Adminhtml\AbstractAction;

/**
 * Persists the submitted hreflang group + its members (insert / update / delete).
 */
class Save extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_Hreflang::hreflang';

    /** Entity types accepted for a hreflang group. */
    private const ALLOWED_ENTITY_TYPES = ['product', 'category', 'cms_page'];

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlFinderInterface $urlFinder
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
            $groupTable = $this->resource->getTableName('panth_seo_hreflang_group');

            if ($id > 0) {
                $connection->update($groupTable, $row, ['group_id = ?' => $id]);
            } else {
                $row['created_at'] = $this->dateTime->gmtDate();
                $connection->insert($groupTable, $row);
                $id = (int) $connection->lastInsertId($groupTable);
            }

            $members = $data['hreflang_members'] ?? [];
            if (is_array($members)) {
                $this->syncMembers($id, $entityType, $members);
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

    /**
     * Insert / update / delete member rows to match the submitted form state.
     */
    private function syncMembers(int $groupId, string $entityType, array $submitted): void
    {
        $connection = $this->resource->getConnection();
        $memberTable = $this->resource->getTableName('panth_seo_hreflang_member');

        $existingIds = array_map(
            'intval',
            $connection->fetchCol(
                $connection->select()->from($memberTable, 'member_id')->where('group_id = ?', $groupId)
            )
        );
        $seenIds = [];

        foreach ($submitted as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((int) ($row['is_removed'] ?? 0) === 1) {
                continue;
            }

            $storeId  = (int) ($row['store_id'] ?? 0);
            $entityId = (int) ($row['entity_id'] ?? 0);
            if ($storeId <= 0 || $entityId <= 0) {
                continue;
            }

            $locale = trim((string) ($row['locale'] ?? ''));
            if ($locale === '') {
                $locale = $this->resolveStoreLocale($storeId);
            }
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '') {
                $url = $this->resolveEntityUrl($entityType, $entityId, $storeId);
            }
            if ($locale === '' || $url === '') {
                continue;
            }

            $payload = [
                'group_id'    => $groupId,
                'store_id'    => $storeId,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'locale'      => $locale,
                'url'         => mb_substr($url, 0, 512),
                'is_default'  => (int) ($row['is_default'] ?? 0) === 1 ? 1 : 0,
            ];

            $memberId = (int) ($row['member_id'] ?? 0);
            if ($memberId > 0 && in_array($memberId, $existingIds, true)) {
                $connection->update($memberTable, $payload, ['member_id = ?' => $memberId]);
                $seenIds[] = $memberId;
            } else {
                $connection->insert($memberTable, $payload);
            }
        }

        $toDelete = array_diff($existingIds, $seenIds);
        if ($toDelete !== []) {
            $connection->delete($memberTable, ['member_id IN (?)' => $toDelete]);
        }
    }

    private function resolveStoreLocale(int $storeId): string
    {
        try {
            $locale = (string) $this->storeManager->getStore($storeId)->getConfig('general/locale/code');
            return $locale !== '' ? str_replace('_', '-', $locale) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolveEntityUrl(string $entityType, int $entityId, int $storeId): string
    {
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (\Throwable) {
            return '';
        }

        $rewrite = $this->urlFinder->findOneByData([
            UrlRewrite::ENTITY_ID   => $entityId,
            UrlRewrite::ENTITY_TYPE => $entityType === 'cms_page' ? 'cms-page' : $entityType,
            UrlRewrite::STORE_ID    => $storeId,
        ]);
        if ($rewrite === null) {
            return '';
        }

        return rtrim((string) $store->getBaseUrl(), '/') . '/' . ltrim($rewrite->getRequestPath(), '/');
    }
}
