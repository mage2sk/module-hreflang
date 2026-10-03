<?php
declare(strict_types=1);

namespace Panth\Hreflang\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Panth\Hreflang\Ui\Component\Listing\Column\HreflangActions;
use PHPUnit\Framework\TestCase;

class HreflangActionsTest extends TestCase
{
    private function column(): HreflangActions
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn(string $path, array $params) => $path . '/id/' . $params['id']
        );

        return new HreflangActions(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $url,
            [],
            ['name' => 'actions']
        );
    }

    public function testEditAndDeleteLinksAreAddedPerRow(): void
    {
        $result = $this->column()->prepareDataSource(['data' => ['items' => [
            ['group_id' => 7, 'code' => 'shoes'],
        ]]]);

        $actions = $result['data']['items'][0]['actions'];
        $this->assertSame('panth_hreflang/hreflang/edit/id/7', $actions['edit']['href']);
        $this->assertSame('Edit', $actions['edit']['label']);
        $this->assertSame('panth_hreflang/hreflang/delete/id/7', $actions['delete']['href']);
        $this->assertTrue($actions['delete']['post']);
        $this->assertSame('Delete group', $actions['delete']['confirm']['title']);
    }

    public function testRowsWithoutIdAreLeftAlone(): void
    {
        $result = $this->column()->prepareDataSource(['data' => ['items' => [['code' => 'x']]]]);

        $this->assertSame([['code' => 'x']], $result['data']['items']);
    }

    public function testDataSourceWithoutItemsIsReturnedUnchanged(): void
    {
        $source = ['data' => ['totalRecords' => 0]];
        $this->assertSame($source, $this->column()->prepareDataSource($source));
    }
}
