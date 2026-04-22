<?php
declare(strict_types=1);

namespace Panth\Hreflang\Controller\Adminhtml\Hreflang;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Panth\Hreflang\Controller\Adminhtml\AbstractAction;

/**
 * JSON endpoint that backs the admin "Find entity" modal on the hreflang
 * edit form. Given a type (product/category/cms_page) and a search term,
 * returns up to 50 matches with their store-scoped IDs so the admin can
 * drop them into a member row without leaving the page.
 */
class EntitySearch extends AbstractAction implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_Hreflang::hreflang';

    private const PAGE_SIZE = 50;

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $type = (string) $this->getRequest()->getParam('type', 'product');
        $q = trim((string) $this->getRequest()->getParam('q', ''));
        $storeId = (int) $this->getRequest()->getParam('store_id', 0);

        $result = match ($type) {
            'category' => $this->searchCategories($q, $storeId),
            'cms_page' => $this->searchCmsPages($q, $storeId),
            default    => $this->searchProducts($q, $storeId),
        };

        return $this->jsonFactory->create()->setData(['items' => $result]);
    }

    /**
     * @return array<int, array{id:int,label:string,url:string}>
     */
    private function searchProducts(string $q, int $storeId): array
    {
        $connection = $this->resource->getConnection();
        $eav = $this->resource->getTableName('catalog_product_entity');
        $varchar = $this->resource->getTableName('catalog_product_entity_varchar');
        $attrId = (int) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('eav_attribute'), 'attribute_id')
                ->where('attribute_code = ?', 'name')
                ->where('entity_type_id = (SELECT entity_type_id FROM ' . $this->resource->getTableName('eav_entity_type') . ' WHERE entity_type_code = \'catalog_product\')')
        );

        $select = $connection->select()
            ->from(['p' => $eav], ['entity_id', 'sku'])
            ->joinLeft(
                ['n' => $varchar],
                "n.entity_id = p.entity_id AND n.attribute_id = {$attrId} AND n.store_id IN (0, {$storeId})",
                ['name' => 'n.value']
            )
            ->group('p.entity_id')
            ->order('p.entity_id ASC')
            ->limit(self::PAGE_SIZE);

        if ($q !== '') {
            $like = '%' . $q . '%';
            if (ctype_digit($q)) {
                $select->where('p.entity_id = ? OR p.sku LIKE ? OR n.value LIKE ?', $q, $like, $like);
            } else {
                $select->where('p.sku LIKE ? OR n.value LIKE ?', $like, $like);
            }
        }

        $rows = $connection->fetchAll($select);
        return array_map(static fn(array $r) => [
            'id'    => (int) $r['entity_id'],
            'label' => sprintf('%s — %s', $r['sku'] ?: '(no sku)', $r['name'] ?: '(unnamed)'),
            'url'   => '',
        ], $rows);
    }

    /**
     * @return array<int, array{id:int,label:string,url:string}>
     */
    private function searchCategories(string $q, int $storeId): array
    {
        $connection = $this->resource->getConnection();
        $cat = $this->resource->getTableName('catalog_category_entity');
        $varchar = $this->resource->getTableName('catalog_category_entity_varchar');
        $attrId = (int) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('eav_attribute'), 'attribute_id')
                ->where('attribute_code = ?', 'name')
                ->where('entity_type_id = (SELECT entity_type_id FROM ' . $this->resource->getTableName('eav_entity_type') . ' WHERE entity_type_code = \'catalog_category\')')
        );

        $select = $connection->select()
            ->from(['c' => $cat], ['entity_id', 'path'])
            ->joinLeft(
                ['n' => $varchar],
                "n.entity_id = c.entity_id AND n.attribute_id = {$attrId} AND n.store_id IN (0, {$storeId})",
                ['name' => 'n.value']
            )
            ->where('c.level > ?', 1)
            ->group('c.entity_id')
            ->order('c.path ASC')
            ->limit(self::PAGE_SIZE);

        if ($q !== '') {
            $like = '%' . $q . '%';
            if (ctype_digit($q)) {
                $select->where('c.entity_id = ? OR n.value LIKE ?', $q, $like);
            } else {
                $select->where('n.value LIKE ?', $like);
            }
        }

        $rows = $connection->fetchAll($select);
        return array_map(static fn(array $r) => [
            'id'    => (int) $r['entity_id'],
            'label' => sprintf('[#%d] %s', $r['entity_id'], $r['name'] ?: '(unnamed)'),
            'url'   => '',
        ], $rows);
    }

    /**
     * @return array<int, array{id:int,label:string,url:string}>
     */
    private function searchCmsPages(string $q, int $storeId): array
    {
        $connection = $this->resource->getConnection();
        $page = $this->resource->getTableName('cms_page');
        $pageStore = $this->resource->getTableName('cms_page_store');

        $select = $connection->select()
            ->from(['p' => $page], ['page_id', 'identifier', 'title', 'is_active']);

        if ($storeId > 0) {
            $select->join(
                ['ps' => $pageStore],
                'ps.page_id = p.page_id AND ps.store_id IN (0, ' . $storeId . ')',
                []
            )->group('p.page_id');
        }

        $select->where('p.is_active = ?', 1)
            ->order('p.page_id ASC')
            ->limit(self::PAGE_SIZE);

        if ($q !== '') {
            $like = '%' . $q . '%';
            if (ctype_digit($q)) {
                $select->where('p.page_id = ? OR p.identifier LIKE ? OR p.title LIKE ?', $q, $like, $like);
            } else {
                $select->where('p.identifier LIKE ? OR p.title LIKE ?', $like, $like);
            }
        }

        $rows = $connection->fetchAll($select);
        return array_map(static fn(array $r) => [
            'id'    => (int) $r['page_id'],
            'label' => sprintf('[#%d] %s (%s)', $r['page_id'], $r['title'] ?: '(untitled)', $r['identifier'] ?: ''),
            'url'   => '',
        ], $rows);
    }
}
