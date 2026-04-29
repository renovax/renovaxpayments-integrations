<?php
/**
 * RENOVAX Payments — BILLmanager processing script.
 *
 * Server path:
 *   /usr/local/mgr5/processing/pmrenovax.php
 *
 * BILLmanager invokes this script via CLI with `--command <action>` and a
 * second JSON-encoded argument that holds the parameters. The standard
 * actions are:
 *
 *   Init             — declare form fields shown to the admin and to the customer.
 *   FeatureList      — list capabilities supported by this module.
 *   PreparePayment   — create the RENOVAX invoice and store the pay_url.
 *   Pay              — return the URL the customer must be redirected to.
 *   PayCallback      — process a payment notification (also exposed via HTTP
 *                      callback in /callback/renovax_callback.php).
 *   GetPaymentStatus — query RENOVAX for the current status of an invoice.
 *
 * BILLmanager API reference:
 *   https://docs.ispsystem.com/billmanager-developer/integration-with-payment-systems
 *
 * NOTE: This is a scaffold targeting BILLmanager 6. Check exact CLI argument
 * shape against your installed version — minor adjustments may be required.
 */

declare(strict_types=1);

require_once '/usr/local/mgr5/processing/pmcommon.php';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @return array{api_base:string,token:string,secret:string,ttl:int}
 */
function renovax_settings(array $payment): array
{
    $module = $payment['module'] ?? $payment['paymethod'] ?? [];
    return [
        'api_base' => rtrim((string) ($module['api_base_url'] ?? 'https://payments.renovax.net'), '/'),
        'token'    => trim((string) ($module['bearer_token']  ?? '')),
        'secret'   => trim((string) ($module['webhook_secret'] ?? '')),
        'ttl'      => (int) (($module['invoice_ttl_minutes'] ?? 15) ?: 15),
    ];
}

/**
 * @param string $method
 * @param string $url
 * @param string $token
 * @param array<string,mixed>|null $body
 * @return array{status:int,data:?array,raw:string}
 */
function renovax_http(string $method, string $url, string $token, ?array $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: RenovaxBILLmanager/1.0',
        ],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw    = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($raw, true);
    return [
        'status' => $status,
        'data'   => is_array($data) ? $data : null,
        'raw'    => $raw,
    ];
}

function renovax_log(string $level, string $message, array $context = []): void
{
    $line = '[renovax][' . $level . '] ' . $message;
    foreach ($context as $k => $v) {
        $line .= ' ' . $k . '=' . (is_scalar($v) ? (string) $v : json_encode($v));
    }
    error_log($line);
}

// ---------------------------------------------------------------------------
// CLI dispatcher
// ---------------------------------------------------------------------------

$command = '';
$argsRaw = '';
foreach ($argv as $i => $arg) {
    if ($arg === '--command' && isset($argv[$i + 1])) {
        $command = $argv[$i + 1];
    }
}
// BILLmanager passes JSON params on stdin in modern versions.
$stdin = stream_get_contents(STDIN) ?: '';
$input = $stdin !== '' ? (json_decode($stdin, true) ?: []) : [];

switch ($command) {
    case 'Init':
        renovax_init();
        break;
    case 'FeatureList':
        renovax_feature_list();
        break;
    case 'PreparePayment':
        renovax_prepare_payment($input);
        break;
    case 'Pay':
        renovax_pay($input);
        break;
    case 'PayCallback':
        renovax_pay_callback($input);
        break;
    case 'GetPaymentStatus':
        renovax_get_payment_status($input);
        break;
    case 'Config':
        renovax_config();
        break;
    default:
        // Unknown command — exit silently to avoid breaking BILLmanager.
        echo json_encode(['ok' => true, 'ignored' => $command]);
        exit(0);
}

// ---------------------------------------------------------------------------
// Action handlers
// ---------------------------------------------------------------------------

function renovax_init(): void
{
    // Form fields rendered when the admin configures the payment method.
    echo json_encode([
        'fields' => [
            ['name' => 'api_base_url',        'type' => 'text',     'default' => 'https://payments.renovax.net', 'required' => true],
            ['name' => 'bearer_token',        'type' => 'password', 'required' => true],
            ['name' => 'webhook_secret',      'type' => 'password', 'required' => true],
            ['name' => 'invoice_ttl_minutes', 'type' => 'text',     'default' => '15'],
        ],
    ]);
}

function renovax_config(): void
{
    // Capabilities advertised to BILLmanager.
    echo json_encode([
        'name'         => 'pmrenovax',
        'feature'      => ['payment'],
        'currency'     => ['USD', 'EUR', 'BRL', 'MXN', 'ARS', 'CLP', 'COP', 'PEN', 'RUB'],
        'paymethod'    => 'redirect',
        'allow_partial_refund' => true,
    ]);
}

function renovax_feature_list(): void
{
    echo json_encode(['features' => ['redirect', 'callback', 'refund', 'getstatus']]);
}

