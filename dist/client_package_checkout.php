<?php
// client_package_checkout.php - Matches client_dashboard theme + safe payment methods fetch
// ==============================================================================

header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'db_connect.php';
require_once 'PaymentConfig.php';

$client_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($client_id <= 0) { header("Location: login.php"); exit; }

$package_id = isset($_GET['package_id']) ? (int)$_GET['package_id'] : 0;
if ($package_id <= 0) { header("Location: client_browse_packages.php"); exit; }

// CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

// منع الاشتراك إذا قائم
$currentStatus = 'none';
try {
    $stmtS = $pdo->prepare("SELECT subscription_status FROM client_details WHERE user_id=? LIMIT 1");
    $stmtS->execute([$client_id]);
    $v = $stmtS->fetchColumn();
    if ($v !== false && $v !== null) $currentStatus = (string)$v;
} catch (Exception $e) {}

if (in_array($currentStatus, ['active','paused','pending_payment'], true)) {
    header("Location: client_browse_packages.php?blocked=1");
    exit;
}

// جلب الباقة
$stmt = $pdo->prepare("SELECT * FROM packages WHERE id=? AND is_active=1 LIMIT 1");
$stmt->execute([$package_id]);
$pkg = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$pkg) { header("Location: client_browse_packages.php"); exit; }

/**
 * ✅ FIX: بعض بيئاتك لا تحتوي pm_getAvailableMethods()
 * حتى لا يحدث 500، نضع fallback هنا.
 */
if (!function_exists('pm_getAvailableMethods')) {
    function pm_getAvailableMethods() {
        $env = function_exists('ps_getSystemSetting') ? ps_getSystemSetting('payment_env', 'test') : 'test';

        $online = [
            'enabled' => false,
            'env' => $env,
            'gateway_code' => '',
            'gateway_name' => ''
        ];

        // إذا عندك pg_getRuntimeConfig (موجود حسب فحصك)
        if (function_exists('pg_getRuntimeConfig')) {
            $cfg = pg_getRuntimeConfig($env); // نتوقع يرجع بيانات البوابة الفعالة
            // نحاول بقدر الإمكان بدون افتراضات صارمة
            $gcode = $cfg['gateway_code'] ?? ($cfg['active_gateway'] ?? '');
            $gname = $cfg['gateway_name'] ?? ($cfg['name_ar'] ?? '');
            if (!empty($gcode)) {
                $online['enabled'] = true;
                $online['gateway_code'] = (string)$gcode;
                $online['gateway_name'] = (string)$gname;
            }
        }

        // التحويل البنكي من system_settings
        $bank_enabled = function_exists('ps_getSystemSetting') ? (ps_getSystemSetting('bank_transfer_enabled', '1') === '1') : false;

        $bank = [
            'enabled' => $bank_enabled,
            'bank_name' => function_exists('ps_getSystemSetting') ? ps_getSystemSetting('bank_name', '') : '',
            'account_name' => function_exists('ps_getSystemSetting') ? ps_getSystemSetting('account_name', '') : '',
            'iban' => function_exists('ps_getSystemSetting') ? ps_getSystemSetting('iban', '') : '',
            'stc_pay_number' => function_exists('ps_getSystemSetting') ? ps_getSystemSetting('stc_pay_number', '') : '',
        ];

        // الكاش: إن لم يكن لديك إعداد خاص، نجعله true افتراضياً
        $cash_enabled = function_exists('ps_getSystemSetting') ? (ps_getSystemSetting('cash_enabled', '1') === '1') : true;

        return [
            'online' => $online,
            'bank'   => $bank,
            'cash'   => ['enabled' => $cash_enabled],
        ];
    }
}

// طرق الدفع (من لوحة المدير)
$available = pm_getAvailableMethods();
$anyPay = (!empty($available['online']['enabled']) || !empty($available['bank']['enabled']) || !empty($available['cash']['enabled']));

// اختيار افتراضي
$defaultMethod = null;
if (!empty($available['online']['enabled'])) $defaultMethod = 'online';
elseif (!empty($available['bank']['enabled'])) $defaultMethod = 'bank';
elseif (!empty($available['cash']['enabled'])) $defaultMethod = 'cash';

// اسم العميل (مثل الداشبورد لو تحب)
$client_name = explode(' ', $_SESSION['name'] ?? 'عميلنا')[0];

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
<title>اشتراك الباقة</title>

<link rel="stylesheet" href="client_style.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">

