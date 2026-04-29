<?php
declare(strict_types=1);

namespace Renovax\Payments\Model;

use Magento\Payment\Model\Method\AbstractMethod;

/**
 * RENOVAX Payments — offline-style payment method that redirects to a
 * hosted checkout. The actual capture is performed asynchronously by the
 * webhook controller (Renovax\Payments\Controller\Webhook\Index).
 */
class Payment extends AbstractMethod
{
    protected $_code = 'renovax';
    protected $_canOrder = true;
    protected $_canAuthorize = false;
    protected $_canCapture = false;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_isOffline = false;
    protected $_isInitializeNeeded = true;
    protected $_canUseInternal = false;

    public function getOrderPlaceRedirectUrl()
    {
        return 'renovax/redirect';
    }

    public function initialize($paymentAction, $stateObject)
    {
        $status = $this->getConfigData('order_status') ?: 'pending_payment';
        $stateObject->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus($status);
        $stateObject->setIsNotified(false);
        return $this;
    }

    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $order      = $payment->getOrder();
        $invoiceId  = (string) $order->getData('renovax_invoice_id');

        if ($invoiceId === '') {
            throw new \Magento\Framework\Exception\LocalizedException(__('No RENOVAX invoice associated with this order.'));
        }

        $client = new \Renovax\Payments\Model\Api\Client(
            (string) $this->getConfigData('api_base_url'),
            (string) $this->getConfigData('bearer_token')
        );
        $client->refundInvoice($invoiceId, (float) $amount, (string) $payment->getCreditmemo()?->getCustomerNote());

        return $this;
    }
}
