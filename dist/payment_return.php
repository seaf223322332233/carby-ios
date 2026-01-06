<?php
// payment_return.php - Unified Return Handler (Moyasar / PayTabs / HyperPay)
// ==============================================================================
// Called by browser redirect after payment.
// Query examples:
//  - ?gateway=moyasar&txn=123&id=pay_xxx
//  - ?gateway=hyperpay&txn=123&resourcePath=/v1/checkouts/.../payment
//  - ?gateway=paytabs&txn=123  (payload may come via GET/POST)
//
// This file:
// 1) Logs the return event
// 2) Verifies payment server-to-server for Moyasar/HyperPay
// 3) Updates payment_transactions status
// 4) Redirects user to client_payment_result.php
// ==============================================================================

header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'db_connect.php';
require_once 'PaymentConfig.php';
require_once 'PaymentEngine.php'; // for helpers + logging (and future adapters)

/* =============================================================================
   Helpers
============================================================================= */
function get_str(string $k, string $d=''): string { return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d; }
function get_int(string $k, int $d=0): int { return isset($_GET[$k]) ? (int)$_GET[$k] : $d; }
function post_arr(): array { return $_POST ?: []; }

function headers_safe(): array {
    // prefer PaymentEngine helper if available
    if (class_exists('PaymentEngine') && method_exists('PaymentEngine','getHeadersSafe')) {
        return PaymentEngine::getHeadersSafe();
    }
    $headers = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $name = strtolower(str_replace('_','-', substr($k,5)));
            $headers[$name] = $v;
        }
    }
    return $headers;
}

function redirect_to_result(int $txnId, string $status, string $msg=''): void {
    $base = ps_getAppBaseUrl();
    $url = $base . "/client_payment_result.php?txn=" . $txnId . "&status=" . urlencode($status);
    if ($msg !== '') $url .= "&msg=" . urlencode($msg);
    header("Location: ".$url);
    exit;
}

function ensure_login_then_result(int $txnId, string $status, string $msg=''): void {
    // إذا المستخدم غير مسجل، نخزن الوجهة ونوديه لتسجيل الدخول
    if (empty($_SESSION['user_id'])) {
        $_SESSION['after_login_redirect'] = ps_getAppBaseUrl() . "/client_payment_result.php?txn=" . $txnId . "&status=" . urlencode($status) . ($msg ? "&msg=".urlencode($msg) : "");
        header("Location: login.php");
        exit;
    }
    redirect_to_result($txnId, $status, $msg);
}

function json_decode_safe($s) {
    $d = json_decode((string)$s, true);
    return is_array($d) ? $d : null;
}

/* =============================================================================
   Load TXN
============================================================================= */
$gateway = strtolower(get_str('gateway',''));
$txnId   = get_int('txn', 0);

if ($gateway === '' || $txnId <= 0) {
    http_response_code(400);
    echo "Invalid return request";
    exit;
}

$txn = PaymentEngine::getTxn($txnId);
if (!$txn) {
    http_response_code(404);
    echo "Transaction not found";
    exit;
}

// Log event (return)
$payloadReturn = $_POST ? $_POST : $_GET;
PaymentEngine::logEvent($gateway, $txn['env'] ?? 'test', $txnId, 'return', headers_safe(), $payloadReturn);

/* =============================================================================
   Optional Ownership Check (if logged in)
   - لو المستخدم مسجل، نتحقق أن txn له
============================================================================= */
if (!empty($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
    if ((int)$txn['user_id'] !== $userId) {
        // لا نمنع التحقق/التحديث (قد يعود من جهاز آخر) لكن لا نعرض له تفاصيل
        // ننقل إلى login
        $_SESSION['after_login_redirect'] = ps_getAppBaseUrl() . "/client_payment_result.php?txn=" . $txnId;
        header("Location: login.php");
        exit;
    }
}

/* =============================================================================
   Gateway: Moyasar
============================================================================= */
if ($gateway === 'moyasar') {

    // Moyasar returns payment id as `id` on callback_url
    $paymentId = get_str('id','');

    if ($paymentId === '') {
        PaymentEngine::setTxnStatus($txnId, 'failed', null, ['ok'=>false,'error'=>'MOYASAR_MISSING_ID','return'=>$_GET]);
        ensure_login_then_result($txnId, 'failed', 'MOYASAR_MISSING_ID');
    }

    $env = $txn['env'] ?? ps_getPaymentEnv();
    $secret = pg_getSetting('moyasar', $env, 'secret_key', '');

    if ($secret === '') {
        PaymentEngine::setTxnStatus($txnId, 'failed', $paymentId, ['ok'=>false,'error'=>'MOYASAR_SECRET_KEY_MISSING']);
        ensure_login_then_result($txnId, 'failed', 'MOYASAR_SECRET_KEY_MISSING');
    }

    // Fetch payment server-to-server
    $url = "https://api.moyasar.com/v1/payments/" . rawurlencode($paymentId);
    $auth = base64_encode($secret . ":");

    $r = PaymentEngine::httpJson("GET", $url, [
        "Authorization: Basic ".$auth,
        "Accept: application/json",
    ]);

    $data = json_decode_safe($r['body'] ?? '') ?? ['raw'=>$r['body'] ?? null];

    if (!empty($r['error'])) {
        PaymentEngine::setTxnStatus($txnId, 'failed', $paymentId, ['ok'=>false,'error'=>'MOYASAR_HTTP_ERROR','http'=>$r,'data'=>$data]);
        ensure_login_then_result($txnId, 'failed', 'MOYASAR_HTTP_ERROR');
    }

    if ((int)$r['code'] >= 400) {
        PaymentEngine::setTxnStatus($txnId, 'failed', $paymentId, ['ok'=>false,'error'=>'MOYASAR_BAD_HTTP','http'=>$r,'data'=>$data]);
        ensure_login_then_result($txnId, 'failed', 'MOYASAR_BAD_HTTP');
    }

    $st = (string)($data['status'] ?? '');
    // في أغلب السيناريوهات "paid" تعني نجاح
    $isPaid = ($st === 'paid') || ($st === 'captured') || ($st === 'authorized');

    if ($isPaid) {
        PaymentEngine::setTxnStatus($txnId, 'paid', $paymentId, ['ok'=>true,'status'=>$st,'payment'=>$data]);
        // TODO: Update your order/subscription here:
        // update_order_paid($txn['order_type'], (int)$txn['order_id'], $txnId, $paymentId, $data);
        ensure_login_then_result($txnId, 'paid', 'PAID');
    } else {
        PaymentEngine::setTxnStatus($txnId, 'failed', $paymentId, ['ok'=>false,'status'=>$st,'payment'=>$data]);
        ensure_login_then_result($txnId, 'failed', $st ?: 'FAILED');
    }
}

/* =============================================================================
   Gateway: HyperPay
============================================================================= */
if ($gateway === 'hyperpay') {

    $resourcePath = get_str('resourcePath','');
    $env = $txn['env'] ?? ps_getPaymentEnv();

    if ($resourcePath === '') {
        PaymentEngine::setTxnStatus($txnId, 'failed', null, ['ok'=>false,'error'=>'HYPERPAY_MISSING_RESOURCEPATH','return'=>$_GET]);
        ensure_login_then_result($txnId, 'failed', 'HYPERPAY_MISSING_RESOURCEPATH');
    }

    $baseUrl     = rtrim(pg_getSetting('hyperpay', $env, 'base_url', ''), '/');
    $entityId    = pg_getSetting('hyperpay', $env, 'entity_id', '');
    $accessToken = pg_getSetting('hyperpay', $env, 'access_token', '');

    if ($baseUrl === '' || $entityId === '' || $accessToken === '') {
        PaymentEngine::setTxnStatus($txnId, 'failed', null, ['ok'=>false,'error'=>'HYPERPAY_KEYS_MISSING']);
        ensure_login_then_result($txnId, 'failed', 'HYPERPAY_KEYS_MISSING');
    }

    // resourcePath might start with /v1/....
    $rp = ltrim($resourcePath, '/');
    $verifyUrl = $baseUrl . "/" . $rp . "?entityId=" . rawurlencode($entityId);

    $r = PaymentEngine::httpJson("GET", $verifyUrl, [
        "Authorization: Bearer ".$accessToken,
        "Accept: application/json",
    ]);

    $data = json_decode_safe($r['body'] ?? '') ?? ['raw'=>$r['body'] ?? null];

    if (!empty($r['error'])) {
        PaymentEngine::setTxnStatus($txnId, 'failed', $txn['provider_ref'] ?? null, ['ok'=>false,'error'=>'HYPERPAY_HTTP_ERROR','http'=>$r,'data'=>$data]);
        ensure_login_then_result($txnId, 'failed', 'HYPERPAY_HTTP_ERROR');
    }

    if ((int)$r['code'] >= 400) {
        PaymentEngine::setTxnStatus($txnId, 'failed', $txn['provider_ref'] ?? null, ['ok'=>false,'error'=>'HYPERPAY_BAD_HTTP','http'=>$r,'data'=>$data]);
        ensure_login_then_result($txnId, 'failed', 'HYPERPAY_BAD_HTTP');
    }

    $resultCode = (string)($data['result']['code'] ?? '');
    $resultDesc = (string)($data['result']['description'] ?? '');
    $paymentId  = (string)($data['id'] ?? ($txn['provider_ref'] ?? ''));

    // قاعدة عامة شائعة: 000.* نجاح
    $isSuccess = (strpos($resultCode, '000.') === 0);

    if ($isSuccess) {
        PaymentEngine::setTxnStatus($txnId, 'paid', $paymentId, ['ok'=>true,'code'=>$resultCode,'desc'=>$resultDesc,'verify'=>$data]);
        // TODO: Update your order/subscription here:
        // update_order_paid($txn['order_type'], (int)$txn['order_id'], $txnId, $paymentId, $data);
        ensure_login_then_result($txnId, 'paid', 'PAID');
    } else {
        PaymentEngine::setTxnStatus($txnId, 'failed', $paymentId, ['ok'=>false,'code'=>$resultCode,'desc'=>$resultDesc,'verify'=>$data]);
        ensure_login_then_result($txnId, 'failed', $resultCode ?: 'FAILED');
    }
}

/* =============================================================================
   Gateway: PayTabs
============================================================================= */
if ($gateway === 'paytabs') {

    // PayTabs return might come via POST or GET
    $payload = $_POST ?: $_GET;

    // غالباً PayTabs يعتمد على callback (server-to-server) لتأكيد النتيجة
    // هنا نعمل:
    // - Mark pending
    // - إن ظهرت مؤشرات نجاح واضحة، نقدر نضعها pending/paid حسب اختيارك (أنا أخليها pending افتراضياً)
    $tranRef = '';
    if (isset($payload['tran_ref'])) $tranRef = (string)$payload['tran_ref'];
    if (isset($payload['tranRef']))  $tranRef = (string)$payload['tranRef'];

    // response status patterns
    $respStatus = '';
    if (isset($payload['respStatus'])) $respStatus = (string)$payload['respStatus'];
    if (isset($payload['payment_result']['response_status'])) $respStatus = (string)$payload['payment_result']['response_status'];

    // default: pending
    PaymentEngine::setTxnStatus($txnId, 'pending', $tranRef ?: ($txn['provider_ref'] ?? null), ['ok'=>true,'return'=>$payload]);

    // إذا تبغى تحويل مباشر لـ paid عند النجاح الواضح، فعّل هذا الجزء:
    $looksSuccess = ($respStatus === 'A' || strtolower($respStatus) === 'success' || strtolower($respStatus) === 'approved');
    if ($looksSuccess) {
        // كثير من الأنظمة تفضّل تأكيد webhook أولاً، لكن نضع “paid” هنا إذا تحب.
        // الأفضل تركها pending حتى يصل webhook.
        // PaymentEngine::setTxnStatus($txnId, 'paid', $tranRef ?: null, ['ok'=>true,'return'=>$payload,'note'=>'marked paid by return']);
    }

    ensure_login_then_result($txnId, 'pending', 'PAYTABS_PENDING');
}

/* =============================================================================
   Unknown gateway
============================================================================= */
PaymentEngine::setTxnStatus($txnId, 'failed', $txn['provider_ref'] ?? null, ['ok'=>false,'error'=>'UNKNOWN_GATEWAY','gateway'=>$gateway]);
ensure_login_then_result($txnId, 'failed', 'UNKNOWN_GATEWAY');