<?php
declare(strict_types=1);

namespace Renovax\Payments\Controller\Webhook;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\Transaction;

/**
 * Receives signed webhooks from RENOVAX and updates the matching order.
 *
 * Endpoint: POST {base_url}renovax/webhook
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly CacheInterface $cache,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly InvoiceService $invoiceService,
        private readonly Transaction $transaction,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $body   = (string) $this->request->getContent();

        if ($body === '') {
            return $result->setHttpResponseCode(400)->setData(['ok' => false, 'error' => 'empty_body']);
        }

        $secret = $this->encryptor->decrypt((string) $this->scopeConfig->getValue('payment/renovax/webhook_secret'));
        if ($secret === '') {
            return $result->setHttpResponseCode(500)->setData(['ok' => false, 'error' => 'webhook_secret_not_configured']);
        }

        $providedSig = str_replace('sha256=', '', (string) $this->request->getHeader('X-Renovax-Signature'));
        $expectedSig = hash_hmac('sha256', $body, $secret);

        if ($providedSig === '' || !hash_equals($expectedSig, $providedSig)) {
            return $result->setHttpResponseCode(401)->setData(['ok' => false, 'error' => 'invalid_signature']);
        }

        $event = json_decode($body, true);
        if (!is_array($event)) {
            return $result->setHttpResponseCode(400)->setData(['ok' => false, 'error' => 'invalid_json']);
        }

        $eventId = (string) $this->request->getHeader('X-Renovax-Event-Id');
        if ($eventId !== '') {
            $cacheKey = 'renovax_evt_' . md5($eventId);
            if ($this->cache->load($cacheKey)) {
                return $result->setData(['ok' => true, 'duplicate' => true]);
            }
            $this->cache->save('1', $cacheKey, [], 86400);
        }

        $eventType = (string) ($this->request->getHeader('X-Renovax-Event-Type') ?: ($event['event_type'] ?? ''));
        $invoiceId = (string) ($event['invoice_id'] ?? '');
        $order     = $this->findOrder($event, $invoiceId);

        if (!$order) {
            return $result->setData(['ok' => false, 'error' => 'order_not_found']);
        }

        try {
            switch ($eventType) {
                case 'invoice.paid':
                case 'invoice.overpaid':
                    $this->markPaid($order, $event, $eventType === 'invoice.overpaid');
                    break;
                case 'invoice.partial':
                    $order->addCommentToStatusHistory(
                        __('RENOVAX partial payment received (%1). Manual review required.', $event['amount_received_fiat'] ?? '')->render()
                    )->setStatus(Order::STATE_HOLDED)->setState(Order::STATE_HOLDED);
                    $this->orderRepository->save($order);
                    break;
                case 'invoice.expired':
                    if (!$order->isCanceled() && !$order->hasInvoices()) {
                        $order->cancel();
                        $order->addCommentToStatusHistory(__('RENOVAX invoice expired without payment.')->render());
                        $this->orderRepository->save($order);
                    }
                    break;
                default:
                    return $result->setData(['ok' => true, 'ignored' => $eventType]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[renovax] webhook failure: ' . $e->getMessage());
            return $result->setHttpResponseCode(500)->setData(['ok' => false, 'error' => 'internal']);
        }

        return $result->setData(['ok' => true, 'event' => $eventType, 'order' => $order->getIncrementId()]);
    }

    private function findOrder(array $event, string $invoiceId): ?Order
    {
        $incr = $event['metadata']['magento_order_increment'] ?? null;
        if ($incr) {
            $col = $this->orderCollectionFactory->create()->addFieldToFilter('increment_id', $incr)->setPageSize(1);
            $order = $col->getFirstItem();
            if ($order && $order->getId()) {
                return $order;
            }
        }

        if ($invoiceId !== '') {
            $col = $this->orderCollectionFactory->create()->addFieldToFilter('renovax_invoice_id', $invoiceId)->setPageSize(1);
            $order = $col->getFirstItem();
            if ($order && $order->getId()) {
                return $order;
            }
        }

        return null;
    }

    private function markPaid(Order $order, array $event, bool $overpaid): void
    {
        if ($order->hasInvoices() || $order->getState() === Order::STATE_COMPLETE) {
            $order->addCommentToStatusHistory(__('RENOVAX webhook received but order is already paid — no action.')->render());
            $this->orderRepository->save($order);
            return;
        }

        if (!$order->canInvoice()) {
            return;
        }

        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
        $invoice->setTransactionId($event['tx_hash'] ?? $event['invoice_id'] ?? '');
        $invoice->register();

        $this->transaction->addObject($invoice)->addObject($invoice->getOrder())->save();

        $note = $overpaid
            ? __('RENOVAX OVERPAID: gross %1 %2, net %3, fee %4.',
                $event['amount_received_fiat'] ?? '',
                $event['invoice_currency'] ?? $order->getOrderCurrencyCode(),
                $event['amount_net_fiat'] ?? '',
                $event['fee'] ?? '')
            : __('RENOVAX paid: gross %1 %2, net %3, fee %4.',
                $event['amount_received_fiat'] ?? '',
                $event['invoice_currency'] ?? $order->getOrderCurrencyCode(),
                $event['amount_net_fiat'] ?? '',
                $event['fee'] ?? '');

        $order->addCommentToStatusHistory($note->render());
        $order->setState(Order::STATE_PROCESSING)->setStatus(Order::STATE_PROCESSING);
        $this->orderRepository->save($order);
    }
}
