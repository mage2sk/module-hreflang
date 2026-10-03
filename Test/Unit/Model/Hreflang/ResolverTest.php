<?php
declare(strict_types=1);

namespace Panth\Hreflang\Test\Unit\Model\Hreflang;

use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Panth\Hreflang\Helper\Config;
use Panth\Hreflang\Model\Hreflang\Resolver;
use Panth\Hreflang\Test\Unit\DbStubTrait;
use PHPUnit\Framework\TestCase;

class ResolverTest extends TestCase
{
    use DbStubTrait;

    private const ENABLED = Config::XML_PATH_HREFLANG_ENABLED;
    private const XDEFAULT = Config::XML_PATH_HREFLANG_X_DEFAULT;

    private array $values = [];
    private array $flags = [self::ENABLED => true];
    /** @var array<int,Store> */
    private array $stores = [];
    private ?ProductResource $productResource = null;
    private ?CategoryResource $categoryResource = null;
    private ?WebsiteRepositoryInterface $websiteRepository = null;

    protected function setUp(): void
    {
        $this->values = [Config::XML_PATH_HREFLANG_SCOPE => 'global'];
        $this->stores = [
            1 => $this->store(1, true, 'https://en.example.com/'),
            2 => $this->store(2, true, 'https://de.example.com'),
            3 => $this->store(3, true, 'https://fr.example.com/'),
        ];
    }

