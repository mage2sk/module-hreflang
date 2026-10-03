<?php
declare(strict_types=1);

namespace Panth\Hreflang\Test\Unit\ViewModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\DataObject;
use Magento\Framework\Registry;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Hreflang\Api\HreflangResolverInterface;
use Panth\Hreflang\Helper\Config;
use Panth\Hreflang\Test\Unit\DbStubTrait;
use Panth\Hreflang\ViewModel\Hreflang;
use PHPUnit\Framework\TestCase;

class HreflangTest extends TestCase
{
    use DbStubTrait;

    private array $registry = [];
    private string $action = 'catalog_product_view';
    private array $params = [];
    private string $homePage = '';
    private bool $enabled = true;
    private string $currentUrl = 'https://shop.example.com/page?utm=1';

    private function viewModel(
        ?HreflangResolverInterface $resolver = null,
        array $fetchOne = []
    ): Hreflang {
        $registryValues = $this->registry;
        $registry = $this->createStub(Registry::class);
        $registry->method('registry')->willReturnCallback(static fn($key) => $registryValues[$key] ?? null);

        $params = $this->params;
        $request = $this->createStub(Http::class);
        $request->method('getFullActionName')->willReturn($this->action);
        $request->method('getParam')->willReturnCallback(static fn($key) => $params[$key] ?? null);

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(3);
        $store->method('getCurrentUrl')->willReturn($this->currentUrl);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $enabled = $this->enabled;
        $flagConfig = $this->createStub(ScopeConfigInterface::class);
        $flagConfig->method('isSetFlag')->willReturn($enabled);

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($this->homePage);

        return new Hreflang(
            $resolver ?? $this->createStub(HreflangResolverInterface::class),
            $registry,
            $request,
            $storeManager,
            new Config($flagConfig),
            $scopeConfig,
            $this->resourceStub($this->connectionStub($fetchOne))
        );
    }

    private function expectResolve(string $type, int $id, array $result = []): HreflangResolverInterface
    {
        $resolver = $this->createMock(HreflangResolverInterface::class);
        $resolver->expects($this->once())->method('getAlternates')->with($type, $id, 3)->willReturn($result);
        return $resolver;
    }

    public function testDisabledReturnsNothing(): void
    {
        $this->enabled = false;
        $resolver = $this->createMock(HreflangResolverInterface::class);
        $resolver->expects($this->never())->method('getAlternates');

        $vm = $this->viewModel($resolver);
        $this->assertFalse($vm->isEnabled());
        $this->assertSame([], $vm->getAlternates());
    }

    public function testNoRoutePageReturnsNothing(): void
    {
        $this->action = 'cms_noroute_index';
        $this->assertSame([], $this->viewModel()->getAlternates());
    }

    public function testProductIsResolvedAndInvalidEntriesFiltered(): void
    {
        $this->registry['current_product'] = new DataObject(['id' => 42]);
        $resolver = $this->expectResolve('product', 42, [
            ['locale' => 'en-GB', 'url' => 'https://en.example.com/p'],
            ['locale' => 'bad locale', 'url' => 'https://en.example.com/p'],
            ['locale' => 'de-DE', 'url' => 'javascript:alert(1)'],
            ['locale' => 'fr-FR', 'url' => 'https://fr.example.com/p'],
        ]);

        $result = $this->viewModel($resolver)->getAlternates();

        $this->assertSame(['en-GB', 'fr-FR'], array_column($result, 'locale'));
    }

    public function testCategoryIsUsedWhenNoProduct(): void
    {
        $this->registry['current_product'] = new DataObject([]);
        $this->registry['current_category'] = new DataObject(['id' => 8]);
        $resolver = $this->expectResolve('category', 8, [
            ['locale' => 'en-GB', 'url' => 'https://en.example.com/c'],
        ]);

        $this->assertCount(1, $this->viewModel($resolver)->getAlternates());
    }

    public function testRegisteredCmsPageIsUsed(): void
    {
        $this->registry['cms_page'] = new DataObject(['id' => 6]);
        $resolver = $this->expectResolve('cms', 6, [['locale' => 'en-GB', 'url' => 'https://e.example.com/']]);

        $this->assertCount(1, $this->viewModel($resolver)->getAlternates());
    }

    public function testCmsPageViewFallsBackToRequestParam(): void
    {
        $this->action = 'cms_page_view';
        $this->params = ['id' => '12'];
        $resolver = $this->expectResolve('cms', 12, [['locale' => 'en-GB', 'url' => 'https://e.example.com/']]);

        $this->viewModel($resolver)->getAlternates();
    }

    public function testHomePageWithExplicitIdSkipsLookup(): void
    {
        $this->action = 'cms_index_index';
        $this->homePage = 'home|15';
        $resolver = $this->expectResolve('cms', 15, [['locale' => 'en-GB', 'url' => 'https://e.example.com/']]);

        $this->viewModel($resolver, ['99'])->getAlternates();
    }

    public function testHomePageIdentifierIsLookedUp(): void
    {
        $this->action = 'cms_index_index';
        $this->homePage = 'home';
        $resolver = $this->expectResolve('cms', 21, [['locale' => 'en-GB', 'url' => 'https://e.example.com/']]);

        $this->viewModel($resolver, ['21'])->getAlternates();
        $this->assertSame('home', $this->whereValueFor('identifier = ?'));
    }

    public function testHomePageIdentifierWithZeroIdIsLookedUp(): void
    {
        $this->action = 'cms_index_index';
        $this->homePage = 'start|0';
        $resolver = $this->expectResolve('cms', 5, [['locale' => 'en-GB', 'url' => 'https://e.example.com/']]);

        $this->viewModel($resolver, ['5'])->getAlternates();
        $this->assertSame('start', $this->whereValueFor('identifier = ?'));
    }

    public function testUnknownPageFallsBackToSelfReferencingXDefault(): void
    {
        $this->action = 'cms_index_index';
        $this->homePage = 'missing';
        $resolver = $this->createMock(HreflangResolverInterface::class);
        $resolver->expects($this->never())->method('getAlternates');

        $result = $this->viewModel($resolver, [false])->getAlternates();

        $this->assertSame(
            [['locale' => 'x-default', 'url' => 'https://shop.example.com/page', 'is_default' => true]],
            $result
        );
    }

    public function testEmptyResolverResultFallsBackToCurrentUrl(): void
    {
        $this->registry['current_product'] = new DataObject(['id' => 1]);
        $resolver = $this->expectResolve('product', 1, []);

        $result = $this->viewModel($resolver)->getAlternates();

        $this->assertSame('x-default', $result[0]['locale']);
        $this->assertSame('https://shop.example.com/page', $result[0]['url']);
    }

    public function testEmptyCurrentUrlYieldsNothing(): void
    {
        $this->action = 'some_other_page';
        $this->currentUrl = '';
        $this->assertSame([], $this->viewModel()->getAlternates());
    }

    public function testResolverExceptionIsSwallowed(): void
    {
        $this->registry['current_product'] = new DataObject(['id' => 1]);
        $resolver = $this->createStub(HreflangResolverInterface::class);
        $resolver->method('getAlternates')->willThrowException(new \RuntimeException('db'));

        $this->assertSame([], $this->viewModel($resolver)->getAlternates());
    }
}
