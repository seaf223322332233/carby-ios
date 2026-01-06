<?php
// system_settings.php - الإصدار المطور 2.2
// (حذف الإشعار التسويقي + نقل إعلان الصفحة الرئيسية إلى marketing_center.php)
// ======================================================================

header('Content-Type: text/html; charset=utf-8');
require_once 'auth_admin.php';
require_once 'db_connect.php';

// جلب كافة الإعدادات
$settings = [];
try {
    $settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $settings = [];
}

// القيم الافتراضية
$cutoff_time = $settings['daily_cutoff_time'] ?? '20:00';
$whatsapp    = $settings['whatsapp_number'] ?? '';

// Splash
$splash_img  = $settings['splash_screen_img'] ?? '';

// ✅ تفعيل/إخفاء المنيو (افتراضي: مفعل)
$menu_enabled = ($settings['menu_enabled'] ?? '1') === '1';

// ✅ تفعيل/إخفاء قسم الطلبات (افتراضي: مفعل)
$orders_section_enabled = ($settings['orders_section_enabled'] ?? '1') === '1';

// ✅ الألوان الديناميكية
$admin_color_primary = $settings['admin_color_primary'] ?? '#d97706';
$admin_color_primary_dark = $settings['admin_color_primary_dark'] ?? '#b45309';
$admin_color_primary_light = $settings['admin_color_primary_light'] ?? '#f59e0b';
$admin_color_accent = $settings['admin_color_accent'] ?? '#dc2626';
$admin_color_success = $settings['admin_color_success'] ?? '#16a34a';
$admin_color_warning = $settings['admin_color_warning'] ?? '#eab308';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إدارة واجهة التطبيق</title>
    <link rel="stylesheet" href="admin_colors.php">
    <link rel="stylesheet" href="admin-unified-style-v2.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    <style>
        .admin-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .form-card { background: #fff; border-radius: 15px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 25px; border-top: 4px solid #4f46e5; }
        .section-title { font-size: 1.1rem; font-weight: 800; color: #2d3436; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #f1f2f6; display: flex; align-items: center; gap: 10px; }
        .preview-img { width: 100%; height: 120px; object-fit: contain; border-radius: 8px; border: 2px dashed #ddd; margin-top: 10px; background: #fafafa; }
        .form-group { margin-bottom: 15px; }
        label { font-weight: bold; display: block; margin-bottom: 8px; font-size: 0.9rem; }
        .form-control { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ddd; }

        /* Menu toggle (Premium switch) */
        .switch-wrap{
            display:flex; align-items:center; justify-content:space-between; gap:14px;
            padding: 14px;
            border: 1px solid #e5e7eb;
            background: #f5f7ff;
            border-radius: 12px;
        }
        .switch-meta small{ display:block; color:#6b7280; font-weight:700; margin-top:4px; line-height:1.5; }
        .switch {
            position: relative;
            width: 56px;
            height: 32px;
            display:inline-block;
            flex-shrink:0;
        }
        .switch input { display:none; }
        .slider {
            position:absolute; inset:0;
            background:#cbd5e1;
            border-radius: 999px;
            transition: .25s ease;
            box-shadow: inset 0 0 0 1px rgba(0,0,0,.05);
        }
        .slider:before{
            content:"";
            position:absolute;
            width: 26px; height: 26px;
            left: 3px; top: 3px;
            background:#fff;
            border-radius: 50%;
            transition: .25s ease;
            box-shadow: 0 6px 16px rgba(0,0,0,.18);
        }
        .switch input:checked + .slider{
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
        }
        .switch input:checked + .slider:before{
            transform: translateX(24px);
        }

        .hint-box{
            margin-top: 10px;
            background: #fff;
            border: 1px dashed #e5e7eb;
            border-radius: 12px;
            padding: 12px;
            color:#475569;
            font-weight:700;
            line-height:1.6;
        }
        .hint-box i{ color:#4f46e5; margin-left:6px; }

        .btn-save{
            width:100%;
            padding:18px;
            background:#4f46e5;
            color:white;
            border:none;
            border-radius:12px;
            font-weight:bold;
            cursor:pointer;
            font-size:1.1rem;
            margin-top:10px;
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3);
        }
        .success-box{
            background:#d1fae5; color:#065f46;
            padding:15px; border-radius:10px; margin-bottom:20px;
            font-weight:800;
        }

        /* ملاحظة نقل الإعلان */
        .moved-box{
            background:#fff7ed;
            color:#9a3412;
            border:1px solid #fed7aa;
            padding:12px;
            border-radius:12px;
            font-weight:800;
            margin-bottom:20px;
            line-height:1.7;
        }
        .moved-box a{
            color:#4f46e5;
            text-decoration:none;
            font-weight:900;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <header class="top-bar">
            <div class="user-info">إدارة مظهر التطبيق</div>
        </header>

        <main class="content-wrapper">

            <?php if(isset($_GET['success'])): ?>
                <div class="success-box">✓ تم حفظ الإعدادات ورفع الصور بنجاح</div>
            <?php endif; ?>

            <form action="handle_settings.php" method="POST" enctype="multipart/form-data">
                <div class="admin-grid">

                    <!-- الإعدادات الأساسية -->
                    <div class="form-card">
                        <div class="section-title"><i class="fas fa-tools"></i> الإعدادات الأساسية</div>

                        <div class="form-group">
                            <label>رقم الواتساب:</label>
                            <input type="text" name="whatsapp_number"
                                   value="<?php echo htmlspecialchars($whatsapp); ?>"
                                   class="form-control" placeholder="9665xxxxxxxx">
                        </div>

                        <div class="form-group">
                            <label>وقت إغلاق الطلبات:</label>
                            <input type="time" name="daily_cutoff_time"
                                   value="<?php echo htmlspecialchars($cutoff_time); ?>"
                                   class="form-control">
                        </div>
                    </div>

                    <!-- ✅ التحكم في المنيو -->
                    <div class="form-card">
                        <div class="section-title"><i class="fas fa-hamburger"></i> التحكم في صفحة المنيو</div>

                        <div class="switch-wrap">
                            <div class="switch-meta">
                                <div style="font-weight:900; font-size:1rem; color:#111827;">
                                    تفعيل صفحة المنيو (menu.php)
                                </div>
                                <small>
                                    عند الإيقاف: يختفي زر المنيو من الشريط السفلي، ويُمنع فتح الصفحة مباشرة.
                                </small>
                            </div>

                            <label class="switch" title="تفعيل/إيقاف المنيو">
                                <input type="checkbox" name="menu_enabled" value="1" <?php echo $menu_enabled ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="hint-box">
                            <i class="fas fa-circle-info"></i>
                            هذا الزر يتحكم في:
                            <b>menu.php</b> + إظهار/إخفاء رابط المنيو في <b>client_footer_nav.php</b>.
                        </div>
                    </div>

                    <!-- ✅ التحكم في قسم الطلبات -->
                    <div class="form-card">
                        <div class="section-title"><i class="fas fa-clipboard-list"></i> التحكم في قسم الطلبات</div>

                        <div class="switch-wrap">
                            <div class="switch-meta">
                                <div style="font-weight:900; font-size:1rem; color:#111827;">
                                    تفعيل قسم الطلبات (admin_orders.php)
                                </div>
                                <small>
                                    عند الإيقاف: يختفي رابط إدارة الطلبات من القائمة الجانبية، ويُمنع فتح الصفحة مباشرة.
                                </small>
                            </div>

                            <label class="switch" title="تفعيل/إيقاف قسم الطلبات">
                                <input type="checkbox" name="orders_section_enabled" value="1" <?php echo $orders_section_enabled ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="hint-box">
                            <i class="fas fa-circle-info"></i>
                            هذا الزر يتحكم في:
                            <b>admin_orders.php</b> + إظهار/إخفاء رابط الطلبات في <b>sidebar.php</b>.
                        </div>
                    </div>

                    <!-- Splash -->
                    <div class="form-card">
                        <div class="section-title"><i class="fas fa-mobile-alt"></i> شاشة الترحيب (Splash)</div>

                        <div class="form-group">
                            <label>شعار/صورة بداية التحميل:</label>
                            <input type="file" name="splash_screen_img" class="form-control">
                            <?php if($splash_img): ?>
                                <img src="uploads/<?php echo htmlspecialchars($splash_img); ?>" class="preview-img">
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ✅ التحكم في الألوان -->
                    <div class="form-card">
                        <div class="section-title"><i class="fas fa-palette"></i> ألوان لوحة الأدمن</div>
                        <p style="color:#6b7280; font-weight:700; margin-bottom:20px; line-height:1.6;">
                            <i class="fas fa-info-circle"></i> يمكنك تخصيص ألوان لوحة الأدمن والقائمة الجانبية من هنا
                        </p>

                        <div class="form-group">
                            <label>اللون الأساسي (Primary):</label>
                            <div style="display:flex; gap:10px; align-items:center;">
                                <input type="color" name="admin_color_primary" value="<?php echo htmlspecialchars($admin_color_primary); ?>" style="width:80px; height:40px; border-radius:8px; border:2px solid #ddd; cursor:pointer;">
                                <input type="text" name="admin_color_primary_text" value="<?php echo htmlspecialchars($admin_color_primary); ?>" class="form-control" placeholder="#d97706" style="flex:1;">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>اللون الأساسي الداكن (Primary Dark):</label>
                            <div style="display:flex; gap:10px; align-items:center;">
                                <input type="color" name="admin_color_primary_dark" value="<?php echo htmlspecialchars($admin_color_primary_dark); ?>" style="width:80px; height:40px; border-radius:8px; border:2px solid #ddd; cursor:pointer;">
                                <input type="text" name="admin_color_primary_dark_text" value="<?php echo htmlspecialchars($admin_color_primary_dark); ?>" class="form-control" placeholder="#b45309" style="flex:1;">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>اللون الأساسي الفاتح (Primary Light):</label>
                            <div style="display:flex; gap:10px; align-items:center;">
                                <input type="color" name="admin_color_primary_light" value="<?php echo htmlspecialchars($admin_color_primary_light); ?>" style="width:80px; height:40px; border-radius:8px; border:2px solid #ddd; cursor:pointer;">
                                <input type="text" name="admin_color_primary_light_text" value="<?php echo htmlspecialchars($admin_color_primary_light); ?>" class="form-control" placeholder="#f59e0b" style="flex:1;">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>لون التأكيد (Accent):</label>
                            <div style="display:flex; gap:10px; align-items:center;">
                                <input type="color" name="admin_color_accent" value="<?php echo htmlspecialchars($admin_color_accent); ?>" style="width:80px; height:40px; border-radius:8px; border:2px solid #ddd; cursor:pointer;">
                                <input type="text" name="admin_color_accent_text" value="<?php echo htmlspecialchars($admin_color_accent); ?>" class="form-control" placeholder="#dc2626" style="flex:1;">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>لون النجاح (Success):</label>
                            <div style="display:flex; gap:10px; align-items:center;">
                                <input type="color" name="admin_color_success" value="<?php echo htmlspecialchars($admin_color_success); ?>" style="width:80px; height:40px; border-radius:8px; border:2px solid #ddd; cursor:pointer;">
                                <input type="text" name="admin_color_success_text" value="<?php echo htmlspecialchars($admin_color_success); ?>" class="form-control" placeholder="#16a34a" style="flex:1;">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>لون التحذير (Warning):</label>
                            <div style="display:flex; gap:10px; align-items:center;">
                                <input type="color" name="admin_color_warning" value="<?php echo htmlspecialchars($admin_color_warning); ?>" style="width:80px; height:40px; border-radius:8px; border:2px solid #ddd; cursor:pointer;">
                                <input type="text" name="admin_color_warning_text" value="<?php echo htmlspecialchars($admin_color_warning); ?>" class="form-control" placeholder="#eab308" style="flex:1;">
                            </div>
                        </div>

                        <div class="hint-box">
                            <i class="fas fa-lightbulb"></i>
                            <strong>نصيحة:</strong> الألوان ستطبق فوراً على جميع صفحات الأدمن بعد الحفظ. يمكنك استخدام أداة اختيار الألوان أو إدخال الكود مباشرة.
                        </div>
                    </div>

                </div>

                <button type="submit" class="btn-save">
                    تحديث نظام التطبيق الآن
                </button>
            </form>

        </main>
    </div>

    <script>
        // ربط color picker مع text input
        document.addEventListener('DOMContentLoaded', function() {
            const colorPairs = [
                ['admin_color_primary', 'admin_color_primary_text'],
                ['admin_color_primary_dark', 'admin_color_primary_dark_text'],
                ['admin_color_primary_light', 'admin_color_primary_light_text'],
                ['admin_color_accent', 'admin_color_accent_text'],
                ['admin_color_success', 'admin_color_success_text'],
                ['admin_color_warning', 'admin_color_warning_text']
            ];

            colorPairs.forEach(([colorInput, textInput]) => {
                const colorEl = document.querySelector(`input[name="${colorInput}"]`);
                const textEl = document.querySelector(`input[name="${textInput}"]`);

                if (colorEl && textEl) {
                    // من color picker إلى text
                    colorEl.addEventListener('input', function() {
                        textEl.value = this.value;
                    });

                    // من text إلى color picker
                    textEl.addEventListener('input', function() {
                        if (/^#[0-9A-F]{6}$/i.test(this.value)) {
                            colorEl.value = this.value;
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>