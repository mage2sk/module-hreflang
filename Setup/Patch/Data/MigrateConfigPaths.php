<?php
declare(strict_types=1);

namespace Panth\Hreflang\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class MigrateConfigPaths implements DataPatchInterface
{
    private const LEGACY_PREFIX = 'panth_seo/hreflang/';
    private const NEW_PREFIX    = 'panth_hreflang/hreflang/';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');

        $connection->update(
            $table,
            [
                'path' => new \Zend_Db_Expr(
                    sprintf(
                        'REPLACE(path, %s, %s)',
                        $connection->quote(self::LEGACY_PREFIX),
                        $connection->quote(self::NEW_PREFIX)
                    )
                ),
            ],
            $connection->quoteInto('path LIKE ?', self::LEGACY_PREFIX . '%')
        );

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
