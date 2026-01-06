<?php
// client_browse_packages.php - Theme matches client_dashboard.php (Dark/Light + Premium UI)
header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');

require_once 'db_connect.php';

if (session_status() == PHP_SESSION_NONE) session_start();
$client_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

if ($client_id <= 0) { header("Location: login.php"); exit; }

$client_name = explode(' ', $_SESSION['name'] ?? 'عميلنا')[0];
$hour = date('H');
if ($hour < 12) $greeting = "صباح الخير";
elseif ($hour < 18) $greeting = "مساء الخير";
else $greeting = "مساء الأنوار";

// الاشتراك الحالي لمنع الاشتراك
$sub = [
  'subscription_status' => 'none',
  'subscription_start_date' => null,
  'subscription_end_date' => null,
  'package_id' => null
];

try {
  $stmtS = $pdo->prepare("SELECT subscription_status, subscription_start_date, subscription_end_date, package_id FROM client_details WHERE user_id=? LIMIT 1");
  $stmtS->execute([$client_id]);
  $row = $stmtS->fetch(PDO::FETCH_ASSOC);
  if ($row) $sub = $row;
} catch (Exception $e) {}

$hasActiveSubscription = in_array((string)$sub['subscription_status'], ['active','paused','pending_payment'], true);

// packages
try {
  $stmt = $pdo->query("SELECT * FROM packages WHERE is_active=1 ORDER BY price ASC");
  $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $packages = [];
}

