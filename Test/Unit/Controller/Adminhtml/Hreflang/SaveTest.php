<?php
declare(strict_types=1);

namespace Panth\Hreflang\Test\Unit\Controller\Adminhtml\Hreflang;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Panth\Hreflang\Controller\Adminhtml\Hreflang\Save;

class SaveTest extends ControllerTestCase
{
    private const NOW = '2026-01-02 03:04:05';

    private array $inserts = [];
    private array $updates = [];
    private array $deletes = [];
    private array $finderQueries = [];

    private function connection(array $existingMemberIds = [], ?\Throwable $insertError = null): AdapterInterface
    {
        $connection = $this->connectionStub([], [], [$existingMemberIds], Mysql::class);
        $connection->method('insert')->willReturnCallback(function ($table, $row) use ($insertError) {
            if ($insertError !== null && $table === 'panth_seo_hreflang_group') {
                throw $insertError;
            }
            $this->inserts[] = [$table, $row];
            return 1;
        });
        $connection->method('update')->willReturnCallback(function ($table, $row, $where) {
            $this->updates[] = [$table, $row, $where];
            return 1;
        });
        $connection->method('delete')->willReturnCallback(function ($table, $where) {
            $this->deletes[] = [$table, $where];
            return 1;
        });
        $connection->method('lastInsertId')->willReturn('31');
        return $connection;
    }

    private function controller(
        array $post,
        array $params = [],
        ?AdapterInterface $connection = null,
        ?UrlRewrite $rewrite = null
    ): Save {
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn(self::NOW);

        $store = $this->createStub(Store::class);
        $store->method('getConfig')->willReturn('de_DE');
        $store->method('getBaseUrl')->willReturn('https://de.example.com/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(static function ($id) use ($store) {
            if ((int) $id === 404) {
                throw new NoSuchEntityException(__('missing'));
            }
            return $store;
        });

        $finder = $this->createStub(UrlFinderInterface::class);
        $finder->method('findOneByData')->willReturnCallback(function (array $data) use ($rewrite) {
            $this->finderQueries[] = $data;
            return $rewrite;
        });

