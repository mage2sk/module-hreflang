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

    public function getAlternates(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }
        try {
            if ((string) $this->request->getFullActionName() === 'cms_noroute_index') {
                return [];
            }
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

            return array_values(array_filter(
                $alternates,
                static fn (array $alt): bool => self::isValidAlternate($alt)
            ));
        } catch (\Throwable) {
            return [];
        }
    }

    private static function isValidAlternate(array $alt): bool
    {
        $locale = (string) ($alt['locale'] ?? '');
        $url = (string) ($alt['url'] ?? '');
        if (preg_match(HreflangResolverInterface::HREFLANG_CODE_PATTERN, $locale) !== 1) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

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