function getPackageLimits($pdo, $pkg_id) {
  $stmt = $pdo->prepare("
    SELECT c.name, l.allowed_count
    FROM package_category_limits l
    JOIN categories c ON l.category_id = c.id
    WHERE l.package_id = ?
  ");
  $stmt->execute([$pkg_id]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
<title>باقات الاشتراك</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="client_style.css?v=<?php echo time(); ?>">

<style>
/* ====== نفس ثيم client_dashboard.php ====== */
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

* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
body{
    margin:0;
    font-family:'Tajawal',sans-serif;
    background: var(--bg);
    color: var(--text-main);
    overflow-x:hidden;
    padding-bottom: 105px;
}
a{ text-decoration:none; }

/* Header-top مطابق للداشبورد */
.header-top{
    padding: 22px 18px 14px;
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
.user-profile{ display:flex; align-items:center; gap:12px; }
.avatar-circle{
    width:52px; height:52px; border-radius:18px;
    background: linear-gradient(135deg, var(--primary), var(--primary2));
    display:flex; justify-content:center; align-items:center;
    color:#fff; font-size:1.35rem;
    box-shadow: 0 14px 30px rgba(108,92,231,.25);
}
.header-actions{ display:flex; gap:10px; }
.action-btn{
    width:46px; height:46px; border-radius:14px;
    background: var(--surface);
    display:flex; justify-content:center; align-items:center;
    font-size:1.12rem;
    color: var(--text-main);
    box-shadow: var(--soft-shadow);
    cursor:pointer;
    border: 1px solid var(--border);
    position:relative;
}
.action-btn:active{ transform: scale(.98); }

/* Alerts */
.alert-sub{
    margin: 14px 18px 6px;
    padding: 14px 16px;
    border-radius: 22px;
    border: 1px solid var(--border);
    background: color-mix(in srgb, var(--danger) 10%, var(--surface) 90%);
    font-weight: 900;
    display:flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}
.alert-sub a{
    background: var(--surface);
    color: var(--text-main);
    padding: 10px 14px;
    border-radius: 14px;
    border: 1px solid var(--border);
    box-shadow: var(--soft-shadow);
    font-weight: 900;
    white-space: nowrap;
}

/* Page title */
.page-head{
    padding: 12px 18px 0;
}
.page-title{
    margin: 6px 0 6px;
    font-weight: 900;
    font-size: 1.08rem;
}
.page-sub{
    margin: 0 0 12px;
    color: var(--text-sub);
    font-weight: 800;
    font-size: .86rem;
}

/* Grid & Cards */
.packages-grid{
    display:grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 14px;
    padding: 0 18px 18px;
}
.pkg-card{
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 26px;
    overflow:hidden;
    box-shadow: var(--soft-shadow);
    cursor:pointer;
    transition:.2s;
}
.pkg-card:active{ transform: scale(.995); }

.pkg-img-area{ height: 170px; position:relative; overflow:hidden; }
.pkg-img-area img{ width:100%; height:100%; object-fit:cover; display:block; }
.badges-container{
    position:absolute; top: 12px; right: 12px;
    display:flex; flex-direction:column; gap:6px;
}
.badge-pill{
    padding: 6px 12px;
    border-radius: 999px;
    font-size: .75rem;
    font-weight: 900;
    color:#fff;
    background: rgba(108,92,231,.92);
    border: 1px solid rgba(255,255,255,.18);
    backdrop-filter: blur(6px);
}
.pkg-content{ padding: 14px 14px 16px; }
.pkg-name{ font-weight: 900; font-size: 1.05rem; margin-bottom: 6px; }
.pkg-desc-short{ color: var(--text-sub); font-weight: 800; font-size:.85rem; line-height:1.6; }
.pkg-footer{
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between;
}
.price-tag{
    font-weight: 900;
    font-size: 1.25rem;
    color: var(--primary);
}
.btn-view{
    display:inline-flex; align-items:center; gap:8px;
    padding: 10px 14px;
    border-radius: 999px;
    border: 1px solid var(--border);
    background: var(--glass2);
    color: var(--text-main);
    font-weight: 900;
    font-size: .85rem;
}

/* Modal (نفس فلسفة البطاقات) */
.modal-overlay{
    position:fixed; inset:0;
    background: rgba(0,0,0,0.42);
    z-index: 3000;
    display:none;
    justify-content:center;
    align-items:flex-end;
    backdrop-filter: blur(6px);
}
.modal-card{
    width: 100%;
    max-width: 540px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-top-left-radius: 28px;
    border-top-right-radius: 28px;
    box-shadow: 0 -20px 60px rgba(0,0,0,.25);
    overflow:hidden;
    max-height: 90vh;
    display:flex;
    flex-direction:column;
    animation: slideUp .25s cubic-bezier(0.16,1,0.3,1);
}
@media(min-width: 700px){
    .modal-overlay{ align-items:center; }
    .modal-card{ border-radius: 28px; }
}
@keyframes slideUp{ from{ transform: translateY(100%);} to{ transform: translateY(0);} }

.modal-header{ height: 170px; position:relative; }
.modal-header img{ width:100%; height:100%; object-fit:cover; }
.close-modal{
    position:absolute; top: 14px; left: 14px;
    width:44px; height:44px;
    border-radius: 16px;
    display:flex; align-items:center; justify-content:center;
    background: rgba(0,0,0,.35);
    color:#fff;
    border: 1px solid rgba(255,255,255,.18);
    cursor:pointer;
}

.modal-body{ padding: 16px; overflow:auto; }
.modal-title{ font-weight: 900; font-size: 1.18rem; margin-bottom: 6px; }
.modal-price{ font-weight: 900; color: var(--primary); font-size: 1.12rem; display:block; margin-bottom: 12px; }
.detail-row{
    display:flex; align-items:center; gap:10px;
    padding: 10px 12px;
    border-radius: 18px;
    border: 1px solid var(--border);
    background: color-mix(in srgb, var(--surface) 92%, transparent);
    margin-bottom: 10px;
    font-weight: 900;
    color: var(--text-main);
}
.detail-row i{ color: var(--primary); width: 20px; text-align:center; }

.limits-box{
    border-radius: 20px;
    border: 1px dashed color-mix(in srgb, var(--warning) 55%, var(--border) 45%);
    background: color-mix(in srgb, var(--surface) 86%, var(--warning) 14%);
    padding: 12px;
    margin: 10px 0;
}
.limits-title{ font-weight: 900; color: var(--warning); display:block; margin-bottom: 8px; }
.limit-tag{
    display:inline-block;
    padding: 6px 10px;
    border-radius: 14px;
    border: 1px solid color-mix(in srgb, var(--warning) 55%, var(--border) 45%);
    background: var(--surface);
    margin: 4px;
    font-weight: 900;
    font-size: .82rem;
}
.modal-desc{
    margin-top: 10px;
    padding-top: 12px;
    border-top: 1px solid var(--border);
    color: var(--text-sub);
    font-weight: 800;
    line-height: 1.8;
}

.modal-footer{
    padding: 14px;
    border-top: 1px solid var(--border);
    background: color-mix(in srgb, var(--surface) 92%, transparent);
}
.btn-subscribe-modal{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    width:100%;
    padding: 14px;
    border-radius: 20px;
    background: linear-gradient(135deg, var(--primary), var(--primary2));
    color:#fff;
    font-weight: 900;
    box-shadow: 0 16px 35px rgba(108,92,231,.25);
}
.btn-subscribe-modal[aria-disabled="true"]{
    background: #9ca3af;
    box-shadow:none;
    pointer-events:none;
}
</style>
</head>

<body data-theme="light">

<div class="header-top">
    <div class="user-profile">
        <div class="avatar-circle"><i class="fas fa-box-open"></i></div>
        <div>
            <span style="font-size:0.85rem; color:var(--text-sub); font-weight:800;">
                <?php echo htmlspecialchars($greeting); ?>
            </span>
            <h2 style="margin:0; font-size:1.12rem; font-weight:900;">
                <?php echo htmlspecialchars($client_name); ?>
            </h2>
        </div>
    </div>

    <div class="header-actions">
        <a class="action-btn" href="client_dashboard.php" aria-label="Back">
            <i class="fas fa-arrow-left"></i>
        </a>
        <button class="action-btn" onclick="toggleTheme()" aria-label="Theme">
            <i id="themeIcon" class="fas fa-moon"></i>
        </button>
    </div>
</div>

<div class="page-head">
    <div class="page-title">باقات الاشتراك 📦</div>
    <div class="page-sub">اختر الباقة المناسبة لك واستمتع بوجبات صحية</div>
</div>

<?php if ($hasActiveSubscription): ?>
    <div class="alert-sub">
        <div>لديك اشتراك قائم حالياً (الحالة: <?php echo htmlspecialchars($sub['subscription_status']); ?>). لا يمكنك الاشتراك حتى ينتهي.</div>
        <a href="client_dashboard.php">اذهب لاشتراكي</a>
    </div>
<?php endif; ?>

<div class="packages-grid">
<?php if (empty($packages)): ?>
    <div style="grid-column: 1/-1; text-align:center; padding: 40px; color: var(--text-sub); font-weight:900;">
        <i class="fas fa-box-open" style="font-size: 2.6rem; margin-bottom: 10px;"></i>
        <div>لا توجد باقات متاحة حالياً.</div>
    </div>
<?php else: ?>
    <?php foreach ($packages as $pkg):
        $limits = getPackageLimits($pdo, $pkg['id']);
        $pkgJson = json_encode([
            'id' => (int)$pkg['id'],
            'name' => $pkg['name'],
            'price' => number_format((float)$pkg['price'], 2),
            'desc' => $pkg['description'],
            'days' => (int)$pkg['duration_days'],
            'meals' => (int)$pkg['meals_per_day'],
            'image' => !empty($pkg['image_url']) ? $pkg['image_url'] : 'uploads/packages/default.png',
            'weight' => $pkg['fixed_weight_label'] ? "وزن ثابت: {$pkg['fixed_weight_label']}g" : "وزن مفتوح (اختياري)",
            'off_days' => !empty($pkg['off_days']) ? count(explode(',', $pkg['off_days'])) . ' أيام إجازة' : 'لا توجد إجازات',
            'limits' => $limits,
            'options_visible' => (int)($pkg['options_visible_count'] ?? 6),
            'options_select' => (int)($pkg['options_selectable_count'] ?? 1),
        ], JSON_UNESCAPED_UNICODE);
    ?>
    <div class="pkg-card" onclick='openModal(<?php echo $pkgJson; ?>)'>
        <div class="pkg-img-area">
            <img src="<?php echo !empty($pkg['image_url']) ? $pkg['image_url'] : 'uploads/packages/default.png'; ?>" alt="">
            <div class="badges-container">
                <span class="badge-pill"><?php echo (int)$pkg['meals_per_day']; ?> وجبات</span>
            </div>
        </div>
        <div class="pkg-content">
            <div class="pkg-name"><?php echo htmlspecialchars($pkg['name']); ?></div>
            <div class="pkg-desc-short"><?php echo mb_strimwidth($pkg['description'], 0, 70, '...'); ?></div>
            <div class="pkg-footer">
                <div class="price-tag"><?php echo number_format((float)$pkg['price'], 0); ?> ر.س</div>
                <span class="btn-view">التفاصيل <i class="fas fa-arrow-left"></i></span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>

<!-- Modal -->
<div id="detailModal" class="modal-overlay" onclick="closeModal(event)">
    <div class="modal-card">
        <div class="modal-header">
            <img id="mImg" src="" alt="">
            <div class="close-modal" onclick="closeModal(event, true)"><i class="fas fa-xmark"></i></div>
        </div>
        <div class="modal-body">
            <div class="modal-title" id="mName">اسم الباقة</div>
            <span class="modal-price" id="mPrice">0.00 ر.س</span>

            <div class="detail-row"><i class="far fa-calendar-alt"></i> <span id="mDays"></span> يوم اشتراك</div>
            <div class="detail-row"><i class="fas fa-utensils"></i> <span id="mMeals"></span> وجبات يومياً</div>
            <div class="detail-row"><i class="fas fa-layer-group"></i> خيارات تظهر: <span id="mOptVisible"></span> | تختار: <span id="mOptSelect"></span></div>
            <div class="detail-row"><i class="fas fa-weight-hanging"></i> <span id="mWeight"></span></div>
            <div class="detail-row"><i class="fas fa-pause-circle"></i> <span id="mOffDays"></span></div>

            <div id="mLimitsBox" class="limits-box" style="display:none;">
                <span class="limits-title">📋 تفاصيل الوجبات المحددة:</span>
                <div id="mLimitsContent"></div>
            </div>

            <div class="modal-desc">
                <strong>الوصف:</strong><br>
                <span id="mDesc"></span>
            </div>
        </div>
        <div class="modal-footer">
            <a id="mSubscribeBtn" href="#" class="btn-subscribe-modal">
                اشترك الآن في هذه الباقة <i class="fas fa-check-circle"></i>
            </a>
        </div>
    </div>
</div>

<?php include 'client_footer_nav.php'; ?>

<script>
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

const subscriptionBlocked = <?php echo $hasActiveSubscription ? 'true' : 'false'; ?>;

function openModal(pkg) {
    document.getElementById('mImg').src = pkg.image;
    document.getElementById('mName').innerText = pkg.name;
    document.getElementById('mPrice').innerText = pkg.price + ' ر.س';
    document.getElementById('mDesc').innerText = pkg.desc || 'لا يوجد وصف إضافي.';

    document.getElementById('mDays').innerText = pkg.days;
    document.getElementById('mMeals').innerText = pkg.meals;
    document.getElementById('mOptVisible').innerText = pkg.options_visible;
    document.getElementById('mOptSelect').innerText = pkg.options_select;
    document.getElementById('mWeight').innerText = pkg.weight;
    document.getElementById('mOffDays').innerText = pkg.off_days;

    const limitsBox = document.getElementById('mLimitsBox');
    const limitsContent = document.getElementById('mLimitsContent');
    limitsContent.innerHTML = '';

    if (pkg.limits && pkg.limits.length > 0) {
        limitsBox.style.display = 'block';
        pkg.limits.forEach(lim => {
            let tag = document.createElement('span');
            tag.className = 'limit-tag';
            tag.innerText = `${lim.name}: ${lim.allowed_count} وجبات`;
            limitsContent.appendChild(tag);
        });
    } else {
        limitsBox.style.display = 'none';
    }

    const btn = document.getElementById('mSubscribeBtn');
    if (subscriptionBlocked) {
        btn.setAttribute('aria-disabled', 'true');
        btn.innerHTML = 'لديك اشتراك قائم حالياً <i class="fas fa-lock"></i>';
        btn.href = 'client_dashboard.php';
    } else {
        btn.removeAttribute('aria-disabled');
        btn.innerHTML = 'اشترك الآن في هذه الباقة <i class="fas fa-check-circle"></i>';
        btn.href = 'client_package_checkout.php?package_id=' + pkg.id;
    }

    document.getElementById('detailModal').style.display = 'flex';
}

function closeModal(e, force = false) {
    if (force || e.target.id === 'detailModal') {
        document.getElementById('detailModal').style.display = 'none';
    }
}
</script>

</body>
</html>