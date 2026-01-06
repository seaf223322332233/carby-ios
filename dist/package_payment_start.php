<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');
session_start();

require_once 'db_connect.php';
require_once 'PaymentConfig.php';

$tx_id = isset($_GET['tx_id']) ? (int)$_GET['tx_id'] : 0;
$t = $_GET['t'] ?? '';

if ($tx_id <= 0) die("Invalid transaction");

if (empty($_SESSION['sub_pay_tokens'][$tx_id]) || !hash_equals($_SESSION['sub_pay_tokens'][$tx_id], $t)) {
    die("Invalid token");
}

// جلب المعاملة
$stmt = $pdo->prepare("SELECT * FROM subscription_transactions WHERE id=? LIMIT 1");
$stmt->execute([$tx_id]);
$tx = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tx) die("Transaction not found");

// إعدادات الدفع الحالية
$cfg = pg_getRuntimeConfig();
$gateway = $cfg['gateway'];
$env = $cfg['env'];
$set = $cfg['settings'];

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>الدفع للاشتراك</title>
<link rel="stylesheet" href="client_style.css?v=<?php echo time(); ?>">
<style>
body{font-family:'Tajawal',sans-serif;background:#f8f9fa;margin:0;padding:20px}
.card{background:#fff;border-radius:18px;padding:18px;box-shadow:0 10px 25px rgba(0,0,0,.04);max-width:520px;margin:0 auto}
.h{font-weight:900;font-size:1.2rem;margin:0 0 10px}
.p{color:#6b7280;font-weight:800;line-height:1.8}
.btn{display:block;text-align:center;margin-top:14px;padding:14px;border-radius:14px;background:#111827;color:#fff;text-decoration:none;font-weight:900}
</style>
</head>
<body>
<div class="card">
  <div class="h">جاري تجهيز الدفع للاشتراك</div>
  <div class="p">
    المعاملة: #<?php echo (int)$tx_id; ?><br>
    المبلغ: <?php echo number_format((float)$tx['amount'],2); ?> ر.س<br>
    بوابة الدفع: <?php echo htmlspecialchars($gateway ?: 'غير محددة'); ?> (<?php echo htmlspecialchars($env); ?>)
  </div>

  <div class="p" style="margin-top:10px">
    ✅ الربط البنيوي جاهز.  
    الآن يلزم “تفعيل التكامل” حسب البوابة داخل هذا الملف (Stripe/Moyasar/MyFatoorah...).
  </div>

  <a class="btn" href="client_dashboard.php">رجوع للوحة العميل</a>
</div>
</body>
</html>