        return new Save(
            $this->context($params, $post),
            $this->resourceStub($connection ?? $this->connection()),
            $dateTime,
            $storeManager,
            $finder
        );
    }

    private function memberInserts(): array
    {
        return array_values(array_map(
            static fn($i) => $i[1],
            array_filter($this->inserts, static fn($i) => $i[0] === 'panth_seo_hreflang_member')
        ));
    }

    public function testEmptyPostRedirectsToGrid(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('insert');

        $this->controller([], [], $connection)->execute();

        $this->assertSame(['*/*/', []], $this->redirect);
    }

    public function testUnknownEntityTypeIsRejected(): void
    {
        $this->controller(['code' => 'x', 'entity_type' => 'customer'])->execute();

        $this->assertSame(['Invalid entity type.'], $this->messages['error']);
        $this->assertSame(['*/*/', []], $this->redirect);
        $this->assertSame([], $this->inserts);
    }

    public function testMissingCodeReturnsToTheEditForm(): void
    {
        $this->controller(['code' => '   ', 'entity_type' => 'product'])->execute();
        $this->assertSame(['Group Code is required.'], $this->messages['error']);
        $this->assertSame(['*/*/edit', []], $this->redirect);

        $this->controller(['group_id' => '9', 'code' => '', 'entity_type' => 'product'])->execute();
        $this->assertSame(['*/*/edit', ['id' => 9]], $this->redirect);
    }

    public function testNewGroupIsInsertedWithTrimmedTruncatedCode(): void
    {
        $this->controller([
            'code' => '  ' . str_repeat('a', 70) . '  ',
            'entity_type' => 'category',
            'notes' => 'n',
        ])->execute();

        [$table, $row] = $this->inserts[0];
        $this->assertSame('panth_seo_hreflang_group', $table);
        $this->assertSame(str_repeat('a', 64), $row['code']);
        $this->assertSame('category', $row['entity_type']);
        $this->assertSame(1, $row['is_active']);
        $this->assertSame(self::NOW, $row['created_at']);
        $this->assertSame(self::NOW, $row['updated_at']);
        $this->assertSame(['Hreflang group saved.'], $this->messages['success']);
        $this->assertSame(['*/*/', []], $this->redirect);
    }

    public function testBackParamRedirectsToEditWithTheNewId(): void
    {
        $this->controller(['code' => 'g', 'entity_type' => 'product'], ['back' => 'edit'])->execute();

        $this->assertSame(['*/*/edit', ['id' => 31]], $this->redirect);
    }

    public function testMembersAreValidatedNormalisedAndInserted(): void
    {
        $this->controller([
            'code' => 'g',
            'entity_type' => 'product',
            'hreflang_members' => [
                ['store_id' => 1, 'entity_id' => 5, 'locale' => 'en-GB', 'url' => 'https://en.example.com/p', 'is_default' => '1'],
                ['store_id' => 2, 'entity_id' => 5, 'locale' => 'de-DE', 'url' => 'https://de.example.com/p', 'is_default' => 'yes'],
                ['store_id' => 3, 'entity_id' => 5, 'locale' => 'xx_bad', 'url' => 'https://fr.example.com/p'],
                ['store_id' => 3, 'entity_id' => 5, 'locale' => 'fr-FR', 'url' => 'ftp://fr.example.com/p'],
                ['store_id' => 0, 'entity_id' => 5, 'locale' => 'it-IT', 'url' => 'https://it.example.com/p'],
                ['store_id' => 4, 'entity_id' => 0, 'locale' => 'it-IT', 'url' => 'https://it.example.com/p'],
                ['store_id' => 4, 'entity_id' => 5, 'locale' => 'it-IT', 'url' => 'https://it.example.com/p', 'is_removed' => 1],
                'garbage',
            ],
        ])->execute();

        $members = $this->memberInserts();
        $this->assertCount(2, $members);
        $this->assertSame([
            'group_id' => 31,
            'store_id' => 1,
            'entity_type' => 'product',
            'entity_id' => 5,
            'locale' => 'en-GB',
            'url' => 'https://en.example.com/p',
            'is_default' => 1,
        ], $members[0]);
        $this->assertSame(0, $members[1]['is_default']);
        $this->assertSame(
            ['2 member row(s) were skipped because the locale or URL is invalid.'],
            $this->messages['warning']
        );
    }

    public function testExistingMembersAreUpdatedKeptOrDeleted(): void
    {
        $connection = $this->connection(['5', '6', '7']);
        $this->controller([
            'group_id' => '12',
            'code' => 'g',
            'entity_type' => 'product',
            'is_active' => '0',
            'hreflang_members' => [
                ['member_id' => '5', 'store_id' => 1, 'entity_id' => 5, 'locale' => 'en-GB', 'url' => 'https://en.example.com/p'],
                ['member_id' => '6', 'store_id' => 2, 'entity_id' => 5, 'locale' => 'bad locale', 'url' => 'https://de.example.com/p'],
                ['member_id' => '88', 'store_id' => 3, 'entity_id' => 5, 'locale' => 'fr-FR', 'url' => 'https://fr.example.com/p'],
            ],
        ], [], $connection)->execute();

        $this->assertSame('panth_seo_hreflang_group', $this->updates[0][0]);
        $this->assertSame(0, $this->updates[0][1]['is_active']);
        $this->assertSame(['group_id = ?' => 12], $this->updates[0][2]);
        $this->assertSame(['member_id = ?' => 5], $this->updates[1][2]);
        $this->assertSame('fr-FR', $this->memberInserts()[0]['locale']);
        $this->assertSame([['panth_seo_hreflang_member', ['member_id IN (?)' => [2 => 7]]]], $this->deletes);
    }

    public function testBlankLocaleAndUrlAreResolvedFromStoreAndRewrite(): void
    {
        $rewrite = $this->createStub(UrlRewrite::class);
        $rewrite->method('getRequestPath')->willReturn('/about-us');

        $this->controller([
            'code' => 'g',
            'entity_type' => 'cms_page',
            'hreflang_members' => [['store_id' => 2, 'entity_id' => 4, 'locale' => '', 'url' => '']],
        ], [], null, $rewrite)->execute();

        $member = $this->memberInserts()[0];
        $this->assertSame('de-DE', $member['locale']);
        $this->assertSame('https://de.example.com/about-us', $member['url']);
        $this->assertSame('cms-page', $this->finderQueries[0][UrlRewrite::ENTITY_TYPE]);
        $this->assertSame(4, $this->finderQueries[0][UrlRewrite::ENTITY_ID]);
    }

    public function testUnresolvableUrlSkipsTheRowSilently(): void
    {
        $this->controller([
            'code' => 'g',
            'entity_type' => 'product',
            'hreflang_members' => [
                ['store_id' => 2, 'entity_id' => 4, 'locale' => 'de-DE', 'url' => ''],
                ['store_id' => 404, 'entity_id' => 4, 'locale' => '', 'url' => 'https://x.example.com/'],
            ],
        ])->execute();

        $this->assertSame([], $this->memberInserts());
        $this->assertSame([], $this->messages['warning']);
        $this->assertSame('product', $this->finderQueries[0][UrlRewrite::ENTITY_TYPE]);
    }

    public function testDuplicateCodeReportsAndReturnsToEdit(): void
    {
        $connection = $this->connection([], new DuplicateException(__('dup')));

        $this->controller(['code' => 'shoes', 'entity_type' => 'product'], [], $connection)->execute();

        $this->assertSame(['A hreflang group with the code "shoes" already exists.'], $this->messages['error']);
        $this->assertSame(['*/*/edit', []], $this->redirect);
    }

    public function testUnexpectedErrorIsReportedAndRedirectsToGrid(): void
    {
        $connection = $this->connection([], new \RuntimeException('db gone'));

        $this->controller(['code' => 'shoes', 'entity_type' => 'product'], [], $connection)->execute();

        $this->assertSame(['db gone'], $this->messages['error']);
        $this->assertSame([], $this->messages['success']);
        $this->assertSame(['*/*/', []], $this->redirect);
    }
}
