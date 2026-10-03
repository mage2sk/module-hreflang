<?php
declare(strict_types=1);

namespace Panth\Hreflang\Test\Unit\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Panth\Hreflang\Helper\Config;
use Panth\Hreflang\Model\Config\Source\CmsRelationMethod;
use Panth\Hreflang\Model\Config\Source\HreflangScope;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private function config(array $values = [], array $flags = []): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn(string $path) => $values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn(string $path) => $flags[$path] ?? false
        );
        return new Config($scopeConfig);
    }

    public function testEnabledFlagsFollowTheEnabledPath(): void
    {
        $on = $this->config([], [Config::XML_PATH_HREFLANG_ENABLED => true]);
        $this->assertTrue($on->isEnabled(1));
        $this->assertTrue($on->isHreflangEnabled(1));

        $off = $this->config();
        $this->assertFalse($off->isEnabled());
        $this->assertFalse($off->isHreflangEnabled());
    }

    public function testXDefaultFlagIsIndependentOfEnabled(): void
    {
        $config = $this->config([], [Config::XML_PATH_HREFLANG_X_DEFAULT => true]);
        $this->assertTrue($config->emitHreflangXDefault(2));
        $this->assertFalse($config->isHreflangEnabled(2));
    }

    public function testScopeDefaultsToWebsite(): void
    {
        $this->assertSame(HreflangScope::SCOPE_WEBSITE, $this->config()->getHreflangScope());
        $this->assertSame(
            HreflangScope::SCOPE_GLOBAL,
            $this->config([Config::XML_PATH_HREFLANG_SCOPE => 'global'])->getHreflangScope(1)
        );
    }

    public function testCmsRelationDefaultsToUrlKey(): void
    {
        $this->assertSame(CmsRelationMethod::BY_URL_KEY, $this->config()->getCmsRelationMethod());
        $this->assertSame(
            CmsRelationMethod::BY_ID,
            $this->config([Config::XML_PATH_HREFLANG_CMS_RELATION => 'by_id'])->getCmsRelationMethod(1)
        );
    }

    public function testGetValuePassesStoreScopeAndId(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())
            ->method('getValue')
            ->with('general/locale/code', ScopeInterface::SCOPE_STORE, 3)
            ->willReturn('de_DE');

        $this->assertSame('de_DE', (new Config($scopeConfig))->getValue('general/locale/code', 3));
    }

    public function testFlagsAreReadAtStoreScope(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with(Config::XML_PATH_HREFLANG_X_DEFAULT, ScopeInterface::SCOPE_STORE, 7)
            ->willReturn(true);

        $this->assertTrue((new Config($scopeConfig))->emitHreflangXDefault(7));
    }
}
