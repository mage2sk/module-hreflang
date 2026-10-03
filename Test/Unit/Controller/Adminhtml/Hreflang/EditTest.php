<?php
declare(strict_types=1);

namespace Panth\Hreflang\Test\Unit\Controller\Adminhtml\Hreflang;

use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Panth\Hreflang\Controller\Adminhtml\Hreflang\Edit;

class EditTest extends ControllerTestCase
{
    private array $titles = [];
    private array $registered = [];

    private function edit(array $params, $row): Edit
    {
        $title = $this->createStub(Title::class);
        $title->method('prepend')->willReturnCallback(function ($t) {
            $this->titles[] = (string) $t;
        });
        $config = $this->createStub(Config::class);
        $config->method('getTitle')->willReturn($title);
        $page = $this->createStub(Page::class);
        $page->method('getConfig')->willReturn($config);
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($page);

        $registry = $this->createStub(Registry::class);
        $registry->method('register')->willReturnCallback(function ($key, $value) {
            $this->registered[$key] = $value;
        });

        $connection = $this->connectionStub();
        $connection->method('fetchRow')->willReturn($row);

        return new Edit($this->context($params), $factory, $registry, $this->resourceStub($connection));
    }

    public function testExistingGroupIsRegisteredWithEditTitle(): void
    {
        $this->edit(['id' => '3'], ['group_id' => 3, 'code' => 'shoes'])->execute();

        $this->assertSame(['group_id' => 3, 'code' => 'shoes'], $this->registered['panth_hreflang_group']);
        $this->assertSame(['Edit Hreflang Group'], $this->titles);
        $this->assertSame(3, $this->whereValueFor('group_id = ?'));
    }

    public function testMissingRowRegistersEmptyArray(): void
    {
        $this->edit(['id' => '3'], false)->execute();

        $this->assertSame([], $this->registered['panth_hreflang_group']);
    }

    public function testNewGroupSkipsLookup(): void
    {
        $this->edit([], ['group_id' => 1])->execute();

        $this->assertSame([], $this->registered['panth_hreflang_group']);
        $this->assertSame(['New Hreflang Group'], $this->titles);
        $this->assertSame([], $this->wheres);
    }
}
