<?php
declare(strict_types=1);

/**
 * Minimal HTTP client for the RENOVAX Payments merchant API.
 * Same auth/error pattern used across the other integrations.
 */
class RenovaxClient
{
    private string $apiBase;
    private string $token;

    public function __construct(string $apiBase, string $token)
    {
        $this->apiBase = rtrim($apiBase ?: 'https://payments.renovax.net', '/');
        $this->token   = trim($token);
    }

    /**
     * @return array{ok:bool, status:int, data:?array, error?:string}
     */
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
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'bearer_token_not_configured'];
        }

        $ch = curl_init($this->apiBase . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: RenovaxWebX/1.0',
            ],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw     = curl_exec($ch);
        $errno   = curl_errno($ch);
        $errMsg  = curl_error($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            return [
                'ok' => false, 'status' => 0, 'data' => null,
                'error' => 'transport: ' . $errMsg,
            ];
        }

        $data = json_decode((string) $raw, true);

        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'status' => $status, 'data' => $data, 'error' => 'auth_failed'];
        }
        if ($status === 422) {
            $msg = is_array($data) && !empty($data['message']) ? $data['message'] : 'Unprocessable request';
            return ['ok' => false, 'status' => $status, 'data' => $data, 'error' => $msg];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'status' => $status, 'data' => $data, 'error' => 'http_' . $status];
        }
        if (!is_array($data)) {
            return ['ok' => false, 'status' => $status, 'data' => null, 'error' => 'invalid_json'];
        }

        return ['ok' => true, 'status' => $status, 'data' => $data];
    }
}

function rx_renovax(): RenovaxClient
{
    static $c = null;
    if ($c instanceof RenovaxClient) {
        return $c;
    }
    global $RX_CFG;
    $c = new RenovaxClient($RX_CFG['renovax']['api_base'], $RX_CFG['renovax']['bearer_token']);
    return $c;
}
