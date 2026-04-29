<?php
declare(strict_types=1);

namespace Renovax\Payments\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;

class ConfigProvider implements ConfigProviderInterface
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly UrlInterface $url
    ) {
    }

    public function getConfig(): array
    {
        return [
            'payment' => [
                'renovax' => [
                    'title'        => (string) $this->scopeConfig->getValue('payment/renovax/title'),
                    'description'  => (string) $this->scopeConfig->getValue('payment/renovax/description'),
                    'redirect_url' => $this->url->getUrl('renovax/redirect'),
                ],
            ],
        ];
    }
}
