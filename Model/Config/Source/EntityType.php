<?php
declare(strict_types=1);

namespace Panth\Hreflang\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Option source for the group Entity Type select. Drives both the form
 * dropdown and any validation that needs the canonical value list.
 */
class EntityType implements OptionSourceInterface
{
    public const PRODUCT  = 'product';
    public const CATEGORY = 'category';
    public const CMS_PAGE = 'cms_page';

    /**
     * @return array<int, array{value:string, label:string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::PRODUCT,  'label' => (string) __('Product')],
            ['value' => self::CATEGORY, 'label' => (string) __('Category')],
            ['value' => self::CMS_PAGE, 'label' => (string) __('CMS Page')],
        ];
    }
}
