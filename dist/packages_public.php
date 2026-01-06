<?php
/**
 * packages_public.php - صفحة عرض الباقات للزوار (غير المسجلين)
 * ===============================================================
 * تسمح للزوار بمشاهدة الباقات وتفاصيلها، وعند الاشتراك يحولون إلى تسجيل الدخول
 */

header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');

require_once 'db_connect.php';

// إذا كان المستخدم مسجل دخوله بالفعل، نحوله إلى صفحة الباقات للعملاء
if (session_status() == PHP_SESSION_NONE) session_start();
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'client') {
    header("Location: client_browse_packages.php");
    exit;
}

// جلب شعار المطعم
$logo_path = 'uploads/logo.png';
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='splash_screen_img' LIMIT 1");
    $logo = $stmt->fetchColumn();
    if ($logo && file_exists('uploads/' . $logo)) {
        $logo_path = 'uploads/' . $logo;
    }
} catch (Exception $e) {}

// جلب الباقات
try {
    $stmt = $pdo->query("SELECT * FROM packages WHERE is_active=1 ORDER BY price ASC");
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $packages = [];
}

function getPackageLimits($pdo, $pkg_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT c.name, l.allowed_count
            FROM package_category_limits l
            JOIN categories c ON l.category_id = c.id
            WHERE l.package_id = ?
        ");
        $stmt->execute([$pkg_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
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
        a{ text-decoration:none; color: inherit; }

        /* Header */
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

        .logo-container {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-container img {
            width: 50px;
            height: 50px;
            object-fit: contain;
            border-radius: 12px;
        }

        .header-title {
            font-weight: 900;
            font-size: 1.1rem;
            color: var(--text-main);
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

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

        /* Info Banner */
        .info-banner{
            margin: 14px 18px;
            padding: 16px 18px;
            border-radius: 20px;
            background: linear-gradient(135deg, rgba(108, 92, 231, 0.1) 0%, rgba(142, 68, 173, 0.1) 100%);
            border: 1px solid rgba(108, 92, 231, 0.2);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .info-banner i {
            font-size: 1.5rem;
            color: var(--primary);
        }
        .info-banner-text {
            flex: 1;
        }
        .info-banner-text strong {
            display: block;
            font-weight: 900;
            margin-bottom: 4px;
            color: var(--text-main);
        }
        .info-banner-text span {
            font-size: 0.85rem;
            color: var(--text-sub);
            font-weight: 700;
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

        /* Modal */
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
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }
        .detail-row:last-child{ border-bottom:none; }
        .detail-icon{
            width:36px; height:36px;
            border-radius:12px;
            background: rgba(108,92,231,.1);
            display:flex; align-items:center; justify-content:center;
            color: var(--primary);
            font-size: 1rem;
        }
        .detail-text{ flex:1; font-weight: 800; font-size:.9rem; }
        .detail-value{ font-weight: 900; color: var(--text-main); }

        .modal-footer{
            padding: 16px;
            border-top: 1px solid var(--border);
        }
        .btn-subscribe{
            width:100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary), var(--primary2));
            color: #fff;
            border: none;
            border-radius: 16px;
            font-weight: 900;
            font-size: 1rem;
            cursor:pointer;
            box-shadow: 0 10px 25px rgba(108,92,231,.35);
            display:flex; align-items:center; justify-content:center; gap:10px;
        }
        .btn-subscribe:active{ transform: scale(.98); }

        .empty{
            text-align:center;
            padding: 60px 20px;
            color: var(--text-sub);
            font-weight: 900;
        }
        .empty i{ font-size: 44px; opacity:.35; display:block; margin-bottom: 12px; }

        @media (max-width: 600px) {
            .packages-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body data-theme="light">

    <!-- Header -->
    <div class="header-top">
        <div class="logo-container">
            <img src="<?php echo htmlspecialchars($logo_path); ?>" alt="شعار المطعم" onerror="this.src='uploads/logo.png'">
            <div class="header-title">باقات الاشتراك</div>
        </div>
        <div class="header-actions">
            <a href="login.php" class="action-btn" title="تسجيل الدخول">
                <i class="fas fa-sign-in-alt"></i>
            </a>
        </div>
    </div>

    <!-- Info Banner -->
    <div class="info-banner">
        <i class="fas fa-info-circle"></i>
        <div class="info-banner-text">
            <strong>للاشتراك في إحدى الباقات</strong>
            <span>يجب تسجيل الدخول أولاً أو إنشاء حساب جديد</span>
        </div>
    </div>

    <!-- Page Head -->
    <div class="page-head">
        <h2 class="page-title">اختر الباقة المناسبة لك</h2>
        <p class="page-sub">عروض مميزة ووجبات صحية يومياً</p>
    </div>

    <!-- Packages Grid -->
    <?php if(empty($packages)): ?>
    <div class="empty">
        <i class="fas fa-box-open"></i>
        <div>لا توجد باقات متاحة حالياً.</div>
    </div>
<?php else: ?>
    <div class="packages-grid">
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
                <img src="<?php echo !empty($pkg['image_url']) ? htmlspecialchars($pkg['image_url']) : 'uploads/packages/default.png'; ?>" alt="">
                <div class="badges-container">
                    <span class="badge-pill"><?php echo (int)$pkg['meals_per_day']; ?> وجبات</span>
                </div>
            </div>
            <div class="pkg-content">
                <div class="pkg-name"><?php echo htmlspecialchars($pkg['name']); ?></div>
                <div class="pkg-desc-short"><?php echo mb_strimwidth($pkg['description'] ?? '', 0, 70, '...'); ?></div>
                <div class="pkg-footer">
                    <div class="price-tag"><?php echo number_format((float)$pkg['price'], 0); ?> ر.س</div>
                    <span class="btn-view">التفاصيل <i class="fas fa-arrow-left"></i></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

    <!-- Modal -->
    <div class="modal-overlay" id="packageModal" onclick="closeModal(event)">
        <div class="modal-card" onclick="event.stopPropagation()">
            <div class="modal-header">
                <img id="modalImg" src="" alt="">
                <div class="close-modal" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </div>
            </div>
            <div class="modal-body">
                <div class="modal-title" id="modalTitle"></div>
                <div class="modal-price" id="modalPrice"></div>
                <div id="modalDetails"></div>
            </div>
            <div class="modal-footer">
                <button class="btn-subscribe" onclick="subscribePackage()">
                    <i class="fas fa-user-plus"></i>
                    <span>اشترك الآن</span>
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentPackageId = null;

        function openModal(pkg) {
            currentPackageId = pkg.id;
            document.getElementById('modalTitle').textContent = pkg.name;
            document.getElementById('modalPrice').textContent = pkg.price + ' ر.س';
            document.getElementById('modalImg').src = pkg.image;

            let details = '';
            details += `<div class="detail-row">
                <div class="detail-icon"><i class="fas fa-calendar-alt"></i></div>
                <div class="detail-text">المدة</div>
                <div class="detail-value">${pkg.days} يوم</div>
            </div>`;
            details += `<div class="detail-row">
                <div class="detail-icon"><i class="fas fa-utensils"></i></div>
                <div class="detail-text">الوجبات يومياً</div>
                <div class="detail-value">${pkg.meals} وجبة</div>
            </div>`;
            details += `<div class="detail-row">
                <div class="detail-icon"><i class="fas fa-weight"></i></div>
                <div class="detail-text">الوزن</div>
                <div class="detail-value">${pkg.weight}</div>
            </div>`;
            details += `<div class="detail-row">
                <div class="detail-icon"><i class="fas fa-calendar-times"></i></div>
                <div class="detail-text">أيام الإجازة</div>
                <div class="detail-value">${pkg.off_days}</div>
            </div>`;
            
            if (pkg.limits && pkg.limits.length > 0) {
                details += `<div class="detail-row">
                    <div class="detail-icon"><i class="fas fa-list"></i></div>
                    <div class="detail-text">حدود التصنيفات</div>
                    <div class="detail-value"></div>
                </div>`;
                pkg.limits.forEach(limit => {
                    details += `<div class="detail-row" style="padding-right: 46px;">
                        <div class="detail-text">${limit.name}</div>
                        <div class="detail-value">${limit.allowed_count}</div>
                    </div>`;
                });
            }

            details += `<div class="detail-row">
                <div class="detail-icon"><i class="fas fa-sliders-h"></i></div>
                <div class="detail-text">الإضافات المرئية/القابلة للاختيار</div>
                <div class="detail-value">${pkg.options_visible} / ${pkg.options_select}</div>
            </div>`;

            if (pkg.desc) {
                details += `<div style="margin-top: 12px; padding: 12px; background: var(--bg); border-radius: 12px; font-weight: 800; font-size: 0.9rem; line-height: 1.6; color: var(--text-sub);">${pkg.desc}</div>`;
            }

            document.getElementById('modalDetails').innerHTML = details;
            document.getElementById('packageModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(e) {
            if (e && e.target !== e.currentTarget && !e.target.closest('.close-modal')) return;
            document.getElementById('packageModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        function subscribePackage() {
            if (currentPackageId) {
                // التوجيه إلى صفحة تسجيل الدخول مع package_id
                window.location.href = 'login.php?package=' + currentPackageId;
            }
        }

        // إغلاق بالزر Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    </script>

</body>
</html>

