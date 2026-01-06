<?php
require_once 'auth_admin.php';
require_once 'db_connect.php';

// ----------------------------------------------------
// 1) جلب إعدادات إعلان الصفحة الرئيسية من system_settings
// ----------------------------------------------------
$settings = [];
try {
    $settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $settings = [];
}

$ad_text = $settings['dashboard_ad_text'] ?? 'استمتع بوجباتك الصحية يومياً!';
$ad_img  = $settings['dashboard_ad_img'] ?? '';

// ----------------------------------------------------
// 2) جلب الحملات الحالية
// ----------------------------------------------------
$campaigns = $pdo->query("SELECT * FROM marketing_campaigns ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>مركز التسويق الذكي</title>
    <link rel="stylesheet" href="admin-unified-style-v2.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    <style>
        .campaign-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px; }
        .card { background: #fff; padding: 20px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); border-top: 4px solid #4f46e5; }
        .status-badge { padding: 5px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; }
        .active { background: #d1fae5; color: #065f46; }
        .inactive { background: #fee2e2; color: #991b1b; }

        .success-box{
            background:#d1fae5; color:#065f46;
            padding:14px; border-radius:12px; font-weight:900;
            margin: 0 0 15px 0;
        }
        .info-box{
            background:#eff6ff; color:#1e40af;
            padding:12px; border-radius:12px; font-weight:800;
            margin: 10px 0 0 0;
            border: 1px solid #bfdbfe;
        }
        .preview-img{
            width:100%;
            height:120px;
            object-fit:contain;
            border-radius:12px;
            margin-top:12px;
            border:2px dashed #ddd;
            background:#fafafa;
        }
        .btn-sm{
            padding:8px 12px;
            border-radius:10px;
            background:#f3f4f6;
            color:#111827;
            text-decoration:none;
            font-weight:900;
            border:1px solid #e5e7eb;
        }
        .btn-sm:hover{ background:#e5e7eb; }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<div class="main-content">
    <header class="top-bar">
        <h2 style="margin:0;">
            <i class="fas fa-bullhorn"></i> مركز التسويق الذكي
        </h2>
    </header>

    <main class="content-wrapper">

        <?php if(isset($_GET['ad_saved'])): ?>
            <div class="success-box">✓ تم تحديث إعلان الصفحة الرئيسية بنجاح</div>
        <?php endif; ?>

        <?php if(isset($_GET['success'])): ?>
            <div class="success-box">✓ تم تنفيذ العملية بنجاح</div>
        <?php endif; ?>

        <!-- ----------------------------------------------------
             1) إعلان الصفحة الرئيسية (منقول من system_settings.php)
        ----------------------------------------------------- -->
        <div class="card">
            <h3 style="margin-top:0;"><i class="fas fa-ad"></i> إعلان الصفحة الرئيسية</h3>

            <div class="info-box">
                هذا الإعلان يظهر داخل <b>client_dashboard.php</b> (الصفحة الرئيسية للعميل).
            </div>

            <form action="handle_marketing.php" method="POST" enctype="multipart/form-data" style="margin-top:15px;">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <input type="text" name="dashboard_ad_text"
                           value="<?php echo htmlspecialchars($ad_text); ?>"
                           class="form-control" placeholder="النص الترويجي للإعلان">

                    <input type="file" name="dashboard_ad_img" class="form-control">
                </div>

                <?php if($ad_img): ?>
                    <img src="uploads/<?php echo htmlspecialchars($ad_img); ?>" class="preview-img">
                <?php endif; ?>

                <button type="submit" name="save_dashboard_ad" class="btn btn-primary" style="margin-top:15px; width:100%;">
                    حفظ إعلان الصفحة الرئيسية
                </button>
            </form>
        </div>

        <!-- ----------------------------------------------------
             2) إنشاء حملة ترويجية جديدة
        ----------------------------------------------------- -->
        <div class="card">
            <h3 style="margin-top:0;">إنشاء حملة ترويجية جديدة</h3>
            <form action="handle_marketing.php" method="POST" enctype="multipart/form-data">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <input type="text" name="title" placeholder="عنوان الإشعار (مثلاً: عرض الجمعة)" required class="form-control">
                    <select name="target_group" class="form-control">
                        <option value="all">إرسال للجميع</option>
                        <option value="active">المشتركين الحاليين فقط</option>
                        <option value="expired">الاشتراكات المنتهية فقط</option>
                    </select>

                    <textarea name="message" placeholder="نص الرسالة التسويقية..." required class="form-control"
                              style="grid-column: span 2; height: 80px;"></textarea>

                    <input type="file" name="campaign_img" class="form-control">
                    <input type="text" name="action_link" placeholder="رابط الإجراء (Link)" class="form-control">
                </div>

                <button type="submit" name="add_campaign" class="btn btn-primary" style="margin-top:15px; width:100%;">
                    إطلاق الحملة الآن
                </button>
            </form>
        </div>

        <!-- ----------------------------------------------------
             3) عرض الحملات
        ----------------------------------------------------- -->
        <div class="campaign-grid">
            <?php foreach($campaigns as $c): ?>
                <div class="card" style="border-top-color: <?php echo $c['is_active'] ? '#10b981' : '#ef4444'; ?>;">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
                        <span class="status-badge <?php echo $c['is_active'] ? 'active' : 'inactive'; ?>">
                            <?php echo $c['is_active'] ? 'نشط الآن' : 'متوقف'; ?>
                        </span>
                        <a href="handle_marketing.php?toggle=<?php echo (int)$c['id']; ?>" class="btn-sm">
                            تبديل الحالة
                        </a>
                    </div>

                    <h4 style="margin:12px 0 8px 0;"><?php echo htmlspecialchars($c['title']); ?></h4>
                    <p style="font-size:0.9rem; color:#666; margin:0;">
                        <?php echo nl2br(htmlspecialchars($c['message'])); ?>
                    </p>

                    <?php if(!empty($c['action_link'])): ?>
                        <div style="margin-top:10px; font-weight:900;">
                            <i class="fas fa-link"></i>
                            <a href="<?php echo htmlspecialchars($c['action_link']); ?>" target="_blank" style="text-decoration:none;">
                                فتح الرابط
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if(!empty($c['image'])): ?>
                        <img src="uploads/<?php echo htmlspecialchars($c['image']); ?>"
                             style="width:100%; height:120px; object-fit:cover; border-radius:12px; margin-top:12px;">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

    </main>
</div>
</body>
</html>
