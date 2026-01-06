<?php
/**
 * add_pwa_meta.php - سكريبت لإضافة PWA meta tags لجميع صفحات العميل
 * ====================================================================
 * 
 * استخدم هذا السكريبت مرة واحدة لإضافة PWA meta tags لجميع صفحات العميل
 * 
 * طريقة الاستخدام:
 * 1. ارفع الملف إلى المجلد الرئيسي
 * 2. افتحه في المتصفح: http://yoursite.com/add_pwa_meta.php
 * 3. سيتم إضافة meta tags تلقائياً
 * 4. احذف الملف بعد الاستخدام لأمان
 */

// قائمة صفحات العميل
$client_pages = [
    'client_dashboard.php',
    'client_browse_packages.php',
    'client_package_details.php',
    'client_package_checkout.php',
    'client_select_meals.php',
    'client_profile.php',
    'menu.php',
    'my_orders.php',
    'cart.php',
    'client_history.php',
    'login.php',
    'register_client.php',
    'packages_public.php'
];

// Meta tags المطلوبة
$pwa_meta_tags = [
    '<link rel="manifest" href="manifest.json">',
    '<meta name="theme-color" content="#6c5ce7">',
    '<meta name="apple-mobile-web-app-capable" content="yes">',
    '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">',
    '<meta name="apple-mobile-web-app-title" content="وجباتي">',
    '<link rel="apple-touch-icon" href="uploads/logo.png">'
];

$results = [];
$modified = 0;
$skipped = 0;

foreach ($client_pages as $file) {
    if (!file_exists($file)) {
        $results[] = "❌ الملف غير موجود: $file";
        $skipped++;
        continue;
    }

    $content = file_get_contents($file);
    
    // التحقق من وجود meta tags
    $has_manifest = strpos($content, 'manifest.json') !== false;
    $has_theme_color = strpos($content, 'theme-color') !== false;
    
    if ($has_manifest && $has_theme_color) {
        $results[] = "✅ $file - يحتوي بالفعل على PWA meta tags";
        $skipped++;
        continue;
    }

    // البحث عن </head>
    if (strpos($content, '</head>') === false) {
        $results[] = "❌ $file - لا يحتوي على </head>";
        $skipped++;
        continue;
    }

    // إضافة meta tags قبل </head>
    $meta_to_add = '';
    if (!$has_manifest) {
        $meta_to_add .= $pwa_meta_tags[0] . "\n    ";
    }
    if (!$has_theme_color) {
        $meta_to_add .= $pwa_meta_tags[1] . "\n    ";
    }
    
    // إضافة بقية meta tags
    for ($i = 2; $i < count($pwa_meta_tags); $i++) {
        if (strpos($content, $pwa_meta_tags[$i]) === false) {
            $meta_to_add .= $pwa_meta_tags[$i] . "\n    ";
        }
    }

    if ($meta_to_add) {
        $content = str_replace('</head>', '    ' . trim($meta_to_add) . "\n</head>", $content);
        
        if (file_put_contents($file, $content)) {
            $results[] = "✅ $file - تم إضافة PWA meta tags بنجاح";
            $modified++;
        } else {
            $results[] = "❌ $file - فشل في الحفظ (تحقق من الصلاحيات)";
            $skipped++;
        }
    } else {
        $results[] = "⚠️ $file - جميع meta tags موجودة بالفعل";
        $skipped++;
    }
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إضافة PWA Meta Tags</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Tajawal', sans-serif;
            background: #f5f5f5;
            padding: 40px 20px;
            direction: rtl;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        h1 {
            color: #6c5ce7;
            margin-bottom: 30px;
            text-align: center;
        }
        .result {
            padding: 12px;
            margin: 8px 0;
            border-radius: 8px;
            border-right: 4px solid #ddd;
            background: #f8f9fa;
        }
        .summary {
            background: linear-gradient(135deg, #6c5ce7, #8e44ad);
            color: #fff;
            padding: 20px;
            border-radius: 12px;
            margin-top: 30px;
            text-align: center;
        }
        .summary h2 {
            margin-bottom: 15px;
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: #6c5ce7;
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📱 إضافة PWA Meta Tags لصفحات العميل</h1>
        
        <?php foreach ($results as $result): ?>
            <div class="result"><?php echo htmlspecialchars($result); ?></div>
        <?php endforeach; ?>
        
        <div class="summary">
            <h2>ملخص العملية</h2>
            <p>تم تعديل: <strong><?php echo $modified; ?></strong> ملف</p>
            <p>تم تخطي: <strong><?php echo $skipped; ?></strong> ملف</p>
            <p>المجموع: <strong><?php echo count($client_pages); ?></strong> ملف</p>
            
            <?php if ($modified > 0): ?>
                <p style="margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.3);">
                    ✅ <strong>تمت العملية بنجاح!</strong><br>
                    يمكنك الآن حذف هذا الملف (add_pwa_meta.php) للأمان
                </p>
            <?php endif; ?>
        </div>
        
        <div style="text-align: center;">
            <a href="client_dashboard.php" class="btn">العودة للتطبيق</a>
        </div>
    </div>
</body>
</html>

