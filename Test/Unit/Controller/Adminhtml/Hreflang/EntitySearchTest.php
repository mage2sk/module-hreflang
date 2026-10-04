<?php
declare(strict_types=1);

namespace Panth\Hreflang\Test\Unit\Controller\Adminhtml\Hreflang;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Panth\Hreflang\Controller\Adminhtml\Hreflang\EntitySearch;

class EntitySearchTest extends ControllerTestCase
{
    private array $payload = [];

    private function search(array $params, array $fetchOne, array $rows): array
    {
        $json = $this->createStub(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->payload = $data;
            return $json;
        });
        $factory = $this->createStub(JsonFactory::class);
        $factory->method('create')->willReturn($json);

        $connection = $this->connectionStub($fetchOne, [$rows]);
        (new EntitySearch($this->context($params), $factory, $this->resourceStub($connection)))->execute();

        return $this->payload;
    }

    private function whereConditions(): array
    {
        return array_column($this->wheres, 0);
    }

    public function testProductsAreTheDefaultTypeAndLabelsFallBack(): void
    {
        $result = $this->search([], ['73'], [
            ['entity_id' => '1', 'sku' => 'SKU-1', 'name' => 'Shirt'],
            ['entity_id' => '2', 'sku' => '', 'name' => null],
        ]);

        $this->assertSame(['items' => [
            ['id' => 1, 'label' => 'SKU-1 - Shirt', 'url' => ''],
            ['id' => 2, 'label' => '(no sku) - (unnamed)', 'url' => ''],
        ]], $result);
        $this->assertNotContains('p.sku LIKE ? OR n.value LIKE ?', $this->whereConditions());
    }

    public function testNumericProductQueryAlsoMatchesTheId(): void
    {
        $this->search(['q' => ' 42 '], ['73'], []);
        $this->assertContains('p.entity_id = ? OR p.sku LIKE ? OR n.value LIKE ?', $this->whereConditions());
        $this->assertSame('42', $this->whereValueFor('p.entity_id = ? OR p.sku LIKE ? OR n.value LIKE ?'));
    }

    public function testTextProductQueryUsesLike(): void
    {
        $this->search(['q' => 'shirt'], ['73'], []);
        $this->assertSame('%shirt%', $this->whereValueFor('p.sku LIKE ? OR n.value LIKE ?'));
    }

    public function testCategoriesExcludeRootLevels(): void
    {
        $result = $this->search(['type' => 'category', 'q' => 'men'], ['45'], [
            ['entity_id' => '9', 'path' => '1/2/9', 'name' => 'Men'],
            ['entity_id' => '10', 'path' => '1/2/10', 'name' => ''],
        ]);

        $this->assertSame('[#9] Men', $result['items'][0]['label']);
        $this->assertSame('[#10] (unnamed)', $result['items'][1]['label']);
        $this->assertSame(1, $this->whereValueFor('c.level > ?'));
        $this->assertSame('%men%', $this->whereValueFor('n.value LIKE ?'));
    }

    public function testNumericCategoryQueryMatchesId(): void
    {
        $this->search(['type' => 'category', 'q' => '9'], ['45'], []);
        $this->assertSame('9', $this->whereValueFor('c.entity_id = ? OR n.value LIKE ?'));
    }

    public function testCmsPagesOnlyActiveAndLabelled(): void
    {
        $result = $this->search(['type' => 'cms_page', 'q' => 'about', 'store_id' => '2'], [], [
            ['page_id' => '4', 'identifier' => 'about-us', 'title' => 'About', 'is_active' => 1],
            ['page_id' => '5', 'identifier' => '', 'title' => '', 'is_active' => 1],
        ]);

        $this->assertSame('[#4] About (about-us)', $result['items'][0]['label']);
        $this->assertSame('[#5] (untitled) ()', $result['items'][1]['label']);
        $this->assertSame(1, $this->whereValueFor('p.is_active = ?'));
        $this->assertSame('%about%', $this->whereValueFor('p.identifier LIKE ? OR p.title LIKE ?'));
    }

    public function testNumericCmsQueryMatchesPageId(): void
    {
        $this->search(['type' => 'cms_page', 'q' => '4'], [], []);
        $this->assertSame('4', $this->whereValueFor('p.page_id = ? OR p.identifier LIKE ? OR p.title LIKE ?'));
    }
}
