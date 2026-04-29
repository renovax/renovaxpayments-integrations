<?php
/**
 * RENOVAX Payments — public webhook receiver for BILLmanager.
 *
 * Server path:
 *   /usr/local/mgr5/skins/<skin>/scripts/renovax_callback.php
 * Or any web-served location, e.g. /var/www/billmgr/callback/renovax_callback.php
 *
 * Public URL example:
 *   https://billmgr.example.com/manimg/scripts/renovax_callback.php
 *
 * Register this URL as the merchant's webhook_url in RENOVAX.
 *
 * Responsibilities:
 *   - Verify the HMAC-SHA256 signature against `webhook_secret`.
 *   - Deduplicate by X-Renovax-Event-Id (stored in /tmp/renovax_evt_*.lock).
 *   - Forward the validated event to processing/pmrenovax.php (PayCallback).
 *
 * Configure paths via environment or edit the constants below.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Configuration. Edit if your installation lives elsewhere.
// ---------------------------------------------------------------------------
define('RENOVAX_WEBHOOK_SECRET',      getenv('RENOVAX_WEBHOOK_SECRET')      ?: '');
define('RENOVAX_PMRENOVAX_PATH',      getenv('RENOVAX_PMRENOVAX_PATH')      ?: '/usr/local/mgr5/processing/pmrenovax.php');
define('RENOVAX_DEDUPE_DIR',          getenv('RENOVAX_DEDUPE_DIR')          ?: sys_get_temp_dir());

ignore_user_abort(true);
set_time_limit(60);

// ---------------------------------------------------------------------------
// Tiny helpers.
// ---------------------------------------------------------------------------
function renovax_exit(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body);
    exit;
}

// ---------------------------------------------------------------------------
// 1) Read raw body.
// ---------------------------------------------------------------------------
$payload = (string) file_get_contents('php://input');
if ($payload === '') {
    renovax_exit(400, ['ok' => false, 'error' => 'empty_body']);
}

// ---------------------------------------------------------------------------
// 2) Verify HMAC-SHA256.
// ---------------------------------------------------------------------------
if (RENOVAX_WEBHOOK_SECRET === '') {
    renovax_exit(500, ['ok' => false, 'error' => 'webhook_secret_not_configured']);
}

$signatureHeader = $_SERVER['HTTP_X_RENOVAX_SIGNATURE'] ?? '';
$providedSig     = str_replace('sha256=', '', (string) $signatureHeader);
$expectedSig     = hash_hmac('sha256', $payload, RENOVAX_WEBHOOK_SECRET);

if ($providedSig === '' || !hash_equals($expectedSig, $providedSig)) {
    renovax_exit(401, ['ok' => false, 'error' => 'invalid_signature']);
}

// ---------------------------------------------------------------------------
// 3) Parse JSON.
// ---------------------------------------------------------------------------
$event = json_decode($payload, true);
if (!is_array($event)) {
    renovax_exit(400, ['ok' => false, 'error' => 'invalid_json']);
}

// ---------------------------------------------------------------------------
// 4) Idempotency via filesystem lock on event_id.
// ---------------------------------------------------------------------------
$eventId = $_SERVER['HTTP_X_RENOVAX_EVENT_ID'] ?? '';
if ($eventId !== '') {
    $lockFile = rtrim(RENOVAX_DEDUPE_DIR, '/') . '/renovax_evt_' . preg_replace('/[^A-Za-z0-9_-]/', '', $eventId) . '.lock';
    // Atomic create-or-fail: 'x' mode opens with O_CREAT|O_EXCL, so concurrent
    // webhooks for the same event_id can't both pass the existence check.
    $fp = @fopen($lockFile, 'x');
    if ($fp === false) {
        renovax_exit(200, ['ok' => true, 'duplicate' => true]);
    }
    @fwrite($fp, (string) time());
    @fclose($fp);
    // Best-effort cleanup of locks older than 30 days.
    foreach ((array) glob(rtrim(RENOVAX_DEDUPE_DIR, '/') . '/renovax_evt_*.lock') as $old) {
        if (is_string($old) && @filemtime($old) < time() - 30 * 86400) {
            @unlink($old);
        }
    }
}

// ---------------------------------------------------------------------------
// 5) Forward to the BILLmanager processing script via CLI.
//    Writing the event to stdin keeps the CLI invocation neat.
// ---------------------------------------------------------------------------
if (!is_file(RENOVAX_PMRENOVAX_PATH)) {
    error_log('[renovax] processing script not found: ' . RENOVAX_PMRENOVAX_PATH);
    renovax_exit(500, ['ok' => false, 'error' => 'processing_script_missing']);
}

$cmd = escapeshellcmd('/usr/bin/php') . ' '
     . escapeshellarg(RENOVAX_PMRENOVAX_PATH) . ' --command PayCallback';

$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($cmd, $descriptors, $pipes);
if (!is_resource($proc)) {
    renovax_exit(500, ['ok' => false, 'error' => 'spawn_failed']);
}

fwrite($pipes[0], json_encode(['event' => $event]));
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($proc);

if ($exitCode !== 0) {
    error_log('[renovax] PayCallback exit=' . $exitCode . ' stderr=' . $stderr);
    renovax_exit(500, ['ok' => false, 'error' => 'callback_failed']);
}

renovax_exit(200, ['ok' => true, 'forwarded' => true]);