<style>
/* ==========================
   نفس ثيم client_dashboard.php
========================== */
:root {
  --primary: #6c5ce7;
  --primary2: #8e44ad;
  --accent: #00d2d3;
  --primary-light: #a29bfe;

  --bg: #f7f8ff;
  --surface: #ffffff;

  --text-main: #1f2937;
  --text-sub: #6b7280;

  --card-shadow: 0 14px 40px rgba(17, 24, 39, 0.08);
  --soft-shadow: 0 10px 25px rgba(17, 24, 39, 0.06);
  --border: rgba(17,24,39,.07);

  --glass: rgba(255,255,255,.70);
  --glass2: rgba(255,255,255,.45);

  --danger: #ff7675;
  --success: #22c55e;
  --warning: #f59e0b;
}

body[data-theme="dark"]{
  --bg: #0b1220;
  --surface: #111b2f;

  --text-main: #f8fafc;
  --text-sub: #94a3b8;

  --card-shadow: 0 18px 55px rgba(0,0,0,0.45);
  --soft-shadow: 0 14px 35px rgba(0,0,0,0.35);
  --border: rgba(255,255,255,.08);

  --glass: rgba(17,27,47,.75);
  --glass2: rgba(17,27,47,.55);
}

*{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
body{
  margin:0;
  font-family:'Tajawal',sans-serif;
  background: var(--bg);
  color: var(--text-main);
  overflow-x:hidden;
  padding-bottom: 105px;
}
a{ text-decoration:none; }

/* ==========================
   Header like dashboard (sticky blur)
========================== */
.header-top{
  padding: 18px 16px 14px;
  display:flex;
  justify-content:space-between;
  align-items:center;
  position:sticky; top:0;
  z-index: 900;
  background: color-mix(in srgb, var(--bg) 92%, transparent);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  border-bottom: 1px solid var(--border);
}
.left-side{ display:flex; align-items:center; gap:10px; }
.action-btn{
  width:46px; height:46px; border-radius:14px;
  background: var(--surface);
  display:flex; justify-content:center; align-items:center;
  font-size:1.12rem;
  color: var(--text-main);
  box-shadow: var(--soft-shadow);
  cursor:pointer;
  border: 1px solid var(--border);
}
.action-btn:active{ transform: scale(.98); }

.page-title{
  display:flex; flex-direction:column;
  line-height: 1.1;
}
.page-title b{ font-size:1.05rem; font-weight:900; }
.page-title span{ font-size:.78rem; color: var(--text-sub); font-weight:800; margin-top:4px; }

/* ==========================
   Layout / Cards
========================== */
.container{ max-width: 720px; margin:0 auto; padding: 14px 16px 0; }

.card{
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 24px;
  box-shadow: var(--card-shadow);
  padding: 16px;
  margin-bottom: 14px;
  position:relative;
  overflow:hidden;
}

.card-head{
  display:flex; align-items:flex-start; justify-content:space-between; gap:12px;
  margin-bottom: 12px;
}
.card-head .t{
  font-weight: 900;
  font-size: 1.05rem;
}
.badge-price{
  padding: 8px 12px;
  border-radius: 999px;
  font-weight: 900;
  background: linear-gradient(135deg, var(--primary), var(--primary2));
  color:#fff;
  box-shadow: 0 14px 30px rgba(108,92,231,.25);
  white-space:nowrap;
  font-size: .9rem;
}

.meta{
  color: var(--text-sub);
  font-weight: 800;
  line-height: 1.8;
  font-size: .9rem;
}
.desc{
  color: var(--text-sub);
  font-weight: 800;
  line-height: 1.7;
  font-size: .88rem;
  border-top: 1px solid var(--border);
  padding-top: 12px;
  margin-top: 12px;
}

/* Notice */
.notice{
  border: 1px solid color-mix(in srgb, var(--border) 70%, var(--danger) 30%);
  background: color-mix(in srgb, var(--surface) 88%, var(--danger) 12%);
  padding: 14px;
  border-radius: 18px;
  font-weight: 900;
  color: var(--text-main);
  display:flex; gap:10px; align-items:flex-start;
}
.notice i{ color: var(--danger); margin-top: 2px; }

/* Payment options (match dashboard softness) */
.pay-grid{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
@media(max-width:420px){
  .pay-grid{ grid-template-columns: 1fr; }
}
.pay-opt{ display:none; }
.pay-card{
  cursor:pointer;
  border: 1px solid var(--border);
  border-radius: 20px;
  padding: 14px 12px;
  background: color-mix(in srgb, var(--surface) 92%, transparent);
  box-shadow: var(--soft-shadow);
  transition: .2s;
  min-height: 86px;
  display:flex;
  align-items:center;
  gap: 12px;
}
.pay-icon{
  width:46px;height:46px;
  display:flex;align-items:center;justify-content:center;
  border-radius: 16px;
  background: color-mix(in srgb, var(--primary) 12%, transparent);
  border: 1px solid color-mix(in srgb, var(--primary) 22%, transparent);
  flex:0 0 auto;
}
.pay-icon i{ color: var(--primary); font-size:1.25rem; }
.pay-info{ flex:1; }
.pay-name{ font-weight: 900; font-size:.95rem; }
.pay-desc{ color: var(--text-sub); font-weight: 800; font-size: .82rem; margin-top:4px; }
.pay-opt:checked + .pay-card{
  border-color: color-mix(in srgb, var(--primary) 70%, transparent);
  background: linear-gradient(135deg,
    color-mix(in srgb, var(--primary) 12%, transparent),
    color-mix(in srgb, var(--primary2) 10%, transparent)
  );
  transform: translateY(-1px);
}

/* Bank box */
.bank-box{
  display:none;
  margin-top: 12px;
  border-radius: 18px;
  padding: 14px;
  border: 1px dashed color-mix(in srgb, var(--primary) 55%, transparent);
  background: color-mix(in srgb, var(--primary) 10%, transparent);
  font-weight: 900;
  line-height: 1.9;
}
.bank-box .small{
  color: var(--text-sub);
  font-weight: 800;
  margin-top: 8px;
}

/* Submit button */
.btn{
  width:100%;
  border:none;
  cursor:pointer;
  border-radius: 18px;
  padding: 16px;
  font-weight: 900;
  font-size: 1.05rem;
  color:#fff;
  background: linear-gradient(135deg, var(--primary), var(--primary2));
  box-shadow: 0 14px 30px rgba(108,92,231,0.35);
  display:flex;
  align-items:center;
  justify-content:center;
  gap:10px;
}
.btn:disabled{ opacity:.55; cursor:not-allowed; box-shadow:none; }
</style>
</head>

<body data-theme="light">

<!-- Header (matches dashboard behavior) -->
<div class="header-top">
  <div class="left-side">
    <a class="action-btn" href="client_browse_packages.php" aria-label="Back">
      <i class="fas fa-arrow-right"></i>
    </a>

    <div class="page-title">
      <b>اشتراك الباقة</b>
      <span>أهلاً <?php echo htmlspecialchars($client_name); ?> — الدفع حسب الطرق المفعّلة من الإدارة</span>
    </div>
  </div>

  <button class="action-btn" onclick="toggleTheme()" aria-label="Theme">
    <i id="themeIcon" class="fas fa-moon"></i>
  </button>
</div>

<div class="container">

  <?php if (!$anyPay): ?>
    <div class="notice" style="margin-bottom:14px;">
      <i class="fas fa-circle-exclamation"></i>
      <div>لا توجد أي طريقة دفع مفعلة حالياً من لوحة الإدارة.</div>
    </div>
  <?php endif; ?>

  <!-- Package Summary -->
  <div class="card">
    <div class="card-head">
      <div>
        <div class="t"><?php echo htmlspecialchars($pkg['name']); ?></div>
        <div class="meta">
          مدة: <b><?php echo (int)$pkg['duration_days']; ?></b> يوم •
          وجبات يومياً: <b><?php echo (int)$pkg['meals_per_day']; ?></b><br>
          خيارات تظهر: <b><?php echo (int)($pkg['options_visible_count'] ?? 6); ?></b> •
          تختار: <b><?php echo (int)($pkg['options_selectable_count'] ?? 1); ?></b>
        </div>
      </div>
      <div class="badge-price"><?php echo number_format((float)$pkg['price'], 0); ?> ر.س</div>
    </div>

    <?php if (!empty($pkg['description'])): ?>
      <div class="desc"><?php echo nl2br(htmlspecialchars($pkg['description'])); ?></div>
    <?php endif; ?>
  </div>

  <!-- Payment -->
  <div class="card">
    <div class="card-head" style="margin-bottom:10px;">
      <div class="t"><i class="fas fa-shield-halved" style="color:var(--primary);margin-left:8px"></i> طريقة الدفع</div>
    </div>

    <div class="pay-grid">
      <?php if (!empty($available['online']['enabled'])): ?>
        <label>
          <input class="pay-opt" type="radio" name="pay" value="online" <?php echo $defaultMethod==='online'?'checked':''; ?>>
          <div class="pay-card">
            <div class="pay-icon"><i class="far fa-credit-card"></i></div>
            <div class="pay-info">
              <div class="pay-name">دفع إلكتروني</div>
              <div class="pay-desc">
                <?php echo htmlspecialchars($available['online']['gateway_name'] ?? $available['online']['gateway_code'] ?? ''); ?>
                (<?php echo htmlspecialchars($available['online']['env'] ?? ''); ?>)
              </div>
            </div>
            <i class="fas fa-chevron-left" style="color:var(--text-sub)"></i>
          </div>
        </label>
      <?php endif; ?>

      <?php if (!empty($available['bank']['enabled'])): ?>
        <label>
          <input class="pay-opt" type="radio" name="pay" value="bank" <?php echo $defaultMethod==='bank'?'checked':''; ?>>
          <div class="pay-card">
            <div class="pay-icon"><i class="fas fa-university"></i></div>
            <div class="pay-info">
              <div class="pay-name">تحويل بنكي</div>
              <div class="pay-desc">عرض بيانات التحويل</div>
            </div>
            <i class="fas fa-chevron-left" style="color:var(--text-sub)"></i>
          </div>
        </label>
      <?php endif; ?>

      <?php if (!empty($available['cash']['enabled'])): ?>
        <label>
          <input class="pay-opt" type="radio" name="pay" value="cash" <?php echo $defaultMethod==='cash'?'checked':''; ?>>
          <div class="pay-card">
            <div class="pay-icon"><i class="fas fa-money-bill-wave"></i></div>
            <div class="pay-info">
              <div class="pay-name">دفع نقدي</div>
              <div class="pay-desc">حسب سياسة المتجر</div>
            </div>
            <i class="fas fa-chevron-left" style="color:var(--text-sub)"></i>
          </div>
        </label>
      <?php endif; ?>
    </div>

    <?php if (!empty($available['bank']['enabled'])): ?>
      <div class="bank-box" id="bankBox">
        <div>اسم البنك: <?php echo htmlspecialchars($available['bank']['bank_name'] ?? ''); ?></div>
        <div>اسم الحساب: <?php echo htmlspecialchars($available['bank']['account_name'] ?? ''); ?></div>
        <div>IBAN: <?php echo htmlspecialchars($available['bank']['iban'] ?? ''); ?></div>
        <div>STC Pay: <?php echo htmlspecialchars($available['bank']['stc_pay_number'] ?? ''); ?></div>
        <div class="small">بعد التحويل سيتم تأكيد اشتراكك من الإدارة.</div>
      </div>
    <?php endif; ?>
  </div>

  <button class="btn" id="btnGo" onclick="subscribeNow()" <?php echo $anyPay?'':'disabled'; ?>>
    <i class="fas fa-check-circle"></i> تأكيد الاشتراك والمتابعة
  </button>

</div>

<?php include 'client_footer_nav.php'; ?>

<script>
/* ==========================
   نفس منطق الثيم في client_dashboard.php
========================== */
function applyThemeFromStorage(){
  const t = localStorage.getItem('theme');
  if (t === 'dark') document.body.setAttribute('data-theme','dark');
  else document.body.setAttribute('data-theme','light');

  const icon = document.getElementById('themeIcon');
  if(icon){
    icon.className = (document.body.getAttribute('data-theme') === 'dark') ? 'fas fa-sun' : 'fas fa-moon';
  }
}
function toggleTheme() {
  const isDark = document.body.getAttribute('data-theme') === 'dark';
  document.body.setAttribute('data-theme', isDark ? 'light' : 'dark');
  localStorage.setItem('theme', isDark ? 'light' : 'dark');
  applyThemeFromStorage();
}
applyThemeFromStorage();

/* ==========================
   Payment UI
========================== */
function getPay(){
  const el = document.querySelector('input[name="pay"]:checked');
  return el ? el.value : '';
}
function syncBank(){
  const box = document.getElementById('bankBox');
  if(!box) return;
  box.style.display = (getPay()==='bank') ? 'block' : 'none';
}
document.addEventListener('change', (e)=>{
  if(e.target && e.target.name==='pay') syncBank();
});
syncBank();

async function subscribeNow(){
  const btn = document.getElementById('btnGo');
  btn.disabled = true;

  const pay = getPay();
  if(!pay){
    alert('اختر طريقة دفع أولاً');
    btn.disabled = false;
    return;
  }

  const fd = new FormData();
  fd.append('csrf_token', '<?php echo htmlspecialchars($csrf); ?>');
  fd.append('package_id', '<?php echo (int)$package_id; ?>');
  fd.append('payment_method', pay);

  try{
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
      btn.disabled = false;
    }
  } catch(e){
    alert('خطأ في الاتصال');
    btn.disabled = false;
  }
}
</script>

</body>
</html>