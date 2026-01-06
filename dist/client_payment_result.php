<?php
// client_payment_result.php - Payment Result Screen (Client UI + Dark Mode Ready)
// ==============================================================================

header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

require_once 'db_connect.php';
require_once 'PaymentConfig.php'; // لاستخدام ps_getAppBaseUrl (اختياري)

$userId = (int)$_SESSION['user_id'];
$txnId  = isset($_GET['txn']) ? (int)$_GET['txn'] : 0;
$qsStatus = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$qsMsg    = isset($_GET['msg']) ? trim((string)$_GET['msg']) : '';

if ($txnId <= 0) {
    http_response_code(400);
    die("Invalid transaction");
}

// جلب المعاملة
$stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE id=? LIMIT 1");
$stmt->execute([$txnId]);
$txn = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$txn) {
    http_response_code(404);
    die("Transaction not found");
}

// حماية: المعاملة تخص نفس المستخدم
if ((int)$txn['user_id'] !== $userId) {
    http_response_code(403);
    die("Forbidden");
}

$status = (string)$txn['status'];
$amount = (string)$txn['amount'];
$currency = (string)$txn['currency'];
$gateway = (string)$txn['gateway_code'];
$env = (string)$txn['env'];
$providerRef = (string)($txn['provider_ref'] ?? '');
$createdAt = (string)($txn['created_at'] ?? '');
$updatedAt = (string)($txn['updated_at'] ?? '');

$orderType = (string)($txn['order_type'] ?? '');
$orderId   = (string)($txn['order_id'] ?? '');

$appBase = ps_getAppBaseUrl();

// ===== نقطة ربط الوضع الليلي (عدّلها حسب نظامك) =====
// إذا عندك مثلا: $_SESSION['dark_mode'] أو system_settings('client_dark_mode')...
$clientDark = false;

// مثال شائع:
// $clientDark = (($_SESSION['client_dark_mode'] ?? '0') === '1');

// fallback: لا شيء، سنستخدم localStorage في JS
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>نتيجة الدفع</title>
<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

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
  --warning:#f39c12;
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
  max-width:920px;
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

.hero{
  display:flex;
  gap:14px;
  align-items:flex-start;
  justify-content:space-between;
  flex-wrap:wrap;
}

.badge{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:10px 12px;
  border-radius:999px;
  border:1px solid var(--border);
  font-weight:1000;
}

.badge.success{background:rgba(39,174,96,.12);border-color:rgba(39,174,96,.25)}
.badge.fail{background:rgba(231,76,60,.12);border-color:rgba(231,76,60,.25)}
.badge.pending{background:rgba(243,156,18,.12);border-color:rgba(243,156,18,.25)}
.badge.other{background:rgba(30,136,229,.10);border-color:rgba(30,136,229,.20)}

.h{
  margin:0 0 6px;
  font-size:1.2rem;
  font-weight:1000;
}

.sub{
  margin:0;
  color:var(--muted);
  line-height:1.6;
  font-weight:700;
}

.grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:12px;
  margin-top:14px;
}
@media (max-width:820px){ .grid{grid-template-columns:1fr} }

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

.hr{
  height:1px;
  background:var(--border);
  margin:14px 0;
}

.note{
  margin-top:12px;
  padding:12px;
  border-radius:16px;
  border:1px solid var(--border);
  background:rgba(30,136,229,.07);
  color:var(--text);
  display:flex;
  gap:10px;
  align-items:flex-start;
}

.note.warn{
  background:rgba(243,156,18,.10);
  border-color:rgba(243,156,18,.25);
}
.note.danger{
  background:rgba(231,76,60,.10);
  border-color:rgba(231,76,60,.25);
}

.footer-actions{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  margin-top:14px;
}

/* bottom bar */
.bottom-bar{
  position:fixed;
  left:0;right:0;bottom:0;
  background:var(--card);
  border-top:1px solid var(--border);
  box-shadow:0 -10px 35px rgba(0,0,0,.06);
  padding:10px 14px;
}
.bottom-inner{
  max-width:920px;
  margin:0 auto;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
}
.small{
  color:var(--muted);
  font-weight:800;
  font-size:.9rem;
}
</style>
</head>

<body class="<?= $clientDark ? 'dark' : '' ?>">

<div class="wrap">

  <div class="top">
    <div class="brand">
      <i class="fas fa-receipt"></i>
      <span>نتيجة الدفع</span>
    </div>

    <div class="actions">
      <button class="btn btn-outline" type="button" onclick="toggleDark()">
        <i class="fas fa-moon"></i> الوضع الليلي
      </button>
      <a class="btn btn-light" href="<?= htmlspecialchars($appBase) ?>/client_dashboard.php">
        <i class="fas fa-house"></i> الرئيسية
      </a>
    </div>
  </div>

  <div class="card">

    <div class="hero">
      <div>
        <h2 class="h">
          <?php
            if ($status === 'paid') echo "✅ تم الدفع بنجاح";
            elseif ($status === 'pending' || $status === 'created') echo "⏳ الدفع قيد المعالجة";
            elseif ($status === 'failed') echo "❌ فشل الدفع";
            elseif ($status === 'canceled') echo "⚠️ تم إلغاء العملية";
            else echo "ℹ️ حالة الدفع: ".htmlspecialchars($status);
          ?>
        </h2>

        <p class="sub">
          رقم المعاملة: <span class="mono">#<?= (int)$txnId ?></span>
          — بوابة: <b><?= htmlspecialchars($gateway) ?></b>
          — بيئة: <b><?= htmlspecialchars($env) ?></b>
        </p>

        <?php if ($qsMsg): ?>
          <div class="note warn" style="margin-top:12px">
            <i class="fas fa-circle-info"></i>
            <div>
              <div style="font-weight:1000;margin-bottom:4px">ملاحظة</div>
              <div class="small"><?= htmlspecialchars($qsMsg) ?></div>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div>
        <?php
          $badgeClass = 'other';
          $badgeIcon = 'fa-circle-info';
          $badgeText = $status;

          if ($status === 'paid'){ $badgeClass='success'; $badgeIcon='fa-check'; $badgeText='PAID'; }
          elseif ($status === 'pending' || $status === 'created'){ $badgeClass='pending'; $badgeIcon='fa-hourglass-half'; $badgeText='PENDING'; }
          elseif ($status === 'failed'){ $badgeClass='fail'; $badgeIcon='fa-xmark'; $badgeText='FAILED'; }
          elseif ($status === 'canceled'){ $badgeClass='pending'; $badgeIcon='fa-ban'; $badgeText='CANCELED'; }
        ?>
        <div class="badge <?= $badgeClass ?>">
          <i class="fas <?= $badgeIcon ?>"></i>
          <span><?= htmlspecialchars($badgeText) ?></span>
        </div>
      </div>
    </div>

    <div class="hr"></div>

    <div class="grid">
      <div class="item">
        <div class="k">المبلغ</div>
        <div class="v"><?= htmlspecialchars($amount) ?> <?= htmlspecialchars($currency) ?></div>
      </div>

      <div class="item">
        <div class="k">الطلب المرتبط</div>
        <div class="v"><?= htmlspecialchars($orderType) ?> — <span class="mono">#<?= htmlspecialchars($orderId) ?></span></div>
      </div>

      <div class="item">
        <div class="k">المرجع لدى شركة الدفع</div>
        <div class="v mono"><?= $providerRef ? htmlspecialchars($providerRef) : '—' ?></div>
      </div>

      <div class="item">
        <div class="k">آخر تحديث</div>
        <div class="v"><?= htmlspecialchars($updatedAt ?: '—') ?></div>
      </div>

      <div class="item">
        <div class="k">وقت الإنشاء</div>
        <div class="v"><?= htmlspecialchars($createdAt ?: '—') ?></div>
      </div>

      <div class="item">
        <div class="k">ملاحظة</div>
        <div class="v">
          <?php if ($status === 'paid'): ?>
            تم تأكيد الدفع. يمكنك متابعة الخدمة.
          <?php elseif ($status === 'pending' || $status === 'created'): ?>
            قد يحتاج الأمر ثوانٍ حتى يصل تأكيد الويبهوك من شركة الدفع.
          <?php elseif ($status === 'failed'): ?>
            يمكنك إعادة المحاولة أو اختيار طريقة أخرى.
          <?php else: ?>
            يمكنك التحديث أو الرجوع.
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($status === 'pending' || $status === 'created'): ?>
      <div class="note warn">
        <i class="fas fa-hourglass-half"></i>
        <div>
          <div style="font-weight:1000;margin-bottom:4px">الدفع قيد المعالجة</div>
          <div class="small">
            إذا كانت بوابتك تعتمد على Webhook (مثل PayTabs غالباً)، قد يظهر النجاح بعد وصول تأكيد السيرفر.
            اضغط “تحديث الحالة” بعد قليل.
          </div>
        </div>
      </div>
    <?php elseif ($status === 'failed'): ?>
      <div class="note danger">
        <i class="fas fa-triangle-exclamation"></i>
        <div>
          <div style="font-weight:1000;margin-bottom:4px">فشل الدفع</div>
          <div class="small">يمكنك إعادة المحاولة أو الرجوع للطلب واختيار طريقة دفع أخرى.</div>
        </div>
      </div>
    <?php endif; ?>

    <div class="footer-actions">
      <a class="btn btn-outline" href="?txn=<?= (int)$txnId ?>"><i class="fas fa-rotate"></i> تحديث الحالة</a>

      <a class="btn btn-primary" href="<?= htmlspecialchars($appBase) ?>/client_orders.php">
        <i class="fas fa-list"></i> الذهاب لطلباتي
      </a>

      <a class="btn btn-light" href="<?= htmlspecialchars($appBase) ?>/client_dashboard.php">
        <i class="fas fa-house"></i> العودة للرئيسية
      </a>
    </div>

  </div>
</div>

<div class="bottom-bar">
  <div class="bottom-inner">
    <div class="small">
      <?= ($status === 'paid')
          ? '✅ تمت العملية بنجاح'
          : (($status === 'pending' || $status === 'created') ? '⏳ بانتظار التأكيد' : '⚠️ تحقق من الحالة') ?>
    </div>
    <div class="small mono">TXN#<?= (int)$txnId ?></div>
  </div>
</div>

<script>
function toggleDark(){
  document.body.classList.toggle('dark');
  try {
    localStorage.setItem('client_dark_mode', document.body.classList.contains('dark') ? '1' : '0');
  } catch(e){}
}
(function initDark(){
  try {
    const v = localStorage.getItem('client_dark_mode');
    if (v === '1') document.body.classList.add('dark');
  } catch(e){}
})();
</script>

</body>
</html>