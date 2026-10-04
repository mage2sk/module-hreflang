<?php
declare(strict_types=1);

namespace Panth\Hreflang\Test\Unit\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Hreflang\Block\Adminhtml\System\Config\HreflangDiagnostic;
use Panth\Hreflang\Model\Hreflang\Diagnostic;
use PHPUnit\Framework\TestCase;

class HreflangDiagnosticTest extends TestCase
{
    private ?int $diagnosedStore = null;

    protected function setUp(): void
    {
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(
            fn($class) => $class === SecureHtmlRenderer::class ? $this->createStub(SecureHtmlRenderer::class) : null
        );
        ObjectManager::setInstance($objectManager);
    }

    protected function tearDown(): void
    {
        $property = new \ReflectionProperty(ObjectManager::class, '_instance');
        $property->setValue(null, null);
    }

    private function block(array $issues, string $storeParam = ''): HreflangDiagnostic
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturn($storeParam);
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(
            static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES)
        );
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getEscaper')->willReturn($escaper);

        $diagnostic = $this->createStub(Diagnostic::class);
        $diagnostic->method('runDiagnostics')->willReturnCallback(function (int $storeId) use ($issues) {
            $this->diagnosedStore = $storeId;
            return $issues;
        });

        $german = $this->createStub(Store::class);
        $german->method('getId')->willReturn(2);
        $default = $this->createStub(Store::class);
        $default->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(static function ($code) use ($german) {
            if ($code === 'de') {
                return $german;
            }
            throw new NoSuchEntityException(__('missing'));
        });
        $storeManager->method('getDefaultStoreView')->willReturn($default);

        return new HreflangDiagnostic($context, $diagnostic, $storeManager);
    }

    private function render(HreflangDiagnostic $block): string
    {
        $method = new \ReflectionMethod($block, '_getElementHtml');
        return $method->invoke($block, $this->createStub(AbstractElement::class));
    }

    public function testAllChecksPassWithoutIssues(): void
    {
        $html = $this->render($this->block([]));

        $this->assertSame(5, substr_count($html, '&#10003;'));
        $this->assertStringNotContainsString('&#10007;', $html);
        $this->assertStringNotContainsString('Issues Found', $html);
        $this->assertSame(1, $this->diagnosedStore);
    }

    public function testFailedChecksAndEscapedMessagesAreListed(): void
    {
        $html = $this->render($this->block([
            ['type' => 'conflict', 'severity' => 'error', 'message' => 'Group "<b>x</b>" clash'],
            ['type' => 'x_default', 'severity' => 'warning', 'message' => 'No default'],
        ], 'de'));

        $this->assertSame(3, substr_count($html, '&#10003;'));
        $this->assertSame(2, substr_count($html, '&#10007;'));
        $this->assertStringContainsString('Issues Found:', $html);
        $this->assertStringContainsString('color:#dc3545;font-weight:600">Group &quot;&lt;b&gt;x&lt;/b&gt;&quot; clash', $html);
        $this->assertStringContainsString('color:#856404">No default', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertSame(2, $this->diagnosedStore);
    }

    public function testUnknownStoreCodeFallsBackToDefaultStoreView(): void
    {
        $this->render($this->block([], 'nope'));
        $this->assertSame(1, $this->diagnosedStore);
    }

    public function testScopeLabelIsSuppressed(): void
    {
        $block = $this->block([]);
        $method = new \ReflectionMethod($block, '_renderScopeLabel');
        $this->assertSame('', $method->invoke($block, $this->createStub(AbstractElement::class)));
    }
}
