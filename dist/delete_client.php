<?php
// إجبار المتصفح على استخدام ترميز UTF-8
header('Content-Type: text/html; charset=utf-8');

// 1. تضمين "الحارس" (المصادقة)
require_once 'auth_admin.php'; 

// 2. تضمين ملف الاتصال
require_once 'db_connect.php'; // يجلب $pdo

// 3. التحقق من وجود ID في الرابط
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("خطأ: معرّف العميل غير موجود أو غير صالح.");
}
$user_id = $_GET['id'];

try {
    // --- الحذف بسيط جداً بفضل "ON DELETE CASCADE" ---
    
    // سنحذف فقط من جدول "users"
    // وستقوم قاعدة البيانات (MySQL) تلقائياً بحذف السجل المرتبط به في "client_details"
    
    $sql_delete = "DELETE FROM users WHERE id = ? AND role = 'client'";
    $stmt_delete = $pdo->prepare($sql_delete);
    $stmt_delete->execute([$user_id]);

    // التحقق من أن عملية الحذف تمت
    if ($stmt_delete->rowCount() > 0) {
        // تم الحذف بنجاح
        header("Location: view_clients.php?success=delete");
        exit;
    } else {
        // لم يتم العثور على العميل أو أنه ليس "عميل"
        header("Location: view_clients.php?error=notfound");
        exit;
    }

} catch (PDOException $e) {
    die("❌ خطأ في حذف العميل: " . $e->getMessage());
}
?>