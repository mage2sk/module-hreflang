<?php
declare(strict_types=1);

namespace Panth\Hreflang\Test\Unit\Block\Head;

use Magento\Framework\View\Element\Template\Context;
use Panth\Hreflang\Block\Head\Hreflang;
use Panth\Hreflang\ViewModel\Hreflang as HreflangViewModel;
use PHPUnit\Framework\TestCase;

class HreflangTest extends TestCase
{
    public function testBlockDelegatesToTheViewModel(): void
    {
        $alternates = [['locale' => 'en-GB', 'url' => 'https://en.example.com/', 'is_default' => false]];
        $viewModel = $this->createStub(HreflangViewModel::class);
        $viewModel->method('isEnabled')->willReturn(true);
        $viewModel->method('getAlternates')->willReturn($alternates);

        $block = new Hreflang($this->createStub(Context::class), $viewModel);

        $this->assertTrue($block->isEnabled());
        $this->assertSame($alternates, $block->getAlternates());
    }

    public function testDisabledViewModelIsReported(): void
    {
        $viewModel = $this->createStub(HreflangViewModel::class);
        $viewModel->method('isEnabled')->willReturn(false);
        $viewModel->method('getAlternates')->willReturn([]);

        $block = new Hreflang($this->createStub(Context::class), $viewModel);

        $this->assertFalse($block->isEnabled());
        $this->assertSame([], $block->getAlternates());
    }
}
