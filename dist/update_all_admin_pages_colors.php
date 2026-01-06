<?php
/**
 * update_all_admin_pages_colors.php
 * ==================================
 * سكريبت لتحديث جميع صفحات الأدمن لإضافة admin_colors.php
 * 
 * استخدم هذا الملف مرة واحدة فقط لتحديث جميع الصفحات
 */

$admin_pages = [
    'view_packages.php',
    'view_clients.php',
    'view_staff.php',
    'view_branches.php',
    'manage_products.php',
    'manage_categories.php',
    'manage_options.php',
    'payment_settings.php',
    'marketing_center.php',
    'edit_product.php',
    'edit_package.php',
    'edit_branch.php',
    'edit_client.php',
    'edit_category.php',
    'edit_staff.php',
    'edit_option.php',
    'add_client.php',
    'add_staff.php',
];

$updated = [];
$errors = [];

foreach ($admin_pages as $file) {
    if (!file_exists($file)) {
        $errors[] = "الملف غير موجود: $file";
        continue;
    }
    
    $content = file_get_contents($file);
    
    // البحث عن السطر الذي يحتوي على admin-unified-style-v2.css
    $pattern = '/(<link\s+rel=["\']stylesheet["\']\s+href=["\']admin-unified-style-v2\.css["\']\s*\/?>)/i';
    
    if (preg_match($pattern, $content)) {
        // التحقق من عدم وجود admin_colors.php بالفعل
        if (strpos($content, 'admin_colors.php') === false) {
            $replacement = '<link rel="stylesheet" href="admin_colors.php">' . "\n    " . '$1';
            $new_content = preg_replace($pattern, $replacement, $content);
            
            if (file_put_contents($file, $new_content)) {
                $updated[] = $file;
            } else {
                $errors[] = "فشل في تحديث: $file";
            }
        } else {
            $updated[] = "$file (محدث مسبقاً)";
        }
    } else {
        $errors[] = "لم يتم العثور على admin-unified-style-v2.css في: $file";
    }
}

// عرض النتائج
echo "<!DOCTYPE html>
<html lang='ar' dir='rtl'>
<head>
    <meta charset='UTF-8'>
    <title>نتيجة التحديث</title>
    <style>
        body { font-family: 'Tajawal', sans-serif; padding: 20px; direction: rtl; }
        .success { color: #16a34a; font-weight: bold; }
        .error { color: #dc2626; font-weight: bold; }
        ul { list-style: none; padding: 0; }
        li { padding: 5px 0; }
    </style>
</head>
<body>
    <h1>نتيجة تحديث صفحات الأدمن</h1>
    
    <h2 class='success'>الصفحات المحدثة (" . count($updated) . "):</h2>
    <ul>";
foreach ($updated as $file) {
    echo "<li>✓ $file</li>";
}
echo "</ul>";

if (!empty($errors)) {
    echo "<h2 class='error'>الأخطاء (" . count($errors) . "):</h2>
    <ul>";
    foreach ($errors as $error) {
        echo "<li>✗ $error</li>";
    }
    echo "</ul>";
}

echo "<p><strong>ملاحظة:</strong> تم تحديث الصفحات الرئيسية يدوياً. يمكنك تشغيل هذا السكريبت لتحديث باقي الصفحات.</p>
</body>
</html>";
?>

