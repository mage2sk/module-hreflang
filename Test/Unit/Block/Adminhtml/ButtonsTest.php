<?php
declare(strict_types=1);

namespace Panth\Hreflang\Test\Unit\Block\Adminhtml;

use Magento\Framework\App\Request\Http;
use Magento\Framework\UrlInterface;
use Panth\Hreflang\Block\Adminhtml\GenericBackButton;
use Panth\Hreflang\Block\Adminhtml\GenericDeleteButton;
use PHPUnit\Framework\TestCase;

class ButtonsTest extends TestCase
{
    private function url(): UrlInterface
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn(string $path, $params = null) => 'https://admin.example.com/' . $path
                . (empty($params) ? '' : '/id/' . $params['id'])
        );
        return $url;
    }

    private function request(?string $id): Http
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturn($id);
        $request->method('getRouteName')->willReturn('panth_hreflang');
        $request->method('getControllerName')->willReturn('hreflang');
        return $request;
    }

    public function testBackButtonPointsToTheGrid(): void
    {
        $data = (new GenericBackButton($this->url()))->getButtonData();

        $this->assertSame("location.href = 'https://admin.example.com/*/*/';", $data['on_click']);
        $this->assertSame('back', $data['class']);
        $this->assertSame('Back', (string) $data['label']);
    }

    public function testDeleteButtonIsHiddenForNewRecords(): void
    {
        $this->assertSame([], (new GenericDeleteButton($this->url(), $this->request(null)))->getButtonData());
    }

    public function testDeleteButtonTargetsTheCurrentRouteAndController(): void
    {
        $data = (new GenericDeleteButton($this->url(), $this->request('5')))->getButtonData();

        $this->assertStringContainsString(
            "'https://admin.example.com/panth_hreflang/hreflang/delete/id/5'",
            $data['on_click']
        );
        $this->assertStringStartsWith(
            "deleteConfirm('Are\\u0020you\\u0020sure\\u0020you\\u0020want\\u0020to\\u0020delete\\u0020this\\u0020item\\u003F'",
            $data['on_click']
        );
        $this->assertSame(20, $data['sort_order']);
    }

    private function withApostropheTranslation(callable $fn): mixed
    {
        $previous = \Magento\Framework\Phrase::getRenderer();
        \Magento\Framework\Phrase::setRenderer(new class implements \Magento\Framework\Phrase\RendererInterface {
            public function render(array $source, array $arguments)
            {
                return "It's " . end($source);
            }
        });
        try {
            return $fn();
        } finally {
            \Magento\Framework\Phrase::setRenderer($previous);
        }
    }

    public function testTranslatedConfirmTextIsJsEscaped(): void
    {
        $data = $this->withApostropheTranslation(
            fn() => (new GenericDeleteButton($this->url(), $this->request('5')))->getButtonData()
        );

        $this->assertStringNotContainsString("It's", $data['on_click']);
        $this->assertStringContainsString('It\\u0027s', $data['on_click']);
    }
}
