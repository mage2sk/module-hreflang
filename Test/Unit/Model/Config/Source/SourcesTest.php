<?php
declare(strict_types=1);

namespace Panth\Hreflang\Test\Unit\Model\Config\Source;

use Panth\Hreflang\Model\Config\Source\CmsRelationMethod;
use Panth\Hreflang\Model\Config\Source\EntityType;
use Panth\Hreflang\Model\Config\Source\HreflangScope;
use PHPUnit\Framework\TestCase;

class SourcesTest extends TestCase
{
    private function values(array $options): array
    {
        return array_column($options, 'value');
    }

    public function testCmsRelationMethodOptions(): void
    {
        $options = (new CmsRelationMethod())->toOptionArray();
        $this->assertSame(['by_id', 'by_url_key', 'by_identifier'], $this->values($options));
        $this->assertSame('Same Page ID', (string) $options[0]['label']);
    }

    public function testEntityTypeOptionsMatchSaveAllowList(): void
    {
        $options = (new EntityType())->toOptionArray();
        $this->assertSame(['product', 'category', 'cms_page'], $this->values($options));
        $this->assertSame('CMS Page', $options[2]['label']);
    }

    public function testHreflangScopeOptions(): void
    {
        $options = (new HreflangScope())->toOptionArray();
        $this->assertSame(
            [HreflangScope::SCOPE_WEBSITE, HreflangScope::SCOPE_GLOBAL],
            $this->values($options)
        );
        $this->assertSame('Across All Websites', (string) $options[1]['label']);
    }
}
