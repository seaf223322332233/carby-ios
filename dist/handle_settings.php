<?php
// handle_settings.php - المعالج الشامل (واتساب + وقت الإغلاق + أيام العمل + رفع splash + تفعيل/إيقاف المنيو)
// ==================================================================================

require_once 'auth_admin.php';
require_once 'db_connect.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: system_settings.php");
    exit;
}

// 1) استقبال البيانات النصية
$whatsapp     = trim($_POST['whatsapp_number'] ?? '');
$cutoff_time  = trim($_POST['daily_cutoff_time'] ?? '');

// ✅ زر تفعيل/إيقاف المنيو
$menu_enabled = isset($_POST['menu_enabled']) ? '1' : '0';

// ✅ زر تفعيل/إيقاف قسم الطلبات
$orders_section_enabled = isset($_POST['orders_section_enabled']) ? '1' : '0';

// ✅ الألوان الديناميكية (من color picker أو text input)
$admin_color_primary = trim($_POST['admin_color_primary_text'] ?? $_POST['admin_color_primary'] ?? '');
$admin_color_primary_dark = trim($_POST['admin_color_primary_dark_text'] ?? $_POST['admin_color_primary_dark'] ?? '');
$admin_color_primary_light = trim($_POST['admin_color_primary_light_text'] ?? $_POST['admin_color_primary_light'] ?? '');
$admin_color_accent = trim($_POST['admin_color_accent_text'] ?? $_POST['admin_color_accent'] ?? '');
$admin_color_success = trim($_POST['admin_color_success_text'] ?? $_POST['admin_color_success'] ?? '');
$admin_color_warning = trim($_POST['admin_color_warning_text'] ?? $_POST['admin_color_warning'] ?? '');

// التحقق من صحة الألوان (يجب أن تكون بصيغة hex)
function validateColor($color) {
    if (empty($color)) return '';
    // إزالة المسافات
    $color = trim($color);
    // التحقق من صيغة hex
    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        return $color;
    }
    return '';
}

$admin_color_primary = validateColor($admin_color_primary) ?: '#d97706';
$admin_color_primary_dark = validateColor($admin_color_primary_dark) ?: '#b45309';
$admin_color_primary_light = validateColor($admin_color_primary_light) ?: '#f59e0b';
$admin_color_accent = validateColor($admin_color_accent) ?: '#dc2626';
$admin_color_success = validateColor($admin_color_success) ?: '#16a34a';
$admin_color_warning = validateColor($admin_color_warning) ?: '#eab308';

// أيام العمل
$work_days_str = '';
if (isset($_POST['work_days']) && is_array($_POST['work_days'])) {
    $work_days_str = implode(',', $_POST['work_days']);
}

try {
    $pdo->beginTransaction();

    // 2) تحديث النصوص في قاعدة البيانات
    $settings_map = [
        'whatsapp_number'   => $whatsapp,
        'daily_cutoff_time' => $cutoff_time,
        'active_weekdays'   => $work_days_str,

        // ✅ حفظ حالة المنيو
        'menu_enabled'      => $menu_enabled,
        
        // ✅ حفظ حالة قسم الطلبات
        'orders_section_enabled' => $orders_section_enabled,
        
        // ✅ حفظ الألوان الديناميكية
        'admin_color_primary' => $admin_color_primary,
        'admin_color_primary_dark' => $admin_color_primary_dark,
        'admin_color_primary_light' => $admin_color_primary_light,
        'admin_color_accent' => $admin_color_accent,
        'admin_color_success' => $admin_color_success,
        'admin_color_warning' => $admin_color_warning,
    ];

    $sql  = "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
    $stmt = $pdo->prepare($sql);

    foreach ($settings_map as $key => $value) {
        $stmt->execute([$key, $value]);
    }

    // 3) رفع الصور (Splash فقط)
    $upload_dir = 'uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
        chmod($upload_dir, 0755);
    }

    $image_fields = [
        'splash_screen_img'
    ];

    foreach ($image_fields as $field_name) {
        if (isset($_FILES[$field_name]) && $_FILES[$field_name]['error'] === UPLOAD_ERR_OK) {

            $file_tmp  = $_FILES[$field_name]['tmp_name'];
            $file_name = $_FILES[$field_name]['name'];
            $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            if (!in_array($file_ext, $allowed_exts)) continue;

            $new_file_name = $field_name . '_' . time() . '.' . $file_ext;
            $target_path   = $upload_dir . $new_file_name;

            if (move_uploaded_file($file_tmp, $target_path)) {
                // تحديث اسم الملف في DB
                $stmt->execute([$field_name, $new_file_name]);
            }
        }
    }

    $pdo->commit();

    header("Location: system_settings.php?success=1");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    die("خطأ في النظام: " . $e->getMessage());
}
