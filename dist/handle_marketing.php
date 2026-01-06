<?php
// handle_marketing.php - إدارة مركز التسويق (حفظ إعلان الصفحة الرئيسية + إنشاء حملات + تبديل الحالة)
// ===================================================================================

require_once 'auth_admin.php';
require_once 'db_connect.php';

$upload_dir = __DIR__ . '/uploads/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
    chmod($upload_dir, 0755);
}

function safeText($v) {
    return trim((string)$v);
}

function uploadImage($field, $prefix = 'img') {
    global $upload_dir;

    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return '';
    }

    $tmp  = $_FILES[$field]['tmp_name'];
    $name = $_FILES[$field]['name'];

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowed)) {
        return '';
    }

    // اسم آمن بدون مسافات/رموز
    $newName = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $upload_dir . $newName;

    if (move_uploaded_file($tmp, $dest)) {
        return $newName;
    }

    return '';
}

// ----------------------------------------------------
// 1) حفظ إعلان الصفحة الرئيسية (من marketing_center.php)
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_dashboard_ad'])) {

    $ad_text = safeText($_POST['dashboard_ad_text'] ?? '');

    try {
        $pdo->beginTransaction();

        // حفظ النص
        $sql = "INSERT INTO system_settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['dashboard_ad_text', $ad_text]);

        // حفظ الصورة (إن وجدت)
        $ad_img = uploadImage('dashboard_ad_img', 'dashboard_ad');
        if ($ad_img !== '') {
            $stmt->execute(['dashboard_ad_img', $ad_img]);
        }

        $pdo->commit();
        header("Location: marketing_center.php?ad_saved=1");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        die("خطأ في النظام: " . $e->getMessage());
    }
}

// ----------------------------------------------------
// 2) إضافة حملة جديدة
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_campaign'])) {

    $title  = safeText($_POST['title'] ?? '');
    $msg    = safeText($_POST['message'] ?? '');
    $target = safeText($_POST['target_group'] ?? 'all');
    $link   = safeText($_POST['action_link'] ?? '');

    if ($title === '' || $msg === '') {
        header("Location: marketing_center.php?error=missing");
        exit;
    }

    // تحقق بسيط من target_group
    $allowedTargets = ['all', 'active', 'expired'];
    if (!in_array($target, $allowedTargets)) {
        $target = 'all';
    }

    $image = uploadImage('campaign_img', 'mkt');

    try {
        $pdo->beginTransaction();

        // إيقاف جميع الحملات الأخرى لتفعيل الجديدة فقط
        $pdo->query("UPDATE marketing_campaigns SET is_active = 0");

        $stmt = $pdo->prepare("
            INSERT INTO marketing_campaigns (title, message, image, action_link, target_group, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$title, $msg, $image, $link, $target]);

        $pdo->commit();

        header("Location: marketing_center.php?success=1");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        die("خطأ في النظام: " . $e->getMessage());
    }
}

// ----------------------------------------------------
// 3) تبديل حالة الحملة (Toggle)
// ----------------------------------------------------
if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE marketing_campaigns SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$id]);
        } catch (Exception $e) {
            // لا نوقف النظام، فقط نعيد توجيه
        }
    }
    header("Location: marketing_center.php");
    exit;
}

// ----------------------------------------------------
// 4) في حال تم فتح الملف مباشرة بدون أكشن
// ----------------------------------------------------
header("Location: marketing_center.php");
exit;


// ----------------------------------------------------
// (اختياري) دالة إرسال إشعار Firebase - تركناها Placeholder
// ----------------------------------------------------
function sendPushNotification($to, $title, $body, $image, $link) {
    // Placeholder: هنا تضع كود FCM (cURL) عند جاهزيته
}