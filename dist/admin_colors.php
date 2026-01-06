<?php
/**
 * admin_colors.php - نظام التحكم بالألوان الديناميكية
 * ====================================================
 * يقرأ الألوان من قاعدة البيانات ويطبقها على جميع صفحات الأدمن
 */

// التحقق من وجود db_connect.php
if (!isset($pdo)) {
    require_once 'db_connect.php';
}

// جلب الألوان من قاعدة البيانات
$colorSettings = [];
try {
    if (isset($pdo)) {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'admin_color_%'");
        $colorSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
} catch (Exception $e) {
    $colorSettings = [];
}

// القيم الافتراضية (هوية المطعم - برتقالي دافئ)
$defaultColors = [
    'admin_color_primary' => '#d97706',
    'admin_color_primary_dark' => '#b45309',
    'admin_color_primary_light' => '#f59e0b',
    'admin_color_accent' => '#dc2626',
    'admin_color_success' => '#16a34a',
    'admin_color_warning' => '#eab308',
];

// دمج الألوان المخصصة مع الافتراضية
$colors = [];
foreach ($defaultColors as $key => $default) {
    $colors[$key] = $colorSettings[$key] ?? $default;
}

// إخراج CSS ديناميكي
header('Content-Type: text/css; charset=utf-8');
?>
:root {
    /* الألوان الديناميكية من إعدادات النظام */
    --restaurant-primary: <?php echo htmlspecialchars($colors['admin_color_primary']); ?>;
    --restaurant-primary-dark: <?php echo htmlspecialchars($colors['admin_color_primary_dark']); ?>;
    --restaurant-primary-light: <?php echo htmlspecialchars($colors['admin_color_primary_light']); ?>;
    --restaurant-accent: <?php echo htmlspecialchars($colors['admin_color_accent']); ?>;
    --restaurant-success: <?php echo htmlspecialchars($colors['admin_color_success']); ?>;
    --restaurant-warning: <?php echo htmlspecialchars($colors['admin_color_warning']); ?>;
    
    /* Gradient الديناميكي */
    --restaurant-gradient: linear-gradient(135deg, <?php echo htmlspecialchars($colors['admin_color_primary']); ?> 0%, <?php echo htmlspecialchars($colors['admin_color_primary_light']); ?> 100%);
    --restaurant-gradient-dark: linear-gradient(135deg, <?php echo htmlspecialchars($colors['admin_color_primary_dark']); ?> 0%, <?php echo htmlspecialchars($colors['admin_color_primary']); ?> 100%);
}

