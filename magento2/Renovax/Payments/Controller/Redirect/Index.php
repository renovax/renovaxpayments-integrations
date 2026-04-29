<?php
declare(strict_types=1);

namespace Renovax\Payments\Controller\Redirect;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Renovax\Payments\Model\Api\Client;

/**
 * Redirects the customer to the RENOVAX hosted checkout (pay_url) right
 * after the order is placed.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RedirectFactory $redirectFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly EncryptorInterface $encryptor,
        private readonly RequestInterface $request,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $redirect = $this->redirectFactory->create();

        try {
            $order = $this->checkoutSession->getLastRealOrder();
            if (!$order || !$order->getId()) {
                return $redirect->setPath('checkout/cart');
            }

            $store    = $this->storeManager->getStore();
            $apiBase  = (string) $this->scopeConfig->getValue('payment/renovax/api_base_url');
            $token    = $this->encryptor->decrypt((string) $this->scopeConfig->getValue('payment/renovax/bearer_token'));
            $ttl      = (int) ($this->scopeConfig->getValue('payment/renovax/invoice_ttl_minutes') ?: 15);

            $client   = new Client($apiBase, $token);
            $invoice  = $client->createInvoice([
                'amount'             => number_format((float) $order->getGrandTotal(), 2, '.', ''),
                'currency'           => $order->getOrderCurrencyCode(),
                'client_remote_id'   => (string) $order->getIncrementId(),
                'success_url'        => $store->getBaseUrl() . 'checkout/onepage/success',
                'cancel_url'         => $store->getBaseUrl() . 'checkout/onepage/failure',
                'expires_in_minutes' => max(1, min(1440, $ttl)),
                'metadata' => [
                    'magento_order_id'        => (string) $order->getId(),
                    'magento_order_increment' => (string) $order->getIncrementId(),
                    'magento_email'           => (string) $order->getCustomerEmail(),
                    'magento_store'           => (string) $store->getCode(),
                ],
            ]);

            $order->setData('renovax_invoice_id', $invoice['id']);
            $order->addCommentToStatusHistory(
                __('Awaiting RENOVAX payment (invoice %1).', $invoice['id'])->render()
            );
            $this->orderRepository->save($order);

            return $redirect->setUrl($invoice['pay_url']);
        } catch (\Throwable $e) {
            $this->logger->error('[renovax] redirect failed: ' . $e->getMessage());
            $this->checkoutSession->restoreQuote();
            return $redirect->setPath('checkout/cart');
        }
    }
}
