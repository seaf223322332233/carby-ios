<?php
ob_start();
require_once 'auth_admin.php';
header("Content-Type: text/plain; charset=utf-8");
echo "auth ok\n";
// pay_txn_debug.php - Hard Debug (prints checkpoints even with fatal errors)
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Content-Type: text/plain; charset=utf-8");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/pay_txn_debug_error.log');
error_reporting(E_ALL);

// اطبع أي Fatal عند الإنهاء
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "\n\n===== FATAL ERROR =====\n";
        echo $e['message'] . "\n";
        echo "FILE: " . $e['file'] . "\n";
        echo "LINE: " . $e['line'] . "\n";
        echo "=======================\n";
    }
});

function step($msg){
    echo "==> $msg\n";
    @ob_flush(); @flush();
}

step("PHP_VERSION: " . PHP_VERSION);
step("1) include auth_admin.php");
require_once __DIR__ . "/auth_admin.php";
step("✓ auth_admin.php OK");

step("2) include db_connect.php");
require_once __DIR__ . "/db_connect.php";
step("✓ db_connect.php OK");

if (!isset($pdo) || !($pdo instanceof PDO)) {
    step("❌ PDO not ready. Check db_connect.php");
    exit;
}
step("✓ PDO OK");

step("3) include PaymentConfig.php");
require_once __DIR__ . "/PaymentConfig.php";
step("✓ PaymentConfig.php OK");

if (!function_exists('ps_getSystemSetting')) {
    step("❌ ps_getSystemSetting() missing");
    exit;
}
step("✓ ps_getSystemSetting OK: admin_dark_mode=" . ps_getSystemSetting('admin_dark_mode','?'));

step("4) quick DB check SELECT 1");
$pdo->query("SELECT 1");
step("✓ SELECT 1 OK");

// فحص الجداول الأساسية
$tables = ['payment_transactions','payment_events','payment_fulfillments','payment_gateways'];
foreach ($tables as $t){
    step("5) check table: $t");
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$t]);
    $ok = (bool)$st->fetchColumn();
    step($ok ? "✓ table exists: $t" : "❌ MISSING table: $t");
}
step("6) describe payment_transactions");
try{
    $d = $pdo->query("DESCRIBE payment_transactions")->fetchAll(PDO::FETCH_ASSOC);
    step("✓ DESCRIBE OK. columns count=" . count($d));
}catch(Throwable $e){
    step("❌ DESCRIBE FAILED: ".$e->getMessage());
}

step("7) try include sidebar.php (common 500 source)");
try {
    // بدل include الطبيعي، نخليه مع output buffering حتى لا يكسر النص
    ob_start();
    include __DIR__ . "/sidebar.php";
    ob_end_clean();
    step("✓ sidebar.php OK");
} catch (Throwable $e) {
    step("❌ sidebar.php ERROR: " . $e->getMessage());
}

step("8) run small query from transactions");
try{
    $q = $pdo->query("SELECT id,status,amount,currency,created_at FROM payment_transactions ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    step("✓ query OK. rows=" . count($q));
    foreach($q as $r){
        step(" - TXN#{$r['id']} {$r['status']} {$r['amount']}{$r['currency']} {$r['created_at']}");
    }
}catch(Throwable $e){
    step("❌ query FAILED: " . $e->getMessage());
}

step("DONE ✅ إذا وصلت هنا فالمشكلة ليست DB ولا includes، بل في كود الصفحة نفسها.");