function renovax_prepare_payment(array $input): void
{
    $payment = $input['payment'] ?? $input;
    $cfg     = renovax_settings($payment);

    $billmgrId = (string) ($payment['id'] ?? $payment['payment_id'] ?? '');
    $amount    = (string) ($payment['amount'] ?? '0');
    $currency  = (string) ($payment['currency'] ?? 'USD');
    $email     = (string) ($payment['account']['email'] ?? '');
    $returnUrl = (string) ($payment['returnurl'] ?? '');

    if ($billmgrId === '' || $cfg['token'] === '') {
        echo json_encode(['error' => 'missing_payment_id_or_token']);
        exit(1);
    }

    $resp = renovax_http('POST', $cfg['api_base'] . '/api/v1/merchant/invoices', $cfg['token'], [
        'amount'             => number_format((float) $amount, 2, '.', ''),
        'currency'           => $currency,
        'client_remote_id'   => $billmgrId,
        'success_url'        => $returnUrl,
        'cancel_url'         => $returnUrl,
        'expires_in_minutes' => max(1, min(1440, $cfg['ttl'])),
        'metadata'           => [
            'billmgr_payment_id' => $billmgrId,
            'billmgr_email'      => $email,
        ],
    ]);

    if ($resp['status'] < 200 || $resp['status'] >= 300 || empty($resp['data']['pay_url'])) {
        renovax_log('error', 'PreparePayment failed', ['status' => $resp['status'], 'body' => $resp['raw']]);
        echo json_encode(['error' => 'create_invoice_failed', 'status' => $resp['status']]);
        exit(1);
    }

    echo json_encode([
        'externalid' => $resp['data']['id'],
        'redirect'   => $resp['data']['pay_url'],
    ]);
}

function renovax_pay(array $input): void
{
    // BILLmanager often calls Pay right after PreparePayment to obtain the
    // redirect URL. We persist it via the metadata returned previously.
    $payment    = $input['payment'] ?? $input;
    $externalId = (string) ($payment['externalid'] ?? '');
    $cfg        = renovax_settings($payment);

    if ($externalId === '') {
        // Fall back to PreparePayment behaviour.
        renovax_prepare_payment($input);
        return;
    }

    $resp = renovax_http('GET', $cfg['api_base'] . '/api/v1/merchant/invoices/' . rawurlencode($externalId), $cfg['token']);
    if ($resp['status'] !== 200 || empty($resp['data']['pay_url'])) {
        echo json_encode(['error' => 'invoice_not_found']);
        exit(1);
    }

    echo json_encode(['redirect' => $resp['data']['pay_url']]);
}

function renovax_get_payment_status(array $input): void
{
    $payment    = $input['payment'] ?? $input;
    $externalId = (string) ($payment['externalid'] ?? '');
    $cfg        = renovax_settings($payment);

    if ($externalId === '') {
        echo json_encode(['status' => 'pending']);
        return;
    }

    $resp = renovax_http('GET', $cfg['api_base'] . '/api/v1/merchant/invoices/' . rawurlencode($externalId), $cfg['token']);
    if ($resp['status'] !== 200) {
        echo json_encode(['status' => 'pending']);
        return;
    }

    $map = [
        'pending'   => 'pending',
        'confirmed' => 'paid',
        'expired'   => 'cancelled',
        'failed'    => 'failed',
        'refunded'  => 'refunded',
    ];
    $renovaxStatus = (string) ($resp['data']['status'] ?? 'pending');
    echo json_encode(['status' => $map[$renovaxStatus] ?? 'pending']);
}

/**
 * Called after the public HTTP callback validates the HMAC signature.
 * Receives a clean event payload and updates the BILLmanager payment.
 */
function renovax_pay_callback(array $input): void
{
    $event     = $input['event'] ?? $input;
    $billmgrId = (string) ($event['metadata']['billmgr_payment_id'] ?? '');
    $eventType = (string) ($event['event_type'] ?? '');

    if ($billmgrId === '') {
        echo json_encode(['ok' => false, 'error' => 'missing_billmgr_payment_id']);
        exit(0);
    }

    // Update the BILLmanager payment record using the local CLI helper.
    // The actual mgr command depends on the version; for billmgr 6:
    //   billmgr -m billmgr payment.edit elid=<id> status=<status>
    $billmgr = '/usr/local/mgr5/sbin/mgrctl';
    if (file_exists($billmgr)) {
        $status = match ($eventType) {
            'invoice.paid', 'invoice.overpaid' => 'paid',
            'invoice.partial'                  => 'inpay',
            'invoice.expired'                  => 'cancelled',
            default                            => null,
        };
        if ($status !== null) {
            $cmd = escapeshellcmd($billmgr) . ' -m billmgr payment.edit'
                 . ' elid=' . escapeshellarg($billmgrId)
                 . ' status=' . escapeshellarg($status);
            shell_exec($cmd);
        }
    }

    echo json_encode(['ok' => true, 'event' => $eventType, 'billmgr_id' => $billmgrId]);
}
