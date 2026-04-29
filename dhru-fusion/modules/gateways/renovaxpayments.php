<?php
/**
 * RenovaxPayments — DHRU Fusion Gateway Module
 *
 * Server path:
 *   /modules/gateways/renovaxpayments.php
 *
 * DHRU Fusion auto-loads this file and calls renovaxpayments_config() when the
 * gateway is listed/enabled, and renovaxpayments_link($params) when the
 * customer proceeds to pay.
 *
 * No `defined('DHRU')` guard is used because DHRU Fusion does not define a
 * consistent constant when loading modules (it varies between versions).
 * This file only declares functions, so direct access does nothing.
 */

// ---------------------------------------------------------------------------
// Gateway configuration: fields shown in DHRU admin → Payment Gateways
// ---------------------------------------------------------------------------
function renovaxpayments_config()
{
    return array(
        'name' => array(
            'Type'  => 'System',
            'Value' => 'RENOVAX Payments — Multi-platform payment gateway: Crypto, Stripe, PayPal & more',
        ),
        'api_base_url' => array(
            'Name'        => 'API Base URL',
            'Type'        => 'text',
            'Size'        => '60',
            'Value'       => 'https://payments.renovax.net',
            'Description' => 'Multi-platform payment gateway. Single checkout with: (1) Crypto — USDT, USDC, EURC, DAI, PYUSD, FDUSD, etc. on BSC, Ethereum, Polygon, Arbitrum, Base, Optimism, Avalanche, Tron, Solana and more (automatic on-chain detection); (2) PayPal; (3) Stripe (credit/debit cards); (4) more methods as RENOVAX adds them. The customer chooses their preferred method and the DHRU invoice is credited once payment is confirmed. Leave the default value in production.',
        ),
        'bearer_token' => array(
            'Name'        => 'Bearer Token',
            'Type'        => 'text',
            'Size'        => '80',
            'Description' => 'Merchant token in RENOVAX. Generate it at: Merchants → Edit → API Tokens → Create. It is shown only ONCE — copy it immediately and paste it here without spaces.',
        ),
        'webhook_secret' => array(
            'Name'        => 'Webhook Secret',
            'Type'        => 'text',
            'Size'        => '80',
            'Description' => 'Merchant HMAC secret (visible on the merchant edit page in RENOVAX). Validates the X-Renovax-Signature header on payment-confirmation webhooks. Also register the webhook URL pointing to: https://YOUR-DOMAIN/renovaxpaymentscallback.php',
        ),
        'invoice_ttl_minutes' => array(
            'Name'        => 'Invoice TTL (min)',
            'Type'        => 'text',
            'Size'        => '6',
            'Value'       => '15',
            'Description' => 'How many minutes the invoice stays valid before expiring if the customer does not pay. Recommended: 15 — enough for the customer to open their wallet and confirm on-chain without losing the price.',
        ),
        'fiat_enabled' => array(
            'Name'        => 'Allow fiat methods',
            'Type'        => 'dropdown',
            'Options'     => 'on,off',
            'Value'       => 'on',
            'Description' => 'When set to "on", the RENOVAX checkout shows every payment method the merchant has enabled (crypto + Stripe / PayPal / Pix / MercadoPago / EnZona / Transfermovil). Set it to "off" to force crypto-only checkout for invoices generated from this DHRU instance — useful when serving trusted/B2B clients where you only want to accept stablecoins.',
        ),
    );
}

// ---------------------------------------------------------------------------
// Internal helper: render a user-facing error message and log the details.
// ---------------------------------------------------------------------------
function _renovaxpayments_error($message, $context = array())
{
    $logParts = array("[renovaxpayments] {$message}");
    foreach ($context as $key => $value) {
        $logParts[] = "{$key}=" . (is_scalar($value) ? $value : json_encode($value));
    }
    error_log(implode(' ', $logParts));

    return '<p style="color:red">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
}

