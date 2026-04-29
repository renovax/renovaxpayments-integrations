<?php
declare(strict_types=1);

namespace Renovax\Payments\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;

/**
 * RENOVAX Payments — minimal API client.
 * Mirrors the auth/error pattern used by the Dhru and WooCommerce integrations.
 */
class Client
{
    private string $apiBase;
    private string $token;

    public function __construct(string $apiBase, string $token)
    {
        $this->apiBase = rtrim($apiBase ?: 'https://payments.renovax.net', '/');
        $this->token   = trim($token);
    }

    public function createInvoice(array $payload): array
    {
        return $this->request('POST', '/api/v1/merchant/invoices', $payload);
    }

    public function getInvoice(string $id): array
    {
        return $this->request('GET', '/api/v1/merchant/invoices/' . rawurlencode($id));
    }

    public function refundInvoice(string $id, ?float $amount = null, ?string $reason = null): array
    {
        $body = [];
        if ($amount !== null) {
            $body['amount'] = number_format($amount, 2, '.', '');
        }
        if ($reason !== null && $reason !== '') {
            $body['reason'] = $reason;
        }
        return $this->request('POST', '/api/v1/merchant/invoices/' . rawurlencode($id) . '/refund', $body);
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        if ($this->token === '') {
            throw new LocalizedException(__('RENOVAX bearer token is not configured.'));
        }

        $curl = new Curl();
        $curl->setOption(CURLOPT_TIMEOUT, 15);
        $curl->setOption(CURLOPT_CUSTOMREQUEST, $method);
        $curl->addHeader('Authorization', 'Bearer ' . $this->token);
        $curl->addHeader('Accept', 'application/json');
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('User-Agent', 'RenovaxMagento/1.0');

        $url = $this->apiBase . $path;

        if ($body !== null) {
            $curl->post($url, json_encode($body, JSON_UNESCAPED_SLASHES));
        } else {
            $curl->get($url);
        }

        $status = (int) $curl->getStatus();
        $raw    = (string) $curl->getBody();
        $data   = json_decode($raw, true);

        if ($status === 401 || $status === 403) {
            throw new LocalizedException(__('RENOVAX authentication failed. Verify the Bearer Token.'));
        }
        if ($status === 422) {
            $msg = is_array($data) && !empty($data['message']) ? $data['message'] : 'Unprocessable request';
            throw new LocalizedException(__('RENOVAX rejected the request: %1', $msg));
        }
        if ($status < 200 || $status >= 300) {
            throw new LocalizedException(__('RENOVAX returned an unexpected error (%1).', $status));
        }
        if (!is_array($data)) {
            throw new LocalizedException(__('RENOVAX returned an invalid response.'));
        }
        return $data;
    }
}
