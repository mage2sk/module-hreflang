<?php
declare(strict_types=1);

namespace Panth\Hreflang\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class EntityType implements OptionSourceInterface
{
    public const PRODUCT  = 'product';
    public const CATEGORY = 'category';
    public const CMS_PAGE = 'cms_page';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::PRODUCT,  'label' => (string) __('Product')],
            ['value' => self::CATEGORY, 'label' => (string) __('Category')],
            ['value' => self::CMS_PAGE, 'label' => (string) __('CMS Page')],
        ];
    }
}
