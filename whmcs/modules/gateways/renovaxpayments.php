<?php
/**
 * RENOVAX Payments — WHMCS Gateway Module.
 *
 * Server path:
 *   /modules/gateways/renovaxpayments.php
 *
 * WHMCS Gateway Module API: https://developers.whmcs.com/payment-gateways/
 *
 * Implements:
 *   renovaxpayments_MetaData()  — module metadata
 *   renovaxpayments_config()    — admin configuration fields
 *   renovaxpayments_link()      — payment button (off-site redirect to RENOVAX)
 *   renovaxpayments_refund()    — refund handler (admin-triggered)
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

// ---------------------------------------------------------------------------
// Module metadata.
// ---------------------------------------------------------------------------
function renovaxpayments_MetaData()
{
    return [
        'DisplayName'                 => 'RENOVAX Payments',
        'APIVersion'                  => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'            => false,
        'RefundSupported'             => true,
    ];
}

// ---------------------------------------------------------------------------
// Admin configuration fields.
// Rendered at: Setup -> Apps & Integrations -> Payment Gateways -> RENOVAX Payments.
// ---------------------------------------------------------------------------
function renovaxpayments_config()
{
    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'RENOVAX Payments — Crypto, Stripe, PayPal & more',
        ],
        'api_base_url' => [
            'FriendlyName' => 'API Base URL',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => 'https://payments.renovax.net',
            'Description'  => 'Multi-platform payment gateway. Single checkout with Crypto (USDT, USDC, EURC, DAI on BSC, Ethereum, Polygon, Arbitrum, Base, Optimism, Avalanche, Tron, Solana...), Stripe, PayPal and more. Leave the default in production.',
        ],
        'bearer_token' => [
            'FriendlyName' => 'Bearer Token',
            'Type'         => 'password',
            'Size'         => '80',
            'Description'  => 'Merchant token in RENOVAX. Generate it at: Merchants -> Edit -> API Tokens -> Create. Shown only once — copy and paste here without spaces.',
        ],
        'webhook_secret' => [
            'FriendlyName' => 'Webhook Secret',
            'Type'         => 'password',
            'Size'         => '80',
            'Description'  => 'Merchant HMAC secret (visible on the merchant edit page in RENOVAX). Validates the X-Renovax-Signature header on incoming webhooks. Also register the webhook URL pointing to: https://YOUR-WHMCS/modules/gateways/callback/renovaxpayments.php',
        ],
        'invoice_ttl_minutes' => [
            'FriendlyName' => 'Invoice TTL (minutes)',
            'Type'         => 'text',
            'Size'         => '6',
            'Default'      => '15',
            'Description'  => 'How long the invoice stays valid before expiring if the customer does not pay. Recommended: 15. Range: 1-1440.',
        ],
        'debug' => [
            'FriendlyName' => 'Debug log',
            'Type'         => 'yesno',
            'Description'  => 'Tick to log API requests and webhook events to: Utilities -> Logs -> Gateway Log.',
        ],
    ];
}

// ---------------------------------------------------------------------------
// Internal: render a user-facing error and log via WHMCS logTransaction().
// ---------------------------------------------------------------------------
function _renovaxpayments_error($message, array $context = [])
{
    if (function_exists('logTransaction')) {
        logTransaction('renovaxpayments', $context + ['error' => $message], 'Error');
    }
    return '<p class="text-danger">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
}

// ---------------------------------------------------------------------------
// Internal: HTTP POST to the RENOVAX merchant API. Returns decoded array
// or a string error message ready to render.
// ---------------------------------------------------------------------------
function _renovaxpayments_http_post($url, $payload, $token, $invoiceId)
{
    $jsonPayload = json_encode($payload);
    if ($jsonPayload === false) {
        return _renovaxpayments_error(
            'RENOVAX internal error: could not encode the request payload.',
            ['json_error' => json_last_error_msg(), 'invoiceid' => $invoiceId]
        );
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return _renovaxpayments_error(
            'RENOVAX internal error: could not initialise cURL. Please contact the server administrator.'
        );
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POSTFIELDS     => $jsonPayload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: RenovaxWHMCS/1.0',
        ],
    ]);

    $body       = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno  = curl_errno($ch);
    $curlErr    = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlErrno !== 0) {
        $userMsg = ($curlErrno === CURLE_OPERATION_TIMEOUTED)
            ? 'RENOVAX did not respond in time. Please try again.'
            : 'RENOVAX could not be reached. Please try again later or contact support.';
        return _renovaxpayments_error($userMsg, [
            'curl_errno' => $curlErrno,
            'curl_error' => $curlErr,
            'invoiceid'  => $invoiceId,
        ]);
    }

    if ($httpStatus === 401 || $httpStatus === 403) {
        return _renovaxpayments_error(
            'RENOVAX authentication failed. Please verify the Bearer Token in Payment Gateways settings.',
            ['http_status' => $httpStatus, 'invoiceid' => $invoiceId]
        );
    }

    if ($httpStatus === 422) {
        $data = json_decode($body, true);
        $apiMsg = is_array($data) && !empty($data['message']) ? $data['message'] : 'Unprocessable request.';
        return _renovaxpayments_error(
            'RENOVAX rejected the request: ' . $apiMsg,
            ['http_status' => $httpStatus, 'invoiceid' => $invoiceId, 'body' => $body]
        );
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        return _renovaxpayments_error(
            'RENOVAX returned an unexpected error. Please try again later or contact support.',
            ['http_status' => $httpStatus, 'invoiceid' => $invoiceId, 'body' => $body]
        );
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        return _renovaxpayments_error(
            'RENOVAX returned an invalid response. Please try again later.',
            ['json_error' => json_last_error_msg(), 'body' => substr($body, 0, 500)]
        );
    }

    return $data;
}

// ---------------------------------------------------------------------------
// Payment link.
// WHMCS calls this to render the payment button on the invoice page.
// $params provided by WHMCS includes: amount, currency, invoiceid, returnurl,
// systemurl, clientdetails[], plus the keys defined in renovaxpayments_config().
// ---------------------------------------------------------------------------
function renovaxpayments_link($params)
{
    $apiBase     = rtrim($params['api_base_url'] ?: 'https://payments.renovax.net', '/');
    $token       = trim($params['bearer_token'] ?? '');
    $ttl         = (int) (($params['invoice_ttl_minutes'] ?? '') ?: 15);
    $invoiceId   = $params['invoiceid'] ?? '';
    $amount      = $params['amount'] ?? 0;
    $currency    = $params['currency'] ?? '';
    $returnUrl   = $params['returnurl'] ?? ($params['systemurl'] ?? '');
    $clientEmail = $params['clientdetails']['email'] ?? '';

    if ($token === '') {
        return _renovaxpayments_error(
            'RENOVAX is not configured: Bearer Token is missing. Please set it in Payment Gateways settings.'
        );
    }
    if ($invoiceId === '') {
        return _renovaxpayments_error(
            'RENOVAX could not process the payment: invoice ID is empty.'
        );
    }
    if (!filter_var($apiBase, FILTER_VALIDATE_URL)) {
        return _renovaxpayments_error(
            'RENOVAX is not configured: API Base URL is not a valid URL.',
            ['api_base_url' => $apiBase]
        );
    }
    if ($ttl < 1 || $ttl > 1440) {
        return _renovaxpayments_error(
            'RENOVAX is not configured: Invoice TTL must be between 1 and 1440 minutes.',
            ['invoice_ttl_minutes' => $ttl]
        );
    }

    $payload = [
        'amount'             => number_format((float) $amount, 2, '.', ''),
        'currency'           => $currency,
        'client_remote_id'   => (string) $invoiceId,
        'success_url'        => $returnUrl,
        'cancel_url'         => $returnUrl,
        'expires_in_minutes' => $ttl,
        'metadata'           => [
            'whmcs_invoiceid' => (string) $invoiceId,
            'whmcs_email'     => $clientEmail,
            'whmcs_systemurl' => $params['systemurl'] ?? '',
        ],
    ];

    $result = _renovaxpayments_http_post($apiBase . '/api/v1/merchant/invoices', $payload, $token, $invoiceId);
    if (is_string($result)) {
        // Already-rendered error HTML.
        return $result;
    }

    if (empty($result['pay_url'])) {
        return _renovaxpayments_error(
            'RENOVAX returned an incomplete response (missing pay_url). Please try again later.',
            ['invoiceid' => $invoiceId, 'response' => $result]
        );
    }

    if (!empty($params['debug'])) {
        logTransaction('renovaxpayments', [
            'invoiceid'   => $invoiceId,
            'renovax_id'  => $result['id'] ?? '',
            'pay_url'     => $result['pay_url'],
        ], 'Invoice Created');
    }

    $payUrl = htmlspecialchars($result['pay_url'], ENT_QUOTES, 'UTF-8');
    $label  = function_exists('Lang::trans')
        ? \Lang::trans('invoicespaynow')
        : 'Pay Now';

    return '<a class="btn btn-success btn-lg" style="background-color:#198754;border-color:#198754;color:#fff;width:100%;" href="' . $payUrl . '">'
         . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ' &middot; RENOVAX Payments'
         . '</a>';
}

// ---------------------------------------------------------------------------
// Refund.
// WHMCS calls this when the admin clicks "Refund" on a transaction.
// $params['transid'] is the RENOVAX invoice UUID stored at addInvoicePayment time.
// Must return an array with keys: status, rawdata, transid, fees.
// ---------------------------------------------------------------------------
function renovaxpayments_refund($params)
{
    $apiBase   = rtrim($params['api_base_url'] ?: 'https://payments.renovax.net', '/');
    $token     = trim($params['bearer_token'] ?? '');
    $invoiceId = trim($params['transid'] ?? '');
    $amount    = $params['amount'] ?? 0;

    if ($token === '' || $invoiceId === '') {
        return [
            'status'  => 'declined',
            'rawdata' => 'Missing bearer_token or transid.',
            'transid' => $invoiceId,
            'fees'    => 0,
        ];
    }

    $url     = $apiBase . '/api/v1/merchant/invoices/' . rawurlencode($invoiceId) . '/refund';
    $payload = ['amount' => number_format((float) $amount, 2, '.', '')];

    $result = _renovaxpayments_http_post($url, $payload, $token, $invoiceId);

    if (is_string($result)) {
        return [
            'status'  => 'declined',
            'rawdata' => strip_tags($result),
            'transid' => $invoiceId,
            'fees'    => 0,
        ];
    }

    return [
        'status'  => 'success',
        'rawdata' => $result,
        'transid' => $result['refund_id'] ?? $invoiceId,
        'fees'    => 0,
    ];
}
