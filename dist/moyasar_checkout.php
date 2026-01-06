<?php
// moyasar_checkout.php - Moyasar Payment Form (Client UI + Dark Mode)
// ==============================================================================

header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

require_once 'db_connect.php';
require_once 'PaymentConfig.php';

$userId = (int)$_SESSION['user_id'];
$txnId  = isset($_GET['txn']) ? (int)$_GET['txn'] : 0;

if ($txnId <= 0) {
    http_response_code(400);
    die("Invalid transaction");
}

// جلب txn
$stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE id=? LIMIT 1");
$stmt->execute([$txnId]);
$txn = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$txn) { http_response_code(404); die("Transaction not found"); }
if ((int)$txn['user_id'] !== $userId) { http_response_code(403); die("Forbidden"); }

// تحقق من البوابة
if (($txn['gateway_code'] ?? '') !== 'moyasar') {
    // لو كانت بوابة أخرى، رجعه لنتيجة الدفع
    header("Location: ".ps_getAppBaseUrl()."/client_payment_result.php?txn=".$txnId."&status=pending&msg=WRONG_GATEWAY");
    exit;
}

// إذا مدفوع
if (($txn['status'] ?? '') === 'paid') {
    header("Location: ".ps_getAppBaseUrl()."/client_payment_result.php?txn=".$txnId."&status=paid");
    exit;
}

$env = $txn['env'] ?? ps_getPaymentEnv();
$publishable = pg_getSetting('moyasar', $env, 'publishable_key', '');
if ($publishable === '') {
    header("Location: ".ps_getAppBaseUrl()."/client_payment_result.php?txn=".$txnId."&status=failed&msg=MOYASAR_PUBLISHABLE_KEY_MISSING");
    exit;
}

// المبلغ بالهللة
$amountSar = (float)$txn['amount'];
$amountSmallest = (int)round($amountSar * 100);

// رابط العودة
$callbackUrl = ps_getAppBaseUrl() . "/payment_return.php?gateway=moyasar&txn=" . $txnId;

// نقطة ربط الوضع الليلي (ضع نظامك الحقيقي هنا إن وجد)
$clientDark = false;
// مثال:
// $clientDark = (($_SESSION['client_dark_mode'] ?? '0') === '1');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>الدفع عبر Moyasar</title>

<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

<!-- Moyasar Payment Form -->
<script src="https://cdn.moyasar.com/mpf/1.14.0/moyasar.js"></script>
<link rel="stylesheet" href="https://cdn.moyasar.com/mpf/1.14.0/moyasar.css" />

<style>
:root{
  --bg:#f6f7fb;
  --card:#ffffff;
  --text:#1f2d3d;
  --muted:#6b7280;
  --border:#e8eaee;
  --primary:#1e88e5;
  --success:#27ae60;
  --danger:#e74c3c;
  --shadow:0 14px 35px rgba(0,0,0,.07);
  --radius:18px;
}

body.dark{
  --bg:#0b1224;
  --card:#0f1a33;
  --text:#e5e7eb;
  --muted:#9ca3af;
  --border:#1c2a4a;
  --shadow:0 16px 48px rgba(0,0,0,.35);
}

body{
  background:var(--bg);
  color:var(--text);
  margin:0;
  font-family: system-ui, -apple-system, "Segoe UI", Tahoma, Arial;
}

.wrap{
  max-width:980px;
  margin:0 auto;
  padding:18px 14px 90px;
}

.top{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  padding:10px 2px 14px;
}

.brand{
  display:flex;
  align-items:center;
  gap:10px;
  font-weight:1000;
  font-size:1.15rem;
}

.actions{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
}

.btn{
  border:none;
  border-radius:14px;
  padding:10px 14px;
  cursor:pointer;
  font-weight:1000;
  display:inline-flex;
  align-items:center;
  gap:8px;
  transition:.15s;
}

.btn:hover{transform:translateY(-1px)}
.btn-primary{background:var(--primary);color:#fff}
.btn-outline{background:transparent;color:var(--text);border:1px solid var(--border)}
.btn-light{background:rgba(107,114,128,.12);color:var(--text);border:1px solid var(--border)}

.card{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:var(--radius);
  box-shadow:var(--shadow);
  padding:18px;
}

.grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:12px;
  margin-top:14px;
}
@media (max-width:920px){ .grid{grid-template-columns:1fr} }

.item{
  padding:12px;
  border-radius:16px;
  border:1px solid var(--border);
  background:rgba(107,114,128,.05);
}
.item .k{
  color:var(--muted);
  font-weight:900;
  font-size:.9rem;
  margin-bottom:6px;
}
.item .v{
  font-weight:1000;
  word-break:break-word;
}
.mono{
  font-family: ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;
  letter-spacing:.2px;
}

.note{
  margin-top:12px;
  padding:12px;
  border-radius:16px;
  border:1px solid var(--border);
  background:rgba(30,136,229,.07);
  display:flex;
  gap:10px;
  align-items:flex-start;
}

.hr{height:1px;background:var(--border);margin:14px 0}

/* Make Moyasar form blend with our theme */
.moyasar-form .moyasar-form__button{
  border-radius:14px !important;
  font-weight:900 !important;
}
</style>
</head>

<body class="<?= $clientDark ? 'dark' : '' ?>">

<div class="wrap">

  <div class="top">
    <div class="brand">
      <i class="fas fa-credit-card"></i>
      <span>الدفع عبر Moyasar</span>
    </div>

    <div class="actions">
      <button class="btn btn-outline" type="button" onclick="toggleDark()">
        <i class="fas fa-moon"></i> الوضع الليلي
      </button>
      <a class="btn btn-light" href="<?= htmlspecialchars(ps_getAppBaseUrl()) ?>/client_dashboard.php">
        <i class="fas fa-house"></i> الرئيسية
      </a>
    </div>
  </div>

  <div class="card">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap">
      <div>
        <h2 style="margin:0 0 6px;font-weight:1000;font-size:1.2rem">إتمام الدفع</h2>
        <p style="margin:0;color:var(--muted);font-weight:800;line-height:1.7">
          الرجاء إدخال بيانات الدفع لإكمال العملية. بعد الإتمام سيتم تحويلك تلقائياً لصفحة النتيجة.
        </p>
      </div>
      <div class="item" style="min-width:220px">
        <div class="k">رقم العملية</div>
        <div class="v mono">TXN#<?= (int)$txnId ?></div>
      </div>
    </div>

    <div class="hr"></div>

    <div class="grid">
      <div class="item">
        <div class="k">المبلغ</div>
        <div class="v"><?= htmlspecialchars(number_format($amountSar, 2)) ?> <?= htmlspecialchars($txn['currency']) ?></div>
      </div>

      <div class="item">
        <div class="k">الطلب المرتبط</div>
        <div class="v"><?= htmlspecialchars($txn['order_type']) ?> — <span class="mono">#<?= htmlspecialchars($txn['order_id']) ?></span></div>
      </div>

      <div class="item">
        <div class="k">البيئة</div>
        <div class="v"><?= htmlspecialchars($env) ?></div>
      </div>

      <div class="item">
        <div class="k">طريقة الدفع</div>
        <div class="v">Credit Card / Apple Pay / STC Pay</div>
      </div>
    </div>

    <div class="note">
      <i class="fas fa-shield-halved"></i>
      <div>
        <div style="font-weight:1000;margin-bottom:4px">تنبيه أمني</div>
        <div style="color:var(--muted);font-weight:800;line-height:1.7">
          لا يتم تخزين بيانات البطاقة داخل موقعك. تتم العملية عبر نموذج الدفع المعتمد من مزود الخدمة.
        </div>
      </div>
    </div>

    <div style="height:14px"></div>

    <!-- Moyasar Form Mount -->
    <div class="moyasar-form"></div>

    <div style="height:10px"></div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">
      <a class="btn btn-outline" href="<?= htmlspecialchars(ps_getAppBaseUrl()) ?>/client_payment_result.php?txn=<?= (int)$txnId ?>">
        <i class="fas fa-receipt"></i> عرض حالة العملية
      </a>
    </div>
  </div>

</div>

<script>
function toggleDark(){
  document.body.classList.toggle('dark');
  try { localStorage.setItem('client_dark_mode', document.body.classList.contains('dark') ? '1' : '0'); } catch(e){}
}
(function initDark(){
  try {
    const v = localStorage.getItem('client_dark_mode');
    if (v === '1') document.body.classList.add('dark');
  } catch(e){}
})();

// Initialize Moyasar Payment Form
// amount is in halalah (SAR * 100)
Moyasar.init({
  element: '.moyasar-form',
  amount: <?= (int)$amountSmallest ?>,
  currency: '<?= htmlspecialchars($txn['currency']) ?>',
  description: '<?= htmlspecialchars($txn['order_type'].'#'.$txn['order_id'].' / TXN#'.$txnId) ?>',
  publishable_api_key: '<?= htmlspecialchars($publishable) ?>',
  callback_url: '<?= htmlspecialchars($callbackUrl) ?>',
  methods: ['creditcard', 'applepay', 'stcpay']
});
</script>

</body>
</html>