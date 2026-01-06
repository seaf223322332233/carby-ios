<?php
// payment_webhook.php - Unified Payment Webhook Receiver (Secure + Crash-Proof + Multi-Gateway)
// ============================================================================================

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Content-Type: text/plain; charset=utf-8");

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/payment_webhook_error.log');
error_reporting(E_ALL);

// Debug mode: ?debug=1
$DEBUG = (isset($_GET['debug']) && $_GET['debug'] === '1');
if ($DEBUG) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            header('Content-Type: text/plain; charset=utf-8');
            echo "FATAL ERROR\n";
            echo $err['message'] . "\n";
            echo "FILE: " . $err['file'] . "\n";
            echo "LINE: " . $err['line'] . "\n";
        }
    });
}

// -----------------------------------------------------------------------------
// Includes (must exist)
// -----------------------------------------------------------------------------
require_once 'db_connect.php';
require_once 'PaymentConfig.php';

// Optional (page must not crash if missing)
$WH_HAS_ENGINE = false;
if (file_exists(__DIR__ . '/PaymentEngine.php')) {
    require_once 'PaymentEngine.php';
    $WH_HAS_ENGINE = class_exists('PaymentEngine');
}

// -----------------------------------------------------------------------------
// Helpers (namespaced to avoid collisions)
// -----------------------------------------------------------------------------
function wh_log(string $msg): void { error_log("[payment_webhook] " . $msg); }

function wh_get_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    // if behind proxy and you trust it, adjust here
    return (string)$ip;
}

function wh_table_exists(PDO $pdo, string $t): bool {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE ?");
        $st->execute([$t]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function wh_json_encode($v): string {
    $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $j === false ? '' : $j;
}

function wh_read_body(): array {
    $raw = file_get_contents('php://input');
    $ct  = strtolower($_SERVER['CONTENT_TYPE'] ?? '');

    // JSON
    if (strpos($ct, 'application/json') !== false) {
        $data = json_decode($raw, true);
        if (is_array($data)) return [$raw, $data];
        return [$raw, []];
    }

    // x-www-form-urlencoded or unknown -> try parse
    $data = [];
    if ($raw !== '') {
        parse_str($raw, $data);
        if (!is_array($data)) $data = [];
    }

    // fallback: if PHP already parsed POST
    if (!$data && !empty($_POST) && is_array($_POST)) $data = $_POST;

    return [$raw, $data];
}

function wh_ip_allowlist_ok(string $ip, string $allowCsv): bool {
    $allowCsv = trim((string)$allowCsv);
    if ($allowCsv === '') return true; // not enforced
    $allowCsv = str_replace(' ', '', $allowCsv);
    $list = array_filter(explode(',', $allowCsv), fn($x) => $x !== '');
    // exact match (simple + safe). If you need CIDR later, we add it.
    return in_array($ip, $list, true);
}

function wh_pick(array $arr, array $keys, $default = null) {
    foreach ($keys as $k) {
        if (array_key_exists($k, $arr) && $arr[$k] !== '' && $arr[$k] !== null) return $arr[$k];
    }
    return $default;
}

function wh_find_txn_id(array $payload): int {
    // common patterns (you can extend anytime)
    $candidates = [
        $payload['txn_id'] ?? null,
        $payload['transaction_id'] ?? null,
        $payload['metadata']['txn_id'] ?? null,
        $payload['metadata']['txn'] ?? null,
        $payload['meta']['txn_id'] ?? null,
        $payload['meta']['txn'] ?? null,
        $payload['order']['metadata']['txn_id'] ?? null,
    ];

    foreach ($candidates as $c) {
        if (is_numeric($c)) return (int)$c;
        if (is_string($c) && preg_match('/^\d+$/', $c)) return (int)$c;
    }

    // Sometimes providers send "reference" like "TXN-123"
    $ref = (string)wh_pick($payload, ['reference','ref','merchant_reference','merchant_ref','order_reference','order_ref'], '');
    if ($ref && preg_match('/(\d+)/', $ref, $m)) return (int)$m[1];

    return 0;
}

function wh_map_status(string $gateway, array $payload): string {
    $g = strtolower(trim($gateway));

    // generic fields
    $s1 = strtolower((string)wh_pick($payload, ['status','payment_status','event','type','result','state'], ''));
    $s2 = strtolower((string)wh_pick($payload, ['transaction_status','transaction.state','data.status'], ''));

    $s = $s1 ?: $s2;

    // Common normalization
    $paidWords   = ['paid','succeeded','success','captured','approved','completed'];
    $failWords   = ['failed','fail','declined','rejected','error','void','canceled','cancelled','expired'];
    $pendWords   = ['pending','processing','in_progress','created','initiated','authorized','authorised'];

    foreach ($paidWords as $w)  if (strpos($s, $w) !== false) return 'paid';
    foreach ($failWords as $w)  if (strpos($s, $w) !== false) return (strpos($s, 'cancel') !== false ? 'canceled' : 'failed');
    foreach ($pendWords as $w)  if (strpos($s, $w) !== false) return (strpos($s, 'created') !== false ? 'created' : 'pending');

    // gateway specific hooks (placeholders you can expand)
    if ($g === 'paytabs') {
        // PayTabs often has: payment_result->response_status / response_message, tran_ref, tran_type
        $resp = strtolower((string)($payload['payment_result']['response_status'] ?? ''));
        if ($resp === 'a' || $resp === 'approved' || $resp === 'success') return 'paid';
        if ($resp === 'd' || $resp === 'declined') return 'failed';
    }

    if ($g === 'hyperpay') {
        // HyperPay often has result->code where "000.000." etc indicates success (depends on integration)
        $code = (string)($payload['result']['code'] ?? '');
        if ($code !== '') {
            // very common success pattern: 000.000. or 000.100. or 000.200.
            if (preg_match('/^000\.(000|100|200)\./', $code)) return 'paid';
            // common pending: 000.400. (depends) - keep as pending if unsure
        }
    }

    // default safest
    return 'pending';
}

// -----------------------------------------------------------------------------
// Security: Token + IP Allowlist
// -----------------------------------------------------------------------------
$tokenRequired = ps_getSystemSetting('payment_webhook_token', '');
$allowlistCsv  = ps_getSystemSetting('payment_webhook_ip_allowlist', '');

$reqToken = $_GET['token'] ?? ($_POST['token'] ?? '');
$gateway  = $_GET['gateway'] ?? ($_POST['gateway'] ?? ''); // expected: paytabs / hyperpay / moyasar / etc
$gateway  = strtolower(trim((string)$gateway));

$ip = wh_get_ip();

// token check (only if configured)
if ($tokenRequired !== '') {
    if (!$reqToken || !hash_equals($tokenRequired, (string)$reqToken)) {
        wh_log("BLOCKED: bad token | ip=$ip | gateway=$gateway");
        http_response_code(403);
        echo "FORBIDDEN\n";
        exit;
    }
}

// allowlist check (optional)
if (!wh_ip_allowlist_ok($ip, $allowlistCsv)) {
    wh_log("BLOCKED: ip not allowed | ip=$ip | gateway=$gateway");
    http_response_code(403);
    echo "FORBIDDEN\n";
    exit;
}

// -----------------------------------------------------------------------------
// Read payload
// -----------------------------------------------------------------------------
[$rawBody, $payload] = wh_read_body();
if (!is_array($payload)) $payload = [];
if ($gateway === '') {
    // try infer from payload if possible
    $gateway = strtolower((string)wh_pick($payload, ['gateway','provider','source'], ''));
}

if ($DEBUG) {
    wh_log("DEBUG incoming | ip=$ip | gateway=$gateway | ct=".($_SERVER['CONTENT_TYPE'] ?? ''));
}

// -----------------------------------------------------------------------------
// DB Checks
// -----------------------------------------------------------------------------
$needTables = ['payment_transactions','payment_events'];
foreach ($needTables as $t) {
    if (!wh_table_exists($pdo, $t)) {
        wh_log("MISSING TABLE: $t");
        http_response_code(500);
        echo "DB_NOT_READY\n";
        exit;
    }
}

// -----------------------------------------------------------------------------
// Find txn + decide env
// -----------------------------------------------------------------------------
$txnId = wh_find_txn_id($payload);

// env: from payload OR system default
$env = strtolower((string)wh_pick($payload, ['env','mode','test_mode'], ''));
$env = ($env === 'live' || $env === 'production') ? 'live' : (($env === 'test' || $env === 'sandbox') ? 'test' : ps_getSystemSetting('payment_env', 'test'));
if (!in_array($env, ['test','live'], true)) $env = 'test';

// provider_ref: keep for admin tracking
$providerRef = (string)wh_pick($payload, [
    'provider_ref','reference','ref','tran_ref','transaction_reference','transaction_id',
    'id','payment_id','checkout_id'
], '');

// decide status
$newStatus = wh_map_status($gateway, $payload);

// -----------------------------------------------------------------------------
// Store event (always)
// -----------------------------------------------------------------------------
try {
    $stE = $pdo->prepare("
        INSERT INTO payment_events (txn_id, gateway_code, env, event_type, payload, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $eventType = (string)wh_pick($payload, ['event','type','action'], 'webhook');
    $payloadJson = $rawBody !== '' ? $rawBody : wh_json_encode($payload);
    $stE->execute([(int)$txnId, $gateway ?: 'unknown', $env, $eventType ?: 'webhook', $payloadJson]);
} catch (Throwable $e) {
    wh_log("EVENT INSERT FAIL: ".$e->getMessage());
    // continue (do not break webhook)
}

// -----------------------------------------------------------------------------
// Update transaction if exists
// -----------------------------------------------------------------------------
$txnExists = false;
$txnRow = null;

if ($txnId > 0) {
    try {
        $st = $pdo->prepare("SELECT * FROM payment_transactions WHERE id=? LIMIT 1");
        $st->execute([(int)$txnId]);
        $txnRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $txnExists = (bool)$txnRow;
    } catch (Throwable $e) {
        wh_log("TXN SELECT FAIL: ".$e->getMessage());
    }
}

if ($txnExists) {
    try {
        // keep last payload snapshot in txn table (useful for admin)
        $stU = $pdo->prepare("
            UPDATE payment_transactions
            SET gateway_code = COALESCE(NULLIF(?, ''), gateway_code),
                env = COALESCE(NULLIF(?, ''), env),
                provider_ref = COALESCE(NULLIF(?, ''), provider_ref),
                provider_payload = ?,
                status = ?,
                updated_at = NOW()
            WHERE id = ?
            LIMIT 1
        ");
        $payloadJson = $rawBody !== '' ? $rawBody : wh_json_encode($payload);
        $stU->execute([
            $gateway ?: ($txnRow['gateway_code'] ?? ''),
            $env ?: ($txnRow['env'] ?? ''),
            $providerRef,
            $payloadJson,
            $newStatus,
            (int)$txnId
        ]);
    } catch (Throwable $e) {
        wh_log("TXN UPDATE FAIL: ".$e->getMessage());
    }

    // If paid and PaymentEngine exists => mark paid + (optionally) apply later in your PaymentApply flow
    if ($newStatus === 'paid' && $WH_HAS_ENGINE) {
        try {
            // We pass provider payload minimal
            PaymentEngine::setTxnStatus((int)$txnId, 'paid', $providerRef, $payload);
        } catch (Throwable $e) {
            wh_log("ENGINE setTxnStatus FAIL: ".$e->getMessage());
        }
    }
}

// -----------------------------------------------------------------------------
// Response
// -----------------------------------------------------------------------------
http_response_code(200);

if ($DEBUG) {
    echo "OK\n";
    echo "gateway=$gateway\n";
    echo "env=$env\n";
    echo "txn_id=$txnId\n";
    echo "status=$newStatus\n";
    echo "provider_ref=$providerRef\n";
    echo "ip=$ip\n";
    echo "txn_exists=" . ($txnExists ? '1' : '0') . "\n";
} else {
    // Most providers accept any 200 text
    echo "OK";
}