<?php
// payment_start.php - Unified Payment Entry Point (Client)
// ==============================================================================
// INPUT (POST):
//  - order_type: string (e.g. package_subscription / order / invoice)
//  - order_id  : int
//  - amount    : float
//  - currency  : string (default SAR)
//  - csrf_token
//
// OUTPUT:
//  - Redirect to gateway checkout URL (or local checkout page) if ok
//  - On failure: redirect to client_payment_result.php with status=failed
// ==============================================================================

header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

require_once 'db_connect.php';
require_once 'PaymentEngine.php'; // includes PaymentConfig + db + gateway adapters via engine

/* =============================================================================
   CSRF
============================================================================= */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_check_or_die(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        die("Method Not Allowed");
    }
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die("CSRF token invalid");
    }
}

/* =============================================================================
   Helpers
============================================================================= */
function post_str(string $k, string $d=''): string {
    return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d;
}
function post_int(string $k, int $d=0): int {
    return isset($_POST[$k]) ? (int)$_POST[$k] : $d;
}
function post_float(string $k, float $d=0): float {
    return isset($_POST[$k]) ? (float)$_POST[$k] : $d;
}
function safe_order_type(string $s): string {
    // يسمح فقط: حروف/أرقام/underscore
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9_]+/i','', $s);
    return (string)$s;
}
function redirect_result(int $txnId, string $status='failed', string $msg=''): void {
    $base = ps_getAppBaseUrl();
    $url = $base . "/client_payment_result.php?txn=" . $txnId . "&status=" . urlencode($status);
    if ($msg !== '') $url .= "&msg=" . urlencode($msg);
    header("Location: ".$url);
    exit;
}

/* =============================================================================
   Start
============================================================================= */
csrf_check_or_die();

$userId = (int)$_SESSION['user_id'];
$orderType = safe_order_type(post_str('order_type', ''));
$orderId   = post_int('order_id', 0);
$amount    = post_float('amount', 0);
$currency  = strtoupper(post_str('currency', 'SAR'));

// Basic validation
if ($orderType === '' || $orderId <= 0 || $amount <= 0) {
    // لا يوجد txn هنا
    http_response_code(400);
    die("Invalid payment request");
}
if (!preg_match('/^[A-Z]{3,5}$/', $currency)) {
    $currency = 'SAR';
}

// OPTIONAL: حماية إضافية - تحقق من أن هذا الـ order يعود لهذا المستخدم
// لأن الأنظمة تختلف، لذلك خلّيته Placeholder:
// - مثال: لو orderType=package_subscription تحقق من جدول subscriptions أن user_id = session user_id
// - مثال: لو orderType=order تحقق من جدول orders ...
//
// إذا أعطيتني أسماء جداولك/أعمدتك سأربطها فعلياً 100% بدون Placeholder.
function assert_order_ownership(string $orderType, int $orderId, int $userId): bool {
    // TODO: implement per your schema
    return true;
}
if (!assert_order_ownership($orderType, $orderId, $userId)) {
    http_response_code(403);
    die("Forbidden");
}

/* =============================================================================
   Create TXN + Start Gateway
============================================================================= */
$txnId = 0;

try {
    // 1) إنشاء معاملة دفع في ledger
    $txnId = PaymentEngine::createTxn($orderType, $orderId, $userId, $amount, $currency);

    // 2) بيانات العميل (تغذية للبوابات التي تحتاج اسم/ايميل/هاتف)
    $customer = [
        'name'  => (string)($_SESSION['name'] ?? ('User '.$userId)),
        'email' => (string)($_SESSION['email'] ?? ''),
        'phone' => (string)($_SESSION['phone'] ?? ''),
        'city'  => (string)($_SESSION['city'] ?? 'Riyadh'),
        'country' => 'SA',
        'street1' => (string)($_SESSION['address'] ?? 'N/A'),
        'zip' => (string)($_SESSION['zip'] ?? '00000'),
        'state' => (string)($_SESSION['state'] ?? 'Riyadh'),
    ];

    // 3) تشغيل الدفع عبر المحرك (يرجع redirect_url)
    $res = PaymentEngine::startPayment($txnId, $customer);

    if (empty($res['ok']) || empty($res['redirect_url'])) {
        PaymentEngine::setTxnStatus($txnId, 'failed', null, $res);
        redirect_result($txnId, 'failed', $res['error'] ?? 'PAYMENT_INIT_FAILED');
    }

    // 4) Redirect للصفحة الصحيحة (قد تكون صفحة checkout محلية أو redirect خارجي)
    header("Location: ".$res['redirect_url']);
    exit;

} catch (Exception $e) {

    // إذا txnId انشأناه نسجل الفشل
    if ($txnId > 0) {
        try {
            PaymentEngine::setTxnStatus($txnId, 'failed', null, [
                'ok' => false,
                'error' => 'EXCEPTION',
                'message' => $e->getMessage(),
            ]);
        } catch (Exception $e2) {
            // ignore
        }
        redirect_result($txnId, 'failed', 'EXCEPTION');
    }

    http_response_code(500);
    die("Payment error");
}