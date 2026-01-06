<?php
// client_dashboard.php - نسخة تطبيق احترافية محسّنة (UI + Splash مرة واحدة + إشعارات بدون تكرار + عداد الإمهال)
// ==============================================================================

header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');

if (session_status() == PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

require_once 'db_connect.php';

$client_id   = $_SESSION['user_id'];
$client_name = explode(' ', $_SESSION['name'] ?? 'عميلنا')[0];
$today       = date('Y-m-d');
$hour        = date('H');

// --- 1) ترحيب ذكي ---
if ($hour < 12) $greeting = "صباح الخير";
elseif ($hour < 18) $greeting = "مساء الخير";
else $greeting = "مساء الأنوار";

// --- 2) إعدادات النظام ---
$settings = [];
try {
    $settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $settings = [];
}

// --- 3) بيانات الاشتراك + الإمهال ---
// المطلوب: إذا انتهت الباقة الأساسية وباقي الإمهال -> العداد يصبح "إلى نهاية الإمهال"
$sub_info = [
    'name' => 'لا يوجد باقة',
    'days' => 0,
    'percent' => 0,
    'status' => 'inactive', // active | grace | expired | inactive
    'label' => 'غير نشط',
    'end_date' => null,
    'grace_end_date' => null,
    'target_label' => 'رصيدك المتبقي في الاشتراك', // يتغير للإمهال
];

try {
    // مدة الإمهال من الإعدادات (اختياري) - افتراضي 30
    $graceDays = 30;
    if (!empty($settings['grace_period_days']) && is_numeric($settings['grace_period_days'])) {
        $graceDays = max(0, (int)$settings['grace_period_days']);
    }

    $stmt = $pdo->prepare("
        SELECT 
            cd.subscription_start_date,
            cd.subscription_end_date,
            p.name,
            p.duration_days
        FROM client_details cd
        LEFT JOIN packages p ON cd.package_id = p.id
        WHERE cd.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$client_id]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($sub && !empty($sub['subscription_start_date'])) {
        $now = new DateTime();

        $start = new DateTime($sub['subscription_start_date']);

        // نهاية الاشتراك: إذا مخزنة استخدمها، وإلا احسبها من مدة الباقة
        if (!empty($sub['subscription_end_date'])) {
            $end = new DateTime($sub['subscription_end_date']);
        } else {
            $dur = !empty($sub['duration_days']) && is_numeric($sub['duration_days']) ? (int)$sub['duration_days'] : 30;
            $end = (clone $start)->modify("+{$dur} days");
        }

        $graceEnd = (clone $end)->modify("+{$graceDays} days");

        $sub_info['name'] = $sub['name'] ?: 'باقة';
        $sub_info['end_date'] = $end->format('Y-m-d');
        $sub_info['grace_end_date'] = $graceEnd->format('Y-m-d');

        // الحالة + العداد الهدف
        if ($now <= $end) {
            // ACTIVE
            $sub_info['status'] = 'active';
            $sub_info['label']  = 'نشط';
            $sub_info['target_label'] = 'متبقي على نهاية الاشتراك';

            $total  = max(1, (int)$start->diff($end)->days);
            $remain = max(0, (int)$now->diff($end)->days);

            $sub_info['days']    = $remain;
            $sub_info['percent'] = (int)max(0, min(100, round(($remain / $total) * 100)));

        } elseif ($now <= $graceEnd) {
            // GRACE
            $sub_info['status'] = 'grace';
            $sub_info['label']  = 'إمهال';
            $sub_info['target_label'] = 'متبقي على نهاية الإمهال';

            $totalGrace  = max(1, (int)$end->diff($graceEnd)->days);
            $remainGrace = max(0, (int)$now->diff($graceEnd)->days);

            $sub_info['days']    = $remainGrace;
            $sub_info['percent'] = (int)max(0, min(100, round(($remainGrace / $totalGrace) * 100)));

        } else {
            // EXPIRED
            $sub_info['status']  = 'expired';
            $sub_info['label']   = 'منتهي';
            $sub_info['target_label'] = 'الاشتراك منتهي';
            $sub_info['days']    = 0;
            $sub_info['percent'] = 0;
        }
    }
} catch (Exception $e) {}

// ألوان الحالة
$statusColor = 'var(--warning)';
if ($sub_info['status'] === 'active') $statusColor = 'var(--success)';
elseif ($sub_info['status'] === 'grace') $statusColor = 'var(--warning)';
elseif ($sub_info['status'] === 'expired') $statusColor = 'var(--danger)';

// --- 4) وجبة اليوم ---
$meal = null;
try {
    $today_meal = $pdo->prepare("
        SELECT p.name, p.image_url
        FROM delivery_log dl
        JOIN products p ON dl.meal_id = p.id
        WHERE dl.client_id = ? AND dl.delivery_date = ?
        LIMIT 1
    ");
    $today_meal->execute([$client_id, $today]);
    $meal = $today_meal->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $meal = null;
}

// --- 5) أفضل الباقات ---
$top_packages = [];
try {
    $top_packages = $pdo->query("SELECT * FROM packages WHERE is_active=1 ORDER BY price ASC LIMIT 3")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $top_packages = [];
}

// Paths
$splashImg = 'uploads/' . ($settings['splash_screen_img'] ?? 'logo.png');
$bannerImg = 'uploads/' . ($settings['dashboard_ad_img'] ?? '');
if (empty($settings['dashboard_ad_img'])) {
    $bannerImg = 'uploads/logo.png';
}

$adText = $settings['dashboard_ad_text'] ?? 'عرض اليوم - خصومات ووجبات مميزة';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>لوحة العميل</title>

    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#6c5ce7">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">

    <style>
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
        a { text-decoration:none; }

        #splash{
            position:fixed; inset:0;
            background:#fff;
            z-index:10000;
            display:flex; justify-content:center; align-items:center;
            transition: opacity .75s ease, visibility .75s ease;
        }
        body[data-theme="dark"] #splash{ background:#0b1220; }
        #splash img{ width:100%; height:100%; object-fit:cover; }
        #splash.hidden{ opacity:0; visibility:hidden; }

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
        .notif-badge{
            position:absolute;
            top:9px; right:9px;
            width:11px; height:11px;
            background: var(--danger);
            border-radius:50%;
            border:2px solid var(--surface);
            display:none;
        }

        .container{ max-width: 720px; margin:0 auto; }

        #notifOverlay{
            position:fixed; inset:0;
            background: rgba(0,0,0,.42);
            z-index: 9000;
            display:none;
        }
        #notifPanel{
            position:fixed;
            top: 86px;
            left: 14px; right: 14px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 22px;
            max-height: 420px;
            overflow:auto;
            z-index: 9001;
            box-shadow: 0 20px 60px rgba(0,0,0,.22);
            display:none;
            padding: 14px;
        }
        .notif-head{
            display:flex; align-items:center; justify-content:space-between;
            padding: 6px 6px 12px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 10px;
        }
        .notif-title{ font-weight: 900; font-size: 1rem; }
        .notif-sub{ color: var(--text-sub); font-size: .78rem; font-weight: 700; }
        .notif-item{
            display:flex; gap:12px;
            padding: 12px;
            border-radius: 16px;
            border: 1px solid var(--border);
            margin-bottom: 10px;
            background: color-mix(in srgb, var(--surface) 92%, transparent);
        }
        .notif-item img{
            width:46px; height:46px; border-radius: 14px;
            object-fit: cover;
            flex-shrink:0;
        }
        .notif-item .t{ font-weight: 900; font-size: .92rem; margin-bottom: 2px; }
        .notif-item .m{ font-size: .82rem; color: var(--text-sub); line-height: 1.4; }

        #popupNotif{
            position:fixed;
            top: -170px;
            left: 14px; right: 14px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 14px;
            box-shadow: 0 24px 70px rgba(0,0,0,.18);
            z-index: 10001;
            display:flex; align-items:center; gap: 12px;
            transition: .55s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }
        .notif-img-large{
            width: 62px; height: 62px;
            border-radius: 16px;
            object-fit: cover;
            flex-shrink:0;
        }
        .xbtn{
            width: 38px; height: 38px; border-radius: 14px;
            display:flex; align-items:center; justify-content:center;
            background: color-mix(in srgb, var(--surface) 80%, #000 20%);
            color: var(--text-sub);
            border: 1px solid var(--border);
        }

        .sub-card{
            background: linear-gradient(135deg,
                color-mix(in srgb, var(--surface) 82%, var(--primary) 18%),
                color-mix(in srgb, var(--surface) 92%, transparent)
            );
            border: 1px solid color-mix(in srgb, var(--border) 70%, var(--primary) 30%);
            border-radius: 30px;
            margin: 16px 18px 18px;
            padding: 22px;
            display:flex;
            align-items:center;
            gap: 18px;
            box-shadow: var(--card-shadow);
            position:relative;
            overflow:hidden;
        }
        .sub-card::before{
            content:"";
            position:absolute; inset:-60px -80px auto auto;
            width:180px; height:180px;
            background: radial-gradient(circle, rgba(108,92,231,.35), transparent 65%);
            transform: rotate(22deg);
        }

        .progress-box{ position:relative; width: 86px; height: 86px; z-index:1; }
        .progress-box svg{ transform: rotate(-90deg); width: 86px; height: 86px; }
        .progress-box .bg{ fill:none; stroke: color-mix(in srgb, var(--text-sub) 20%, transparent); stroke-width: 4; }
        .progress-box .bar{ fill:none; stroke: var(--primary); stroke-width: 4; stroke-linecap: round; transition: 1s; }
        .sub-meta{ flex:1; z-index:1; }
        .sub-name{ margin:0 0 6px; font-size: 1.03rem; font-weight: 900; }
        .sub-desc{ color: var(--text-sub); font-size: .82rem; font-weight: 700; }
        .chip-status{
            display:inline-flex; align-items:center; gap:6px;
            margin-top: 10px;
            padding: 6px 10px;
            border-radius: 999px;
            font-weight: 900;
            font-size: .75rem;
            border: 1px solid var(--border);
            background: var(--glass2);
        }

        .promo-banner{
            margin: 0 18px 18px;
            border-radius: 30px;
            overflow:hidden;
            position:relative;
            box-shadow: 0 22px 60px rgba(108,92,231,.16);
            border: 1px solid var(--border);
            background: var(--surface);
        }
        .promo-banner .banner-img{
            width:100%;
            height: 205px;
            object-fit: cover;
            display:block;
            transform: scale(1.02);
            filter: saturate(1.05);
        }
        .promo-banner .banner-overlay{
            position:absolute; inset:0;
            background: linear-gradient(90deg, rgba(0,0,0,.66), rgba(0,0,0,.14));
        }
        .promo-banner .banner-content{
            position:absolute; inset:0;
            display:flex; flex-direction:column; justify-content:center;
            padding: 26px 22px;
            color:#fff;
        }
        .promo-banner .chip{
            width:fit-content;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,.18);
            border: 1px solid rgba(255,255,255,.22);
            font-weight: 900;
            font-size: .78rem;
        }
        .promo-banner h1{
            margin: 12px 0 10px;
            font-weight: 900;
            font-size: 1.32rem;
            line-height: 1.22;
        }
        .promo-banner .cta{
            width: fit-content;
            border:none;
            padding: 10px 18px;
            border-radius: 999px;
            background: rgba(255,255,255,.92);
            color: var(--primary);
            font-weight: 900;
            font-size: .82rem;
            box-shadow: 0 16px 35px rgba(0,0,0,.18);
        }

        .section{ padding: 0 18px 16px; }
        .section-head{
            display:flex; justify-content:space-between; align-items:center;
            margin-bottom: 12px;
        }
        .section-title{ margin:0; font-weight: 900; font-size: 1.05rem; }
        .section-link{ color: var(--primary); font-weight: 900; font-size: .85rem; }

        .meal-card{
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 14px;
            display:flex;
            align-items:center;
            gap: 14px;
            box-shadow: var(--soft-shadow);
            position:relative;
            overflow:hidden;
        }
        .meal-card::before{
            content:"";
            position:absolute; inset:auto -30px -30px auto;
            width:140px; height:140px;
            background: radial-gradient(circle, rgba(0,210,211,.25), transparent 65%);
            transform: rotate(-18deg);
        }
        .m-img{
            width: 82px; height: 82px;
            border-radius: 18px;
            object-fit: cover;
            border: 1px solid var(--border);
            background: color-mix(in srgb, var(--surface) 70%, #000 30%);
            flex-shrink: 0;
            z-index:1;
        }
        .m-info{ z-index:1; }
        .m-name{ font-weight: 900; font-size: 1rem; }
        .m-sub{
            margin-top: 6px;
            font-size: .8rem;
            color: var(--text-sub);
            font-weight: 800;
            display:flex; align-items:center; gap: 8px;
        }
        .pill{
            display:inline-flex; align-items:center; gap:6px;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: var(--glass2);
            font-weight: 900;
            font-size: .74rem;
            color: var(--text-main);
        }

        .scroll-section{ padding-right: 18px; margin-bottom: 18px; }
        .scroll-container{
            display:flex;
            overflow-x:auto;
            gap: 12px;
            padding: 8px 0 6px;
            scrollbar-width:none;
        }
        .scroll-container::-webkit-scrollbar{ display:none; }

        .pkg-card{
            min-width: 170px;
            background: linear-gradient(135deg,
                color-mix(in srgb, var(--surface) 85%, var(--primary) 15%),
                var(--surface)
            );
            border: 1px solid var(--border);
            padding: 18px;
            border-radius: 26px;
            text-align:center;
            box-shadow: var(--soft-shadow);
            position:relative;
            overflow:hidden;
        }
        .pkg-card::after{
            content:"";
            position:absolute; inset:-60px auto auto -60px;
            width:160px; height:160px;
            background: radial-gradient(circle, rgba(162,155,254,.35), transparent 65%);
        }
        .pkg-name{
            display:block;
            font-size: .92rem;
            font-weight: 900;
            margin-bottom: 10px;
            position:relative;
            z-index:1;
        }
        .pkg-price{
            display:block;
            color: var(--primary);
            font-weight: 900;
            font-size: 1.22rem;
            position:relative;
            z-index:1;
        }
        .pkg-btn{
            border:none;
            background: linear-gradient(135deg, var(--primary), var(--primary2));
            color:#fff;
            width:100%;
            padding: 11px 12px;
            border-radius: 16px;
            margin-top: 14px;
            font-size: .78rem;
            font-weight: 900;
            position:relative;
            z-index:1;
            box-shadow: 0 14px 30px rgba(108,92,231,.25);
        }
        .pkg-btn:active{ transform: scale(.99); }

        .muted{ color: var(--text-sub); font-weight: 800; }
    </style>
</head>

<body data-theme="light">

    <div id="splash">
        <img src="<?php echo htmlspecialchars($splashImg); ?>" alt="Splash">
    </div>

    <div id="notifOverlay" onclick="toggleNotifs()"></div>

    <div id="notifPanel">
        <div class="notif-head">
            <div>
                <div class="notif-title">الإشعارات</div>
                <div class="notif-sub">آخر التحديثات والعروض</div>
            </div>
            <button class="action-btn" style="width:42px;height:42px;border-radius:14px;" onclick="toggleNotifs()">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div id="notifList">
            <div style="text-align:center; color: var(--text-sub); padding: 18px;" data-empty="1">
                لا توجد إشعارات حالياً
            </div>
        </div>
    </div>

    <div id="popupNotif" onclick="handleNotifClick()">
        <img id="popupImg" src="" class="notif-img-large" alt="" style="display:none;">
        <div style="flex:1;">
            <b id="popupTitle" style="display:block; font-size:0.95rem; color:var(--primary); font-weight:900;">إشعار</b>
            <span id="popupBody" style="font-size:0.85rem; color:var(--text-sub); font-weight:800;"></span>
        </div>
        <div class="xbtn" onclick="closePopup(event)"><i class="fas fa-times"></i></div>
    </div>

    <audio id="notifSound" src="assets/notification.mp3" preload="auto"></audio>

    <div class="header-top">
        <div class="user-profile">
            <div class="avatar-circle"><i class="fas fa-user-astronaut"></i></div>
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
            <button class="action-btn" onclick="toggleTheme()" aria-label="Theme">
                <i id="themeIcon" class="fas fa-moon"></i>
            </button>
            <button class="action-btn" onclick="toggleNotifs()" aria-label="Notifications">
                <i class="fas fa-bell"></i>
                <span id="redDot" class="notif-badge"></span>
            </button>
        </div>
    </div>

    <div class="container">

        <div class="sub-card">
            <div class="progress-box">
                <svg>
                    <circle class="bg" cx="43" cy="43" r="38"></circle>
                    <circle class="bar" cx="43" cy="43" r="38"
                        style="stroke-dasharray: <?php echo (int)round($sub_info['percent'] * 2.38); ?>, 238;">
                    </circle>
                </svg>
                <div style="position:absolute; inset:0; display:flex; flex-direction:column; justify-content:center; align-items:center;">
                    <span style="font-size:1.05rem; font-weight:900;">
                        <?php echo (int)$sub_info['days']; ?>
                    </span>
                    <span style="font-size:0.6rem; color:var(--text-sub); font-weight:900;">يوم</span>
                </div>
            </div>

            <div class="sub-meta">
                <h3 class="sub-name"><?php echo htmlspecialchars($sub_info['name']); ?></h3>
                <div class="sub-desc"><?php echo htmlspecialchars($sub_info['target_label']); ?></div>

                <div class="chip-status">
                    <i class="fas fa-circle" style="font-size:.55rem;color:<?php echo $statusColor; ?>"></i>
                    <?php echo htmlspecialchars($sub_info['label']); ?>
                    <span style="opacity:.75;">•</span>
                    <?php echo (int)$sub_info['percent']; ?>%
                </div>

                <?php if (!empty($sub_info['end_date'])): ?>
                    <div style="margin-top:8px;color:var(--text-sub);font-weight:800;font-size:.78rem;">
                        <?php if ($sub_info['status'] === 'grace'): ?>
                            نهاية الاشتراك: <b><?php echo htmlspecialchars($sub_info['end_date']); ?></b> •
                            نهاية الإمهال: <b><?php echo htmlspecialchars($sub_info['grace_end_date']); ?></b>
                        <?php else: ?>
                            نهاية الاشتراك: <b><?php echo htmlspecialchars($sub_info['end_date']); ?></b>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <a href="client_package.php" class="action-btn" style="text-decoration:none;">
                <i class="fas fa-arrow-left"></i>
            </a>
        </div>

        <div class="promo-banner">
            <img class="banner-img" src="<?php echo htmlspecialchars($bannerImg); ?>" alt="banner" loading="lazy">
            <div class="banner-overlay"></div>
            <div class="banner-content">
                <span class="chip">حصرياً</span>
                <h1><?php echo htmlspecialchars($adText); ?></h1>
                <button class="cta" type="button">اكتشف المزيد</button>
            </div>
        </div>

        <div class="section">
            <div class="section-head">
                <h3 class="section-title">وجبتك القادمة 🍱</h3>
                <a class="section-link" href="client_select_meals.php">تعديل</a>
            </div>

            <?php if ($meal): ?>
                <?php
                    $mealImg = 'uploads/' . ($meal['image_url'] ?? '');
                    if (empty($meal['image_url'])) $mealImg = 'uploads/logo.png';
                ?>
                <div class="meal-card">
                    <img src="<?php echo htmlspecialchars($mealImg); ?>" class="m-img" alt="meal" loading="lazy">
                    <div class="m-info">
                        <div class="m-name"><?php echo htmlspecialchars($meal['name']); ?></div>
                        <div class="m-sub">
                            <span class="pill"><i class="fas fa-clock"></i> في انتظار التجهيز</span>
                            <span class="pill"><i class="fas fa-shield-alt"></i> متابعة من طلباتي</span>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="meal-card" style="justify-content:center; border-style:dashed;">
                    <span class="muted">لم يتم اختيار وجبة بعد</span>
                </div>
            <?php endif; ?>
        </div>

        <div class="scroll-section">
            <h3 style="margin:0 18px 12px; font-weight:900;">أفضل الباقات ⭐</h3>
            <div class="scroll-container">
                <?php foreach($top_packages as $pkg): ?>
                    <div class="pkg-card">
                        <b class="pkg-name"><?php echo htmlspecialchars($pkg['name'] ?? 'باقة'); ?></b>
                        <span class="pkg-price"><?php echo htmlspecialchars($pkg['price'] ?? '0'); ?> ر.س</span>
                        <button class="pkg-btn" type="button" onclick="window.location.href='client_browse_packages.php'">
                            اشترك الآن
                        </button>
                    </div>
                <?php endforeach; ?>
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

        (function(){
            const splash = document.getElementById('splash');
            const KEY = 'splash_shown_session_v1';

            if (!splash) return;

            if (sessionStorage.getItem(KEY) === '1') {
                splash.classList.add('hidden');
                splash.style.display = 'none';
                return;
            }

            window.addEventListener('load', () => {
                setTimeout(() => {
                    splash.classList.add('hidden');
                    sessionStorage.setItem(KEY, '1');
                    setTimeout(() => { splash.style.display = 'none'; }, 900);
                }, 1200);
            });
        })();

        function toggleNotifs() {
            const panel = document.getElementById('notifPanel');
            const overlay = document.getElementById('notifOverlay');
            const redDot = document.getElementById('redDot');

            const open = (panel.style.display === 'block');

            if (open) {
                panel.style.display = 'none';
                overlay.style.display = 'none';
            } else {
                panel.style.display = 'block';
                overlay.style.display = 'block';
                if (redDot) redDot.style.display = 'none';
            }
        }

        let currentCampaignId = parseInt(localStorage.getItem('last_campaign_id') || '0', 10);
        let campaignLink = localStorage.getItem('last_campaign_link') || "";

        function closePopup(e){
            if (e) e.stopPropagation();
            const p = document.getElementById('popupNotif');
            if(p) p.style.top = '-170px';
        }

        function handleNotifClick(){
            if (campaignLink) window.location.href = campaignLink;
        }

        function pushToNotifList(data){
            const list = document.getElementById('notifList');
            if(!list) return;

            if (list.querySelector('[data-empty="1"]')) list.innerHTML = '';

            const item = document.createElement('div');
            item.className = 'notif-item';
            item.innerHTML = `
                ${data.img ? `<img src="${data.img}">` : `<div style="width:46px;height:46px;border-radius:14px;display:flex;align-items:center;justify-content:center;background:rgba(108,92,231,.12);color:var(--primary);border:1px solid var(--border)"><i class="fas fa-bullhorn"></i></div>`}
                <div>
                    <div class="t">${(data.title||'إشعار')}</div>
                    <div class="m">${(data.msg||'')}</div>
                </div>
            `;
            list.prepend(item);
        }

        function showInAppPopup(data){
            const popup = document.getElementById('popupNotif');
            const imgEl = document.getElementById('popupImg');

            document.getElementById('popupTitle').innerText = data.title || 'إشعار';
            document.getElementById('popupBody').innerText = data.msg || '';

            if (data.img) {
                imgEl.src = data.img;
                imgEl.style.display = 'block';
            } else {
                imgEl.style.display = 'none';
            }

            document.getElementById('redDot').style.display = 'block';

            popup.style.top = '18px';
            document.getElementById('notifSound').play().catch(()=>{});
            if ("vibrate" in navigator) navigator.vibrate([120, 80, 120]);

            setTimeout(() => closePopup(), 12000);
        }

        async function showSystemNotification(title, body, icon, url){
            if (!('serviceWorker' in navigator)) return;

            try{
                const perm = await Notification.requestPermission();
                if (perm !== 'granted') return;

                const reg = await navigator.serviceWorker.ready;
                reg.showNotification(title, {
                    body: body,
                    icon: icon || 'icon.png',
                    badge: icon || 'icon.png',
                    data: { url: url || 'client_dashboard.php' }
                });
            }catch(e){}
        }

        function checkCampaigns(){
            fetch('check_notif.php', { cache: 'no-store' })
                .then(r => r.json())
                .then(data => {
                    if(!data || !data.id) return;

                    const newId = parseInt(data.id, 10);
                    if (!newId) return;

                    if (newId === currentCampaignId) return;

                    currentCampaignId = newId;
                    campaignLink = data.link || "";

                    localStorage.setItem('last_campaign_id', String(currentCampaignId));
                    localStorage.setItem('last_campaign_link', campaignLink);

                    pushToNotifList(data);
                    showInAppPopup(data);

                    showSystemNotification(
                        data.title || 'إشعار',
                        data.msg || '',
                        data.img || 'icon.png',
                        campaignLink
                    );
                })
                .catch(()=>{});
        }

        checkCampaigns();
        setInterval(checkCampaigns, 15000);

        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js').catch(() => {});
        }
    </script>
</body>
</html>