<?php
declare(strict_types=1);

namespace Panth\Hreflang\Test\Unit\Model\Hreflang;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\Hreflang\Helper\Config;
use Panth\Hreflang\Model\Hreflang\Diagnostic;
use Panth\Hreflang\Test\Unit\DbStubTrait;
use PHPUnit\Framework\TestCase;

class DiagnosticTest extends TestCase
{
    use DbStubTrait;

    private function store(int $id, string $name): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('getName')->willReturn($name);
        return $store;
    }

    private function diagnostic(
        AdapterInterface $connection,
        array $locales = [],
        array $enabledStores = [],
        ?StoreManagerInterface $storeManager = null
    ): Diagnostic {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn($path, $scope = null, $code = null) => $locales[$code] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn($path, $scope = null, $code = null) => in_array($code, $enabledStores, true)
        );

        if ($storeManager === null) {
            $stores = [1 => $this->store(1, 'English'), 2 => $this->store(2, 'German')];
            $storeManager = $this->createStub(StoreManagerInterface::class);
            $storeManager->method('getStores')->willReturn(array_values($stores));
            $storeManager->method('getStore')->willReturnCallback(static function ($id) use ($stores) {
                if (!isset($stores[$id])) {
                    throw new NoSuchEntityException(__('missing'));
                }
                return $stores[$id];
            });
        }

        return new Diagnostic($this->resourceStub($connection), $storeManager, new Config($scopeConfig));
    }

    private function types(array $issues): array
    {
        return array_column($issues, 'type');
    }

    public function testHealthySetupReportsNoIssues(): void
    {
        $connection = $this->connectionStub([], [[], [], []], [['1', '2']]);
        $connection->method('isTableExists')->willReturn(true);

        $issues = $this->diagnostic($connection, [1 => 'en_GB', 2 => 'de_DE'], [1, 2])->runDiagnostics(1);

        $this->assertSame([], $issues);
    }

    public function testStoresWithoutLocaleAreErrors(): void
    {
        $connection = $this->connectionStub();
        $connection->method('isTableExists')->willReturn(false);

        $issues = $this->diagnostic($connection, [1 => 'en_GB'])->runDiagnostics(1);

        $this->assertSame(['locale'], $this->types($issues));
        $this->assertSame('error', $issues[0]['severity']);
        $this->assertStringContainsString('Store "German" (ID 2) has no locale configured', $issues[0]['message']);
    }

    public function testStoreListFailureSkipsTheLocaleCheck(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willThrowException(new \RuntimeException('boom'));
        $connection = $this->connectionStub();
        $connection->method('isTableExists')->willReturn(false);

        $this->assertSame([], $this->diagnostic($connection, [], [], $storeManager)->runDiagnostics(1));
    }

    public function testMissingTablesSkipAllGroupChecks(): void
    {
        // Queued rows would surface as issues if any group query ran.
        $connection = $this->connectionStub(
            [],
            [[['group_id' => '4', 'code' => 'x']], [['group_id' => '4', 'code' => 'x', 'member_count' => 0]]],
            [['2']]
        );
        $connection->method('isTableExists')->willReturn(false);

        $issues = $this->diagnostic($connection, [1 => 'en_GB', 2 => 'de_DE'])->runDiagnostics(1);

        $this->assertSame([], $issues);
    }

    public function testGroupProblemsAreReportedInCheckOrder(): void
    {
        $connection = $this->connectionStub(
            [],
            [
                [['group_id' => '4', 'code' => 'shoes']],
                [['group_id' => '5', 'code' => 'bags', 'member_count' => '1']],
                [['group_id' => '6', 'code' => 'hats', 'locale' => 'en-GB', 'dup_count' => '2']],
            ],
            [['2', '9']]
        );
        $connection->method('isTableExists')->willReturn(true);

        $issues = $this->diagnostic($connection, [1 => 'en_GB', 2 => 'de_DE'], [1])->runDiagnostics(1);

        $this->assertSame(['x_default', 'orphan_group', 'conflict', 'config', 'config'], $this->types($issues));
        $this->assertSame(
            'Group "shoes" (ID 4) has no x-default member. Search engines may choose an arbitrary default.',
            $issues[0]['message']
        );
        $this->assertStringContainsString('Group "bags" (ID 5) has only 1 member(s)', $issues[1]['message']);
        $this->assertSame('error', $issues[2]['severity']);
        $this->assertStringContainsString('has 2 members with locale "en-GB"', $issues[2]['message']);
        $this->assertStringContainsString('Store "German" (ID 2)', $issues[3]['message']);
        $this->assertStringContainsString('Store "Unknown" (ID 9)', $issues[4]['message']);
        $this->assertSame('warning', $issues[4]['severity']);
    }
}
