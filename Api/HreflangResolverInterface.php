<?php
declare(strict_types=1);

namespace Panth\Hreflang\Api;

/**
 * Resolves hreflang alternates for a given entity.
 */
interface HreflangResolverInterface
{
    /** Supported entity type constants. */
    public const ENTITY_PRODUCT  = 'product';
    public const ENTITY_CATEGORY = 'category';
    public const ENTITY_CMS      = 'cms';

    /**
     * @return array<int,array{locale:string,url:string,is_default:bool}>
     */
    public function getAlternates(string $entityType, int $entityId, int $storeId): array;

    /**
     * Validates reciprocity of a hreflang group.
     *
     * @return array<int,string> Error messages (empty if valid)
     */
    public function validateGroup(int $groupId): array;
}
