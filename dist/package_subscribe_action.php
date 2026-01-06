<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');

require_once 'db_connect.php';
require_once 'PaymentConfig.php';

if (session_status() == PHP_SESSION_NONE) session_start();
$client_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($client_id <= 0) { header("Location: login.php"); exit; }

$package_id = isset($_GET['package_id']) ? (int)$_GET['package_id'] : 0;
if ($package_id <= 0) { header("Location: client_browse_packages.php"); exit; }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// جلب الباقة
$stmt = $pdo->prepare("SELECT * FROM packages WHERE id=? AND is_active=1 LIMIT 1");
$stmt->execute([$package_id]);
$pkg = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$pkg) { header("Location: client_browse_packages.php"); exit; }

// تحقق اشتراك قائم
$stmtS = $pdo->prepare("SELECT subscription_status FROM client_details WHERE user_id=? LIMIT 1");
$stmtS->execute([$client_id]);
$st = (string)$stmtS->fetchColumn();
if (in_array($st, ['active','paused','pending_payment'], true)) {
    header("Location: client_browse_packages.php?blocked=1");
    exit;
}

// بنك
$bank_enabled   = ps_getSystemSetting('bank_transfer_enabled','1') === '1';
$bank_name      = ps_getSystemSetting('bank_name','');
$account_name   = ps_getSystemSetting('account_name','');
$iban           = ps_getSystemSetting('iban','');
$stc            = ps_getSystemSetting('stc_pay_number','');

// أونلاين
$rt = pg_getRuntimeConfig();
$gateway = $rt['gateway'];
$env     = $rt['env'];
$set     = $rt['settings'];

$online_available = false;
$gateway_name = $gateway ?: '';
if ($gateway) {
    $stmtG = $pdo->prepare("SELECT name_ar, is_active FROM payment_gateways WHERE code=? LIMIT 1");
    $stmtG->execute([$gateway]);
    $g = $stmtG->fetch(PDO::FETCH_ASSOC);
    if ($g && (int)$g['is_active']===1) {
        $gateway_name = $g['name_ar'] ?: $gateway;
        $online_available = !empty($set['secret_key']) || !empty($set['api_key']) || (!empty($set['public_key']) && !empty($set['secret_key']));
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>اشتراك الباقة</title>
<link rel="stylesheet" href="client_style.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
body{background:#f8f9fa;font-family:'Tajawal',sans-serif;margin:0;padding:20px;padding-bottom:90px}
.card{background:#fff;border-radius:18px;padding:18px;margin-bottom:14px;box-shadow:0 10px 25px rgba(0,0,0,.04)}
.h{font-weight:900;font-size:1.2rem;margin:0 0 10px}
.row{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
.badge{background:#111827;color:#fff;padding:8px 12px;border-radius:999px;font-weight:900;font-size:.85rem}
.pay-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.opt{display:none}
.pcard{border:2px solid #f1f2f6;border-radius:16px;padding:14px;text-align:center;cursor:pointer}
.opt:checked + .pcard{border-color:#8e44ad;background:#faf5ff}
.btn{width:100%;padding:16px;border:none;border-radius:16px;background:#8e44ad;color:#fff;font-weight:900;font-size:1.05rem;cursor:pointer}
.bankinfo{display:none;border:2px dashed #e5e7eb;border-radius:16px;padding:14px;background:#fafafa;margin-top:10px;font-weight:800;line-height:1.9}
</style>
</head>
<body>

<div class="card">
  <div class="row" style="align-items:center">
    <div>
      <div class="h"><?php echo htmlspecialchars($pkg['name']); ?></div>
      <div style="color:#6b7280;font-weight:800">
        مدة: <?php echo (int)$pkg['duration_days']; ?> يوم |
        وجبات يوميًا: <?php echo (int)$pkg['meals_per_day']; ?> |
        خيارات تظهر: <?php echo (int)$pkg['options_visible_count']; ?> |
        تختار: <?php echo (int)$pkg['options_selectable_count']; ?>
      </div>
    </div>
    <div class="badge"><?php echo number_format((float)$pkg['price'],0); ?> ر.س</div>
  </div>
</div>

<div class="card">
  <div class="h">طريقة الدفع</div>
  <div class="pay-grid">
    <?php if ($online_available): ?>
      <label>
        <input class="opt" type="radio" name="pay" value="online" checked>
        <div class="pcard">
          <i class="far fa-credit-card" style="font-size:1.8rem;color:#8e44ad"></i>
          <div style="font-weight:900;margin-top:6px">دفع إلكتروني</div>
          <div style="color:#6b7280;font-weight:800"><?php echo htmlspecialchars($gateway_name); ?> (<?php echo htmlspecialchars($env); ?>)</div>
        </div>
      </label>
    <?php endif; ?>

    <?php if ($bank_enabled): ?>
      <label>
        <input class="opt" type="radio" name="pay" value="bank" <?php echo $online_available?'':'checked'; ?>>
        <div class="pcard">
          <i class="fas fa-university" style="font-size:1.8rem;color:#8e44ad"></i>
          <div style="font-weight:900;margin-top:6px">تحويل بنكي</div>
          <div style="color:#6b7280;font-weight:800">اعرض بيانات التحويل</div>
        </div>
      </label>
    <?php endif; ?>
  </div>

  <?php if ($bank_enabled): ?>
    <div class="bankinfo" id="bankinfo">
      <div>اسم البنك: <?php echo htmlspecialchars($bank_name); ?></div>
      <div>اسم الحساب: <?php echo htmlspecialchars($account_name); ?></div>
      <div>IBAN: <?php echo htmlspecialchars($iban); ?></div>
      <div>STC Pay: <?php echo htmlspecialchars($stc); ?></div>
      <div style="margin-top:8px;color:#6b7280">بعد التحويل سيتم تأكيد اشتراكك من الإدارة.</div>
    </div>
  <?php endif; ?>
</div>

<button class="btn" onclick="subscribeNow()"><i class="fas fa-check-circle"></i> تأكيد الاشتراك</button>

<script>
function syncBank(){
  const v = document.querySelector('input[name="pay"]:checked')?.value;
  const box = document.getElementById('bankinfo');
  if(!box) return;
  box.style.display = (v==='bank') ? 'block' : 'none';
}
document.addEventListener('change', (e)=>{
  if(e.target && e.target.name==='pay') syncBank();
});
syncBank();

async function subscribeNow(){
  const pay = document.querySelector('input[name="pay"]:checked')?.value || 'bank';

  const fd = new FormData();
  fd.append('csrf_token', '<?php echo htmlspecialchars($csrf); ?>');
  fd.append('package_id', '<?php echo (int)$package_id; ?>');
  fd.append('payment_method', pay);

  const res = await fetch('package_subscribe_action.php', { method:'POST', body: fd });
  const data = await res.json();

  if(data.status === 'success'){
    if(data.payment_url){
      window.location.href = data.payment_url;
      return;
    }
    alert(data.message || 'تم إنشاء الاشتراك');
    window.location.href = 'client_dashboard.php';
  } else {
    alert(data.message || 'حدث خطأ');
  }
}
</script>

</body>
</html>