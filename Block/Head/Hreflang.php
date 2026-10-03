<?php
declare(strict_types=1);

namespace Panth\Hreflang\Block\Head;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Panth\Hreflang\ViewModel\Hreflang as HreflangViewModel;

class Hreflang extends Template
{
    public function __construct(
        Context $context,
        private readonly HreflangViewModel $viewModel,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->viewModel->isEnabled();
    }

    public function getAlternates(): array
    {
        return $this->viewModel->getAlternates();
    }
}
