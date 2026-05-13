<?php
declare(strict_types=1);

namespace Panth\Hreflang\ViewModel;

use Magento\Cms\Helper\Page as CmsPageHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Hreflang\Api\HreflangResolverInterface;
use Panth\Hreflang\Helper\Config;

/**
 * ViewModel powering the storefront hreflang block.
 *
 * Detects the current entity (product/category/cms) and delegates alternates
 * resolution to {@see HreflangResolverInterface}.
 *
 * CMS detection on the storefront deliberately does NOT rely on the `cms_page`
 * registry key — Magento only populates that key in the admin edit controller.
 * Instead we match on the controller's full action name and resolve the page
 * id either from the request (cms_page_view) or from store config (home).
 */
class Hreflang implements ArgumentInterface
{
    public function __construct(
        private readonly HreflangResolverInterface $resolver,
        private readonly Registry $registry,
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ResourceConnection $resource
    ) {
    }

    public function isEnabled(): bool
    {
        try {
            return $this->config->isEnabled() && $this->config->isHreflangEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int,array{locale:string,url:string,is_default:bool}>
     */
    public function getAlternates(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }
        try {
            [$type, $id] = $this->detectEntity();
            $storeId = (int) $this->storeManager->getStore()->getId();
            $alternates = [];
            if ($type !== null) {
                $alternates = $this->resolver->getAlternates($type, $id, $storeId);
            }

            if ($alternates === []) {
                $currentUrl = $this->storeManager->getStore()->getCurrentUrl(false);
                $cleanUrl = strtok((string) $currentUrl, '?');
                if ($cleanUrl !== false && $cleanUrl !== '') {
                    $alternates[] = [
                        'locale' => 'x-default',
                        'url' => $cleanUrl,
                        'is_default' => true,
                    ];
                }
            }

            return $alternates;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{0:?string,1:int}
     */
    private function detectEntity(): array
    {
        $product = $this->registry->registry('current_product');
        if ($product !== null && $product->getId()) {
            return [HreflangResolverInterface::ENTITY_PRODUCT, (int) $product->getId()];
        }
        $category = $this->registry->registry('current_category');
        if ($category !== null && $category->getId()) {
            return [HreflangResolverInterface::ENTITY_CATEGORY, (int) $category->getId()];
        }

        // CMS page detection — registry fallback first (admin / custom
        // integrations), then action-name based lookup for the storefront.
        $cmsPage = $this->registry->registry('cms_page');
        if ($cmsPage !== null && $cmsPage->getId()) {
            return [HreflangResolverInterface::ENTITY_CMS, (int) $cmsPage->getId()];
        }

        $cmsPageId = $this->detectCmsPageId();
        if ($cmsPageId !== null) {
            return [HreflangResolverInterface::ENTITY_CMS, $cmsPageId];
        }

        return [null, 0];
    }

    /**
     * Resolve the CMS page id for the current storefront request using the
     * matched full-action-name. Returns null when not on a CMS page.
     */
    private function detectCmsPageId(): ?int
    {
        $action = (string) $this->request->getFullActionName();

        if ($action === 'cms_index_index') {
            $configured = (string) $this->scopeConfig->getValue(
                CmsPageHelper::XML_PATH_HOME_PAGE,
                ScopeInterface::SCOPE_STORE
            );
            if ($configured === '') {
                return null;
            }
            // The admin "Default Pages" picker stores the value as
            // "<identifier>|<page_id>" whenever more than one CMS page
            // shares an identifier across stores. The numeric suffix is
            // the authoritative page id picked in admin — prefer it over
            // the identifier lookup, which would otherwise miss the row
            // entirely (the literal string "home|42" never matches a
            // cms_page.identifier).
            if (str_contains($configured, '|')) {
                [$identifier, $explicitId] = explode('|', $configured, 2);
                if ((int) $explicitId > 0) {
                    return (int) $explicitId;
                }
            } else {
                $identifier = $configured;
            }
            return $this->lookupCmsPageIdByIdentifier($identifier);
        }

        if ($action === 'cms_page_view') {
            $paramId = $this->request->getParam('page_id')
                ?? $this->request->getParam('id');
            if ($paramId !== null && (int) $paramId > 0) {
                return (int) $paramId;
            }
        }

        return null;
    }

    private function lookupCmsPageIdByIdentifier(string $identifier): ?int
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('cms_page');

        $id = $connection->fetchOne(
            $connection->select()
                ->from($table, ['page_id'])
                ->where('identifier = ?', $identifier)
                ->where('is_active = ?', 1)
                ->order('page_id ASC')
                ->limit(1)
        );

        return $id !== false && (int) $id > 0 ? (int) $id : null;
    }
}
