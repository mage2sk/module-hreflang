<?php
declare(strict_types=1);

namespace Panth\Hreflang\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class CmsRelationMethod implements OptionSourceInterface
{
    public const BY_ID         = 'by_id';
    public const BY_URL_KEY    = 'by_url_key';
    public const BY_IDENTIFIER = 'by_identifier';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::BY_ID,         'label' => __('Same Page ID')],
            ['value' => self::BY_URL_KEY,    'label' => __('Same URL Key')],
            ['value' => self::BY_IDENTIFIER, 'label' => __('By Hreflang Identifier')],
        ];
    }
}
