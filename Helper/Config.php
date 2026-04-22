<?php
declare(strict_types=1);

namespace Panth\Hreflang\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Panth\Hreflang\Model\Config\Source\CmsRelationMethod;
use Panth\Hreflang\Model\Config\Source\HreflangScope;

/**
 * Lightweight config accessor for Panth Hreflang.
 *
 * Reads from the `panth_hreflang/hreflang/*` system.xml group. Module is enabled
 * whenever the group enabled flag is on; there is no separate parent switch
 * owned by this module.
 */
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

    /**
     * Module-level master switch — mirrors the hreflang enabled flag.
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->isHreflangEnabled($storeId);
    }

    /**
     * Whether hreflang tag emission is enabled for the store.
     */
    public function isHreflangEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_HREFLANG_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Whether an x-default hreflang tag should be emitted.
     */
    public function emitHreflangXDefault(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_HREFLANG_X_DEFAULT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Hreflang alternates scope: website | global.
     */
    public function getHreflangScope(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_HREFLANG_SCOPE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $value !== '' ? $value : HreflangScope::SCOPE_WEBSITE;
    }

    /**
     * CMS hreflang relation method: by_id | by_url_key | by_identifier.
     */
    public function getCmsRelationMethod(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_HREFLANG_CMS_RELATION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $value !== '' ? $value : CmsRelationMethod::BY_URL_KEY;
    }

    /**
     * Generic passthrough for arbitrary config paths (used for general/locale/code).
     */
    public function getValue(string $path, ?int $storeId = null): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
