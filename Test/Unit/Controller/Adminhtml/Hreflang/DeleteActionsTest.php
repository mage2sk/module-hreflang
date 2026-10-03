<?php
declare(strict_types=1);

namespace Panth\Hreflang\Test\Unit\Controller\Adminhtml\Hreflang;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Ui\Component\MassAction\Filter;
use Panth\Hreflang\Controller\Adminhtml\Hreflang\Delete;
use Panth\Hreflang\Controller\Adminhtml\Hreflang\MassDelete;
use Panth\Hreflang\Model\ResourceModel\HreflangGroup\Collection;
use Panth\Hreflang\Model\ResourceModel\HreflangGroup\CollectionFactory;

class DeleteActionsTest extends ControllerTestCase
{
    private function massDelete(array $ids, AdapterInterface $connection): MassDelete
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getAllIds')->willReturn($ids);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $filter = $this->createStub(Filter::class);
        $filter->method('getCollection')->willReturnArgument(0);

        return new MassDelete($this->context(), $this->resourceStub($connection), $filter, $factory);
    }

    public function testDeleteRemovesTheGroup(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())
            ->method('delete')
            ->with('panth_seo_hreflang_group', ['group_id = ?' => 4])
            ->willReturn(1);

        (new Delete($this->context(['id' => '4']), $this->resourceStub($connection)))->execute();

        $this->assertSame(['Hreflang group deleted.'], $this->messages['success']);
        $this->assertSame(['*/*/', []], $this->redirect);
    }

    public function testDeleteWithoutIdDoesNothing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('delete');

        (new Delete($this->context(), $this->resourceStub($connection)))->execute();

        $this->assertSame([], $this->messages['success']);
        $this->assertSame(['*/*/', []], $this->redirect);
    }

    public function testDeleteFailureIsReported(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('delete')->willThrowException(new \RuntimeException('locked'));

        (new Delete($this->context(['id' => 4]), $this->resourceStub($connection)))->execute();

        $this->assertSame(['locked'], $this->messages['error']);
    }

    public function testMassDeleteRemovesSelectedIds(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())
            ->method('delete')
            ->with('panth_seo_hreflang_group', ['group_id IN (?)' => [3, 5]])
            ->willReturn(2);

        $this->massDelete(['3', '5'], $connection)->execute();

        $this->assertSame(['A total of 2 record(s) have been deleted.'], $this->messages['success']);
        $this->assertSame(['*/*/', []], $this->redirect);
    }

    public function testMassDeleteWithoutSelectionShowsError(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('delete');

        $this->massDelete([], $connection)->execute();

        $this->assertSame(['Please select at least one group.'], $this->messages['error']);
    }

    public function testMassDeleteFailureIsReported(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('delete')->willThrowException(new \RuntimeException('fk'));

        $this->massDelete([1], $connection)->execute();

        $this->assertSame(['fk'], $this->messages['error']);
        $this->assertSame([], $this->messages['success']);
    }

    public function testAclUsesTheModuleResource(): void
    {
        $this->allowedResources = ['Panth_Hreflang::hreflang'];
        $delete = new Delete($this->context(), $this->resourceStub($this->connectionStub()));
        $method = new \ReflectionMethod($delete, '_isAllowed');

        $this->assertTrue($method->invoke($delete));
        $this->allowedResources = [];
        $this->assertFalse($method->invoke($delete));
    }
}