// ---------------------------------------------------------------------------
// Payment link: DHRU calls this function to get the HTML (button/form) that
// redirects the customer to the gateway.
//
// Typical $params delivered by DHRU:
//   systemurl    — DHRU panel base URL, e.g. "https://your-dhru.com/"
//   invoiceid    — invoice/transaction id in DHRU
//   amount       — total amount
//   currency     — currency code
//   clientdetails.email — customer's email
//   Plus the keys from renovaxpayments_config(): api_base_url, bearer_token,
//   webhook_secret, invoice_ttl_minutes.
// ---------------------------------------------------------------------------
function renovaxpayments_link($params)
{
    // DHRU Fusion does not apply the config `Value` default if the admin leaves
    // the field blank (it stores ""). That's why we use `?:` which also falls
    // back to the default when the value is an empty string.
    $apiBase     = rtrim(($params['api_base_url'] ?? '') ?: 'https://payments.renovax.net', '/');
    $token       = trim($params['bearer_token'] ?? '');
    $ttl         = (int) (($params['invoice_ttl_minutes'] ?? '') ?: 15);
    $fiatEnabled = strtolower(trim($params['fiat_enabled'] ?? 'on')) !== 'off';
    $systemUrl   = rtrim($params['systemurl'] ?? '', '/') . '/';
    $invoiceId   = $params['invoiceid'] ?? '';
    $amount      = $params['amount'] ?? 0;
    $clientEmail = $params['clientdetails']['email'] ?? '';

    // --- Configuration validation -------------------------------------------
    if ($token === '') {
        return _renovaxpayments_error(
            'RenovaxPayments is not configured: Bearer Token is missing. Please set it in Payment Gateways settings.'
        );
    }

    if ($invoiceId === '') {
        return _renovaxpayments_error(
            'RenovaxPayments could not process the payment: invoice ID is empty.',
            array('params_keys' => array_keys($params))
        );
    }

    if (!filter_var($apiBase, FILTER_VALIDATE_URL)) {
        return _renovaxpayments_error(
            'RenovaxPayments is not configured: API Base URL is not a valid URL.',
            array('api_base_url' => $apiBase)
        );
    }

    if ($ttl < 1 || $ttl > 1440) {
        return _renovaxpayments_error(
            'RenovaxPayments is not configured: Invoice TTL must be between 1 and 1440 minutes.',
            array('invoice_ttl_minutes' => $ttl)
        );
    }

    // DHRU Fusion uses `/viewinvoice/id/{md5(invoiceid)}` (path, not query string).
    $viewInvoiceUrl = $systemUrl . 'viewinvoice/id/' . md5((string) $invoiceId);

    $payload = array(
        'amount'             => number_format((float) $amount, 2, '.', ''),
        // currency intentionally omitted — inherited from the merchant's default_currency.
        'client_remote_id'   => (string) $invoiceId,
        'success_url'        => $viewInvoiceUrl,
        'cancel_url'         => $viewInvoiceUrl,
        'expires_in_minutes' => $ttl,
        'fiat_enabled'       => $fiatEnabled,
        'metadata'           => array(
            'dhru_invoiceid' => (string) $invoiceId,
            'dhru_email'     => $clientEmail,
            'dhru_systemurl' => $systemUrl,
        ),
    );

    $jsonPayload = json_encode($payload);
    if ($jsonPayload === false) {
        return _renovaxpayments_error(
            'RenovaxPayments internal error: could not encode the request payload.',
            array('json_error' => json_last_error_msg())
        );
    }

    // --- HTTP request --------------------------------------------------------
    $ch = curl_init($apiBase . '/api/merchant/invoices');
    if ($ch === false) {
        return _renovaxpayments_error(
            'RenovaxPayments internal error: could not initialise cURL. Please contact the server administrator.'
        );
    }

    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POSTFIELDS     => $jsonPayload,
        CURLOPT_HTTPHEADER     => array(
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ),
    ));

    $body       = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno  = curl_errno($ch);
    $curlErr    = curl_error($ch);
    curl_close($ch);

    // --- cURL-level errors (network, timeout, TLS) --------------------------
    if ($body === false || $curlErrno !== 0) {
        $userMsg = ($curlErrno === CURLE_OPERATION_TIMEOUTED)
            ? 'RenovaxPayments did not respond in time. Please try again.'
            : 'RenovaxPayments could not be reached. Please try again later or contact support.';

        return _renovaxpayments_error($userMsg, array(
            'curl_errno'  => $curlErrno,
            'curl_error'  => $curlErr,
            'invoiceid'   => $invoiceId,
        ));
    }

    // --- HTTP-level errors --------------------------------------------------
    if ($httpStatus === 401 || $httpStatus === 403) {
        return _renovaxpayments_error(
            'RenovaxPayments authentication failed. Please verify the Bearer Token in Payment Gateways settings.',
            array('http_status' => $httpStatus, 'invoiceid' => $invoiceId)
        );
    }

    if ($httpStatus === 422) {
        $data = json_decode($body, true);
        $apiMsg = is_array($data) && !empty($data['message']) ? $data['message'] : 'Unprocessable request.';
        return _renovaxpayments_error(
            'RenovaxPayments rejected the request: ' . $apiMsg,
            array('http_status' => $httpStatus, 'invoiceid' => $invoiceId, 'body' => $body)
        );
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        return _renovaxpayments_error(
            'RenovaxPayments returned an unexpected error. Please try again later or contact support.',
            array('http_status' => $httpStatus, 'invoiceid' => $invoiceId, 'body' => $body)
        );
    }

    // --- Response parsing ---------------------------------------------------
    $data = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return _renovaxpayments_error(
            'RenovaxPayments returned an invalid response. Please try again later.',
            array('json_error' => json_last_error_msg(), 'body' => substr($body, 0, 500))
        );
    }

    if (!is_array($data) || empty($data['pay_url'])) {
        return _renovaxpayments_error(
            'RenovaxPayments returned an incomplete response (missing pay_url). Please try again later.',
            array('http_status' => $httpStatus, 'invoiceid' => $invoiceId, 'body' => $body)
        );
    }

    // --- Render payment button ----------------------------------------------
    $payUrl = htmlspecialchars($data['pay_url'], ENT_QUOTES, 'UTF-8');

    // Use DHRU's native translation for "Pay Now" if available; fall back to plain text otherwise.
    global $lng_languag;
    $payNow = isset($lng_languag['invoicespaynow']) ? $lng_languag['invoicespaynow'] : 'Pay Now';

    return '<a class="btn btn-success pt-3 pb-3" style="width:100%;background-color:#198754!important;color:#fff!important;" href="' . $payUrl . '">'
         . $payNow . ' · RENOVAX Payments'
         . '</a>';
}
