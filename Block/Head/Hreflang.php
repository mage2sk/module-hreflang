<?php
declare(strict_types=1);

namespace Panth\Hreflang\Block\Head;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Panth\Hreflang\ViewModel\Hreflang as HreflangViewModel;

/**
 * Storefront head block that renders hreflang `<link rel="alternate">` tags.
 */
class Hreflang extends Template
{
    public function __construct(
        Context $context,
        private readonly HreflangViewModel $viewModel,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Whether hreflang emission is active for the current store.
     */
    public function isEnabled(): bool
    {
        return $this->viewModel->isEnabled();
    }

    /**
     * @return array<int,array{locale:string,url:string,is_default:bool}>
     */
    public function getAlternates(): array
    {
        return $this->viewModel->getAlternates();
    }
}
