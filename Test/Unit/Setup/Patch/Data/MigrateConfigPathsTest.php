<?php
declare(strict_types=1);

namespace Panth\Hreflang\Test\Unit\Setup\Patch\Data;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Panth\Hreflang\Setup\Patch\Data\MigrateConfigPaths;
use PHPUnit\Framework\TestCase;

class MigrateConfigPathsTest extends TestCase
{
    public function testLegacyPathsAreRewrittenToTheNewPrefix(): void
    {
        $captured = [];
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quote')->willReturnCallback(static fn($v) => "'" . $v . "'");
        $connection->method('quoteInto')->willReturnCallback(
            static fn($text, $value) => str_replace('?', "'" . $value . "'", $text)
        );
        $connection->method('update')->willReturnCallback(
            static function ($table, $bind, $where) use (&$captured) {
                $captured = [$table, (string) $bind['path'], $where];
                return 2;
            }
        );
        $setup = $this->createStub(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnArgument(0);

        $patch = new MigrateConfigPaths($setup);

        $this->assertSame($patch, $patch->apply());
        $this->assertSame([
            'core_config_data',
            "REPLACE(path, 'panth_seo/hreflang/', 'panth_hreflang/hreflang/')",
            "path LIKE 'panth_seo/hreflang/%'",
        ], $captured);
        $this->assertSame([], MigrateConfigPaths::getDependencies());
        $this->assertSame([], $patch->getAliases());
    }
}
