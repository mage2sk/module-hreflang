<?php
declare(strict_types=1);

namespace Panth\Hreflang\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class HreflangScope implements OptionSourceInterface
{
    public const SCOPE_WEBSITE = 'website';
    public const SCOPE_GLOBAL  = 'global';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::SCOPE_WEBSITE, 'label' => __('Within Same Website')],
            ['value' => self::SCOPE_GLOBAL,  'label' => __('Across All Websites')],
        ];
    }
}