    private function store(int $id, bool $active, string $baseUrl, int $websiteId = 1, int $root = 0): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('getIsActive')->willReturn($active);
        $store->method('getBaseUrl')->willReturn($baseUrl);
        $store->method('getWebsiteId')->willReturn($websiteId);
        $store->method('getRootCategoryId')->willReturn($root);
        return $store;
    }

    private function resolver(AdapterInterface $connection): Resolver
    {
        return $this->resolverWith($this->resourceStub($connection));
    }

    private function resolverWith(ResourceConnection $resource): Resolver
    {
        $values = $this->values;
        $flags = $this->flags;
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn(string $path, $scope = null, $code = null) => $values[$path . '@' . $code] ?? $values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn(string $path) => $flags[$path] ?? false
        );

        $stores = $this->stores;
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(static function ($id) use ($stores) {
            if (!isset($stores[(int) $id])) {
                throw new NoSuchEntityException(__('missing'));
            }
            return $stores[(int) $id];
        });
        $storeManager->method('getStores')->willReturn(array_values($stores));

        return new Resolver(
            $resource,
            $storeManager,
            $this->websiteRepository ?? $this->createStub(WebsiteRepositoryInterface::class),
            new Config($scopeConfig),
            $this->productResource ?? $this->createStub(ProductResource::class),
            $this->categoryResource ?? $this->createStub(CategoryResource::class)
        );
    }

    private function member(int $storeId, string $locale, string $url, int $default = 0, string $type = 'cms', int $id = 10): array
    {
        return [
            'store_id' => (string) $storeId,
            'entity_type' => $type,
            'entity_id' => (string) $id,
            'locale' => $locale,
            'url' => $url,
            'is_default' => (string) $default,
        ];
    }

    // ---- group resolution ------------------------------------------------

    public function testDisabledReturnsEmptyWithoutTouchingTheDatabase(): void
    {
        $this->flags = [];
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');

        $this->assertSame([], $this->resolverWith($resource)->getAlternates('product', 5, 1));
    }

    public function testNoActiveGroupReturnsEmpty(): void
    {
        $this->assertSame([], $this->resolver($this->connectionStub([false]))->getAlternates('category', 5, 1));
    }

    public function testTwoValidMembersProduceAlternatesWithoutXDefault(): void
    {
        $connection = $this->connectionStub(['7'], [[
            $this->member(1, 'en-GB', 'https://en.example.com/a', 0, 'faq'),
            $this->member(2, 'de-DE', 'https://de.example.com/a', 0, 'faq'),
        ]]);

        $result = $this->resolver($connection)->getAlternates('faq', 10, 1);

        $this->assertSame([
            ['locale' => 'en-GB', 'url' => 'https://en.example.com/a', 'is_default' => false],
            ['locale' => 'de-DE', 'url' => 'https://de.example.com/a', 'is_default' => false],
        ], $result);
        $this->assertSame(7, $this->whereValueFor('group_id = ?'));
        $this->assertSame(['faq'], $this->whereValueFor('m.entity_type IN (?)'));
    }

    public function testXDefaultUsesTheFlaggedDefaultMemberUrl(): void
    {
        $this->flags[self::XDEFAULT] = true;
        $connection = $this->connectionStub(['7'], [[
            $this->member(1, 'en-GB', 'https://en.example.com/a'),
            $this->member(2, 'de-DE', 'https://de.example.com/a', 1),
        ]]);

        $result = $this->resolver($connection)->getAlternates('faq', 10, 1);

        $this->assertCount(3, $result);
        $this->assertSame(
            ['locale' => 'x-default', 'url' => 'https://de.example.com/a', 'is_default' => true],
            $result[2]
        );
        $this->assertTrue($result[1]['is_default']);
    }

    public function testXDefaultFallsBackToFirstUrlWhenNoMemberIsDefault(): void
    {
        $this->flags[self::XDEFAULT] = true;
        $connection = $this->connectionStub(['7'], [[
            $this->member(1, 'en-GB', 'https://en.example.com/a'),
            $this->member(2, 'de-DE', 'https://de.example.com/a'),
        ]]);

        $result = $this->resolver($connection)->getAlternates('faq', 10, 1);

        $this->assertSame('https://en.example.com/a', $result[2]['url']);
    }

    public function testExplicitXDefaultMemberSuppressesTheGeneratedOne(): void
    {
        $this->flags[self::XDEFAULT] = true;
        $connection = $this->connectionStub(['7'], [[
            $this->member(1, 'en-GB', 'https://en.example.com/a'),
            $this->member(2, 'X-Default', 'https://example.com/'),
        ]]);

        $result = $this->resolver($connection)->getAlternates('faq', 10, 1);

        $this->assertCount(2, $result);
        $this->assertSame('X-Default', $result[1]['locale']);
    }

    public function testInvalidAndDuplicateRowsAreDropped(): void
    {
        $connection = $this->connectionStub(['7'], [[
            $this->member(1, 'en-GB', 'https://en.example.com/a'),
            $this->member(2, 'EN-gb', 'https://de.example.com/dup'),
            $this->member(2, 'english', 'https://de.example.com/bad-locale'),
            $this->member(3, 'fr-FR', 'ftp://fr.example.com/a'),
            $this->member(3, 'fr-CA', '/relative/path'),
            $this->member(3, ' it-IT ', ' https://it.example.com/a '),
        ]]);

        $result = $this->resolver($connection)->getAlternates('faq', 10, 1);

        $this->assertSame(['en-GB', 'it-IT'], array_column($result, 'locale'));
        $this->assertSame('https://it.example.com/a', $result[1]['url']);
    }

    public function testFewerThanTwoValidAlternatesReturnsEmpty(): void
    {
        $this->flags[self::XDEFAULT] = true;
        $connection = $this->connectionStub(['7'], [[
            $this->member(1, 'en-GB', 'https://en.example.com/a'),
            $this->member(2, 'de-DE', 'not a url'),
        ]]);

        $this->assertSame([], $this->resolver($connection)->getAlternates('faq', 10, 1));
    }

    public function testMembersOfInactiveOrUnknownStoresAreFiltered(): void
    {
        $this->stores[2] = $this->store(2, false, 'https://de.example.com/');
        $connection = $this->connectionStub(['7'], [[
            $this->member(1, 'en-GB', 'https://en.example.com/a'),
            $this->member(2, 'de-DE', 'https://de.example.com/a'),
            $this->member(3, 'fr-FR', 'https://fr.example.com/a'),
            $this->member(99, 'es-ES', 'https://es.example.com/a'),
        ]]);

        $result = $this->resolver($connection)->getAlternates('faq', 10, 1);

        $this->assertSame(['en-GB', 'fr-FR'], array_column($result, 'locale'));
    }

    public function testWebsiteScopeRestrictsMembersToTheCurrentWebsite(): void
    {
        $this->values[Config::XML_PATH_HREFLANG_SCOPE] = 'website';
        $website = $this->createStub(Website::class);
        $website->method('getStoreIds')->willReturn(['1', '3']);
        $repo = $this->createMock(WebsiteRepositoryInterface::class);
        $repo->expects($this->once())->method('getById')->with(1)->willReturn($website);
        $this->websiteRepository = $repo;

        $connection = $this->connectionStub(['7'], [[
            $this->member(1, 'en-GB', 'https://en.example.com/a'),
            $this->member(3, 'fr-FR', 'https://fr.example.com/a'),
        ]]);

        $result = $this->resolver($connection)->getAlternates('faq', 10, 1);

        $this->assertCount(2, $result);
        $this->assertSame([1, 3], $this->whereValueFor('store_id IN (?)'));
    }

    public function testWebsiteScopeLookupFailureFallsBackToAllStores(): void
    {
        $this->values[Config::XML_PATH_HREFLANG_SCOPE] = 'website';
        $repo = $this->createStub(WebsiteRepositoryInterface::class);
        $repo->method('getById')->willThrowException(new NoSuchEntityException(__('gone')));
        $this->websiteRepository = $repo;

        $connection = $this->connectionStub(['7'], [[
            $this->member(1, 'en-GB', 'https://en.example.com/a'),
            $this->member(2, 'de-DE', 'https://de.example.com/a'),
        ]]);

        $this->assertCount(2, $this->resolver($connection)->getAlternates('faq', 10, 1));
        $this->assertFalse($this->hasWhere('store_id IN (?)'));
    }

    public function testGlobalScopeDoesNotFilterStores(): void
    {
        $connection = $this->connectionStub(['7'], [[]]);
        $this->resolver($connection)->getAlternates('faq', 10, 1);
        $this->assertFalse($this->hasWhere('store_id IN (?)'));
    }

    public function testCmsIdentifierMethodSearchesBothCmsEntityTypes(): void
    {
        $this->values[Config::XML_PATH_HREFLANG_CMS_RELATION] = 'by_identifier';
        $connection = $this->connectionStub([false]);

        $this->assertSame([], $this->resolver($connection)->getAlternates('cms_page', 4, 1));
        $this->assertSame(['cms', 'cms_page'], $this->whereValueFor('m.entity_type IN (?)'));
    }

    // ---- product / category availability ---------------------------------

    private function productResource(array $websites, array $status, array $visibility): ProductResource
    {
        $resource = $this->createStub(ProductResource::class);
        $resource->method('getWebsiteIds')->willReturnCallback(static fn($id) => $websites[$id] ?? []);
        $resource->method('getAttributeRawValue')->willReturnCallback(
            static function ($id, $attr) use ($status, $visibility) {
                return $attr === 'status' ? ($status[$id] ?? 2) : ($visibility[$id] ?? 1);
            }
        );
        return $resource;
    }

    public function testProductMembersRequireWebsiteStatusAndVisibility(): void
    {
        $this->productResource = $this->productResource(
            [11 => [1], 12 => [2], 13 => [1], 14 => [1]],
            [11 => 1, 12 => 1, 13 => 2, 14 => 1],
            [11 => 4, 12 => 4, 13 => 4, 14 => 1]
        );
        $connection = $this->connectionStub(['7'], [[
            $this->member(1, 'en-GB', 'https://en.example.com/p', 0, 'product', 11),
            $this->member(2, 'de-DE', 'https://de.example.com/p', 0, 'product', 12),
            $this->member(3, 'fr-FR', 'https://fr.example.com/p', 0, 'product', 13),
            $this->member(1, 'en-US', 'https://en.example.com/us', 0, 'product', 14),
            $this->member(3, 'fr-CA', 'https://fr.example.com/ca', 0, 'product', 11),
        ]]);

        $result = $this->resolver($connection)->getAlternates('product', 11, 1);

        $this->assertSame(['en-GB', 'fr-CA'], array_column($result, 'locale'));
    }

    public function testProductLookupErrorsKeepTheMember(): void
    {
        $resource = $this->createStub(ProductResource::class);
        $resource->method('getWebsiteIds')->willThrowException(new \RuntimeException('db down'));
        $this->productResource = $resource;
        $connection = $this->connectionStub(['7'], [[
            $this->member(1, 'en-GB', 'https://en.example.com/p', 0, 'product'),
            $this->member(2, 'de-DE', 'https://de.example.com/p', 0, 'product'),
        ]]);

        $this->assertCount(2, $this->resolver($connection)->getAlternates('product', 10, 1));
    }

    public function testCategoryMembersMustBeActiveAndUnderTheStoreRoot(): void
    {
        $this->stores[1] = $this->store(1, true, 'https://en.example.com/', 1, 2);
        $this->stores[2] = $this->store(2, true, 'https://de.example.com/', 1, 3);
        $this->stores[3] = $this->store(3, true, 'https://fr.example.com/', 1, 0);
        $category = $this->createStub(CategoryResource::class);
        $category->method('getAttributeRawValue')->willReturnCallback(
            static fn($id) => $id === 30 ? '0' : '1'
        );
        $this->categoryResource = $category;

        // fetchOne: group id, then category path for store 1 and store 2 (store 3 has no root).
        $connection = $this->connectionStub(['7', '1/2/20', '1/2/21'], [[
            $this->member(1, 'en-GB', 'https://en.example.com/c', 0, 'category', 20),
            $this->member(2, 'de-DE', 'https://de.example.com/c', 0, 'category', 21),
            $this->member(3, 'fr-FR', 'https://fr.example.com/c', 0, 'category', 22),
            $this->member(3, 'fr-CA', 'https://fr.example.com/ca', 0, 'category', 30),
        ]]);

        $result = $this->resolver($connection)->getAlternates('category', 20, 1);

        $this->assertSame(['en-GB', 'fr-FR'], array_column($result, 'locale'));
    }

    // ---- CMS relation -----------------------------------------------------

    private function cmsLocales(): void
    {
        $this->values['general/locale/code@1'] = 'en_GB';
        $this->values['general/locale/code@2'] = 'de_DE';
        $this->values['general/locale/code@3'] = 'fr_FR';
    }

    public function testCmsByIdBuildsUrlsFromStoreBaseUrlAndLocale(): void
    {
        $this->values[Config::XML_PATH_HREFLANG_CMS_RELATION] = 'by_id';
        $this->cmsLocales();
        $connection = $this->connectionStub([], [[
            ['page_id' => 4, 'identifier' => 'about-us', 'store_id' => '2'],
            ['page_id' => 4, 'identifier' => 'about-us', 'store_id' => '1'],
        ]]);

        $result = $this->resolver($connection)->getAlternates('cms', 4, 1);

        $this->assertSame([
            ['locale' => 'en-GB', 'url' => 'https://en.example.com/about-us', 'is_default' => false],
            ['locale' => 'de-DE', 'url' => 'https://de.example.com/about-us', 'is_default' => false],
        ], $result);
        $this->assertSame(4, $this->whereValueFor('p.page_id = ?'));
    }

    public function testCmsAllStoreViewRowExpandsToEveryStoreAndStoreRowsOverride(): void
    {
        $this->values[Config::XML_PATH_HREFLANG_CMS_RELATION] = 'by_id';
        $this->cmsLocales();
        $this->flags[self::XDEFAULT] = true;
        $connection = $this->connectionStub([], [[
            ['page_id' => 4, 'identifier' => 'about', 'store_id' => '0'],
            ['page_id' => 4, 'identifier' => 'ueber-uns', 'store_id' => '2'],
        ]]);

        $result = $this->resolver($connection)->getAlternates('cms_page', 4, 1);

        $this->assertSame(
            ['https://en.example.com/about', 'https://de.example.com/ueber-uns', 'https://fr.example.com/about', 'https://en.example.com/about'],
            array_column($result, 'url')
        );
        $this->assertSame('x-default', $result[3]['locale']);
        $this->assertTrue($result[3]['is_default']);
    }

    public function testCmsHomePageMapsToTheStoreBaseUrl(): void
    {
        $this->values[Config::XML_PATH_HREFLANG_CMS_RELATION] = 'by_id';
        $this->cmsLocales();
        $this->values['web/default/cms_home_page@1'] = 'home|2';
        $connection = $this->connectionStub([], [[
            ['page_id' => 2, 'identifier' => 'home', 'store_id' => '1'],
            ['page_id' => 2, 'identifier' => 'home', 'store_id' => '2'],
        ]]);

        $result = $this->resolver($connection)->getAlternates('cms', 2, 1);

        $this->assertSame('https://en.example.com/', $result[0]['url']);
        $this->assertSame('https://de.example.com/home', $result[1]['url']);
    }

    public function testCmsSkipsStoresWithoutLocaleOrInactiveAndNeedsTwo(): void
    {
        $this->values[Config::XML_PATH_HREFLANG_CMS_RELATION] = 'by_id';
        $this->values['general/locale/code@1'] = 'en_GB';
        $this->stores[3] = $this->store(3, false, 'https://fr.example.com/');
        $this->values['general/locale/code@3'] = 'fr_FR';
        $connection = $this->connectionStub([], [[
            ['page_id' => 4, 'identifier' => 'a', 'store_id' => '1'],
            ['page_id' => 4, 'identifier' => 'a', 'store_id' => '2'],
            ['page_id' => 4, 'identifier' => 'a', 'store_id' => '3'],
        ]]);

        $this->assertSame([], $this->resolver($connection)->getAlternates('cms', 4, 1));
    }

    public function testCmsWebsiteScopeLimitsRelatedStores(): void
    {
        $this->values[Config::XML_PATH_HREFLANG_CMS_RELATION] = 'by_id';
        $this->values[Config::XML_PATH_HREFLANG_SCOPE] = 'website';
        $this->cmsLocales();
        $website = $this->createStub(Website::class);
        $website->method('getStoreIds')->willReturn([1, 2]);
        $repo = $this->createStub(WebsiteRepositoryInterface::class);
        $repo->method('getById')->willReturn($website);
        $this->websiteRepository = $repo;
        $connection = $this->connectionStub([], [[
            ['page_id' => 4, 'identifier' => 'a', 'store_id' => '0'],
        ]]);

        $result = $this->resolver($connection)->getAlternates('cms', 4, 1);

        $this->assertSame(['en-GB', 'de-DE'], array_column($result, 'locale'));
    }

    public function testCmsByUrlKeyLooksUpTheIdentifierFirst(): void
    {
        $this->cmsLocales();
        $connection = $this->connectionStub(['contact'], [[
            ['page_id' => 5, 'identifier' => 'contact', 'store_id' => '1'],
            ['page_id' => 9, 'identifier' => 'contact', 'store_id' => '3'],
        ]]);

        $result = $this->resolver($connection)->getAlternates('cms', 5, 1);

        $this->assertSame(['en-GB', 'fr-FR'], array_column($result, 'locale'));
        $this->assertSame('contact', $this->whereValueFor('p.identifier = ?'));
    }

    public function testCmsByUrlKeyWithUnknownPageReturnsEmpty(): void
    {
        $this->assertSame([], $this->resolver($this->connectionStub([false]))->getAlternates('cms', 5, 1));
    }

    public function testCmsRelationWithNoRowsReturnsEmpty(): void
    {
        $this->values[Config::XML_PATH_HREFLANG_CMS_RELATION] = 'by_id';
        $this->assertSame([], $this->resolver($this->connectionStub([], [[]]))->getAlternates('cms', 5, 1));
    }

    // ---- validateGroup ----------------------------------------------------

    private function groupRow(int $id, string $locale, string $url, int $default = 0, string $type = 'product'): array
    {
        return ['member_id' => (string) $id] + $this->member(1, $locale, $url, $default, $type);
    }

    public function testValidateGroupWithoutMembers(): void
    {
        $this->assertSame(
            ['Group 3 has no members.'],
            $this->resolver($this->connectionStub([], [[]]))->validateGroup(3)
        );
    }

    public function testValidateGroupAcceptsAHealthyGroup(): void
    {
        $connection = $this->connectionStub([], [[
            $this->groupRow(1, 'en-GB', 'https://en.example.com/a', 1),
            $this->groupRow(2, 'de-DE', 'https://de.example.com/a'),
        ]]);

        $this->assertSame([], $this->resolver($connection)->validateGroup(3));
        $this->assertSame(3, $this->whereValueFor('group_id = ?'));
    }

    public function testValidateGroupReportsEveryProblem(): void
    {
        $connection = $this->connectionStub([], [[
            $this->groupRow(1, 'en-GB', 'https://en.example.com/a', 1),
            $this->groupRow(2, 'EN-GB', 'https://en.example.com/b', 1, 'category'),
            $this->groupRow(3, 'en-gb', 'no-url', 1),
        ]]);

        $errors = $this->resolver($connection)->validateGroup(8);

        $this->assertContains('Duplicate locale "en-gb" in group 8.', $errors);
        $this->assertContains('Mixed entity types in group 8.', $errors);
        $this->assertContains('Invalid URL for member 3: no-url', $errors);
        $this->assertContains('Group 8 has 3 x-default rows (max 1).', $errors);
        $this->assertContains('Group 8 must contain at least 2 locales for hreflang to be meaningful.', $errors);
        $this->assertCount(6, $errors);
    }
}
