<?php
header('Content-Type: text/plain; charset=utf-8');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// إذا الاستضافة تمنع عرض الأخطاء، على الأقل نسجلها
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/pay_step_test.log');

echo "STEP TEST OK\n";
echo "PHP_VERSION: " . PHP_VERSION . "\n\n";

function step($label){
  echo "==> ".$label."\n";
}

step("1) include auth_admin.php");
require_once __DIR__ . "/auth_admin.php";
step("✓ auth_admin.php OK");

step("2) include db_connect.php");
require_once __DIR__ . "/db_connect.php";
step("✓ db_connect.php OK");
echo isset($pdo) ? "✓ PDO variable exists\n" : "✗ PDO variable NOT set (db_connect)\n";

step("3) include PaymentConfig.php");
require_once __DIR__ . "/PaymentConfig.php";
step("✓ PaymentConfig.php OK");

// اختبارات دوال إعدادات (إذا موجودة)
if (function_exists('ps_getSystemSetting')) {
  step("4) ps_getSystemSetting() exists => trying call");
  $v = ps_getSystemSetting('admin_dark_mode','0');
  echo "admin_dark_mode = ".$v."\n";
} else {
  echo "NOTE: ps_getSystemSetting() NOT FOUND\n";
}

step("5) include PaymentEngine.php (optional)");
if (file_exists(__DIR__ . "/PaymentEngine.php")) {
  require_once __DIR__ . "/PaymentEngine.php";
  echo class_exists('PaymentEngine') ? "✓ PaymentEngine class OK\n" : "✗ PaymentEngine class NOT found\n";
} else {
  echo "SKIP: PaymentEngine.php missing\n";
}

step("6) include PaymentApply.php (optional)");
if (file_exists(__DIR__ . "/PaymentApply.php")) {
  require_once __DIR__ . "/PaymentApply.php";
  echo class_exists('PaymentApply') ? "✓ PaymentApply class OK\n" : "✗ PaymentApply class NOT found\n";
} else {
  echo "SKIP: PaymentApply.php missing\n";
}

step("DONE ✅ إذا وصلت هنا بدون 500، المشكلة ليست في الملفات بل داخل صفحة transactions نفسها أو جداول DB");
