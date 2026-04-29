<?php
/**
 * RENOVAX Payments — REST API client.
 *
 * cURL wrapper targeting payments.renovax.net merchant API. Mirrors the
 * auth/error patterns used by the WooCommerce and DHRU Fusion integrations:
 *
 *   POST /api/v1/merchant/invoices            — create invoice
 *   GET  /api/v1/merchant/invoices/{id}       — fetch invoice
 *   POST /api/v1/merchant/invoices/{id}/refund — refund
 *
 * Errors raise RenovaxApiException with a stable code so the caller can
 * branch (renovax_auth, renovax_validation, renovax_http, renovax_json,
 * renovax_transport, renovax_no_token).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class RenovaxApiException extends Exception
{
    public $renovaxCode;
    public $httpStatus;
    public $apiData;

    public function __construct($renovaxCode, $message, $httpStatus = 0, $apiData = null)
    {
        parent::__construct($message);
        $this->renovaxCode = (string) $renovaxCode;
        $this->httpStatus  = (int) $httpStatus;
        $this->apiData     = $apiData;
    }
}

class RenovaxApiClient
{
    private $apiBase;
    private $token;

    public function __construct($apiBase = null, $token = null)
    {
        $this->apiBase = rtrim((string) ($apiBase !== null ? $apiBase : Configuration::get('RENOVAX_API_BASE_URL')), '/');
        if ($this->apiBase === '') {
            $this->apiBase = 'https://payments.renovax.net';
        }
        $this->token = trim((string) ($token !== null ? $token : Configuration::get('RENOVAX_BEARER_TOKEN')));
    }

    public function createInvoice(array $payload)
    {
        return $this->request('POST', '/api/v1/merchant/invoices', $payload);
    }

    public function getInvoice($invoiceId)
    {
        return $this->request('GET', '/api/v1/merchant/invoices/' . rawurlencode((string) $invoiceId));
    }

    public function refundInvoice($invoiceId, $amount = null, $reason = null)
    {
        $body = array();
        if ($amount !== null && (float) $amount > 0) {
            $body['amount'] = (string) $amount;
        }
        if ($reason !== null && $reason !== '') {
            $body['reason'] = (string) $reason;
        }
        return $this->request('POST', '/api/v1/merchant/invoices/' . rawurlencode((string) $invoiceId) . '/refund', $body);
    }

    private function request($method, $path, ?array $body = null)
    {
        if ($this->token === '') {
            throw new RenovaxApiException(
                'renovax_no_token',
                'Bearer Token is not configured.'
            );
        }

        $url    = $this->apiBase . $path;
        $shop   = function_exists('Tools::getShopDomainSsl') ? Tools::getShopDomainSsl(true, true) : '';
        $userAg = 'RenovaxPS/' . (defined('RENOVAX_PS_VERSION') ? constant('RENOVAX_PS_VERSION') : '1.0.0') . '; ' . $shop;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RenovaxApiException('renovax_transport', 'Could not initialise cURL.');
        }

        $headers = array(
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: ' . $userAg,
        );

        $opts = array(
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => false,
        );

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
        }

        curl_setopt_array($ch, $opts);

        $raw       = curl_exec($ch);
        $code      = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlErr   = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $curlErrno !== 0) {
            RenovaxLogger::error('transport error: ' . $curlErr . ' (errno=' . $curlErrno . ') url=' . $url);
            throw new RenovaxApiException(
                'renovax_transport',
                'RENOVAX Payments could not be reached. Please try again later.'
            );
        }

        $data = json_decode((string) $raw, true);

        if ($code === 401 || $code === 403) {
            RenovaxLogger::error('auth failed: code=' . $code . ' body=' . Tools::substr((string) $raw, 0, 500));
            throw new RenovaxApiException(
                'renovax_auth',
                'RENOVAX Payments authentication failed. Verify the Bearer Token.',
                $code,
                $data
            );
        }

        if ($code === 422) {
            $msg = is_array($data) && !empty($data['message']) ? (string) $data['message'] : 'Unprocessable request.';
            RenovaxLogger::warning('validation rejected: ' . $msg);
            throw new RenovaxApiException('renovax_validation', $msg, $code, $data);
        }

        if ($code < 200 || $code >= 300) {
            RenovaxLogger::error('unexpected status: code=' . $code . ' body=' . Tools::substr((string) $raw, 0, 500));
            throw new RenovaxApiException(
                'renovax_http',
                'RENOVAX Payments returned an unexpected error. Please try again later.',
                $code,
                $data
            );
        }

        if (!is_array($data)) {
            RenovaxLogger::error('invalid JSON response: ' . Tools::substr((string) $raw, 0, 500));
            throw new RenovaxApiException(
                'renovax_json',
                'RENOVAX Payments returned an invalid response.',
                $code
            );
        }

        return $data;
    }
}
