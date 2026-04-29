<?php
/**
 * RENOVAX Payments — REST API client.
 *
 * Thin wrapper around wp_remote_* targeting payments.renovax.net merchant API.
 * Mirrors the auth/error patterns used by the Dhru Fusion integration.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Renovax_API_Client
{
    private $api_base;
    private $token;
    private $logger;

    public function __construct($api_base, $token, $logger = null)
    {
        $this->api_base = rtrim($api_base ?: 'https://payments.renovax.net', '/');
        $this->token    = trim((string) $token);
        $this->logger   = $logger;
    }

    public function create_invoice(array $payload)
    {
        return $this->request('POST', '/api/v1/merchant/invoices', $payload);
    }

    public function get_invoice($invoice_id)
    {
        return $this->request('GET', '/api/v1/merchant/invoices/' . rawurlencode($invoice_id));
    }

    public function refund_invoice($invoice_id, $amount = null, $reason = null)
    {
        $body = [];
        if ($amount !== null) {
            $body['amount'] = (string) $amount;
        }
        if ($reason !== null && $reason !== '') {
            $body['reason'] = (string) $reason;
        }
        return $this->request('POST', '/api/v1/merchant/invoices/' . rawurlencode($invoice_id) . '/refund', $body);
    }

    private function request($method, $path, ?array $body = null)
    {
        if ($this->token === '') {
            return new WP_Error('renovax_no_token', __('Bearer Token is not configured.', 'renovaxpayments'));
        }

        $args = [
            'method'  => $method,
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'RenovaxWC/' . RENOVAXPAYMENTS_VERSION . '; ' . home_url('/'),
            ],
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $url      = $this->api_base . $path;
        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->log('error', 'HTTP transport error', [
                'url'   => $url,
                'error' => $response->get_error_message(),
            ]);
            return new WP_Error(
                'renovax_transport',
                __('RENOVAX Payments could not be reached. Please try again later.', 'renovaxpayments')
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($code === 401 || $code === 403) {
            $this->log('error', 'Auth failed', ['code' => $code, 'body' => $raw]);
            return new WP_Error(
                'renovax_auth',
                __('RENOVAX Payments authentication failed. Verify the Bearer Token.', 'renovaxpayments'),
                ['status' => $code]
            );
        }

        if ($code === 422) {
            $msg = is_array($data) && !empty($data['message'])
                ? $data['message']
                : __('Unprocessable request.', 'renovaxpayments');
            $this->log('warning', 'Validation rejected', ['code' => $code, 'body' => $raw]);
            return new WP_Error('renovax_validation', $msg, ['status' => $code, 'data' => $data]);
        }

        if ($code < 200 || $code >= 300) {
            $this->log('error', 'Unexpected status', ['code' => $code, 'body' => $raw]);
            return new WP_Error(
                'renovax_http',
                __('RENOVAX Payments returned an unexpected error. Please try again later.', 'renovaxpayments'),
                ['status' => $code]
            );
        }

        if (!is_array($data)) {
            $this->log('error', 'Invalid JSON response', ['body' => substr($raw, 0, 500)]);
            return new WP_Error(
                'renovax_json',
                __('RENOVAX Payments returned an invalid response.', 'renovaxpayments')
            );
        }

        return $data;
    }

    private function log($level, $message, array $context = [])
    {
        if ($this->logger && method_exists($this->logger, $level)) {
            $this->logger->{$level}('[renovax] ' . $message . ' ' . wp_json_encode($context), ['source' => 'renovaxpayments']);
        }
    }
}
