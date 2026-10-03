<?php
declare(strict_types=1);

namespace Panth\Hreflang\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Panth\Hreflang\Model\Config\Source\CmsRelationMethod;
use Panth\Hreflang\Model\Config\Source\HreflangScope;

class Config
{
    public const XML_PATH_HREFLANG_ENABLED = 'panth_hreflang/hreflang/enabled';
    public const XML_PATH_HREFLANG_X_DEFAULT = 'panth_hreflang/hreflang/emit_x_default';
    public const XML_PATH_HREFLANG_SCOPE = 'panth_hreflang/hreflang/hreflang_scope';
    public const XML_PATH_HREFLANG_CMS_RELATION = 'panth_hreflang/hreflang/cms_relation_method';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->isHreflangEnabled($storeId);
    }

    public function isHreflangEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_HREFLANG_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function emitHreflangXDefault(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_HREFLANG_X_DEFAULT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getHreflangScope(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_HREFLANG_SCOPE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $value !== '' ? $value : HreflangScope::SCOPE_WEBSITE;
    }

    public function getCmsRelationMethod(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_HREFLANG_CMS_RELATION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $value !== '' ? $value : CmsRelationMethod::BY_URL_KEY;
    }

    public function getValue(string $path, ?int $storeId = null): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
