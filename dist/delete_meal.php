<?php
// إجبار المتصفح على استخدام ترميز UTF-8
header('Content-Type: text/html; charset=utf-8');

// 1. تضمين "الحارس" (المصادقة)
require_once 'auth_admin.php'; 

// 2. تضمين ملف الاتصال
require_once 'db_connect.php'; // يجلب $pdo

// 3. التحقق من وجود ID في الرابط
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("خطأ: معرّف الوجبة غير موجود أو غير صالح.");
}
$meal_id = $_GET['id'];

try {
    // --- الخطوة أ: جلب مسار الصورة قبل الحذف ---
    // (نحتاج لهذا لحذف الملف من الخادم)
    $sql_select = "SELECT image_url FROM meals WHERE id = ?";
    $stmt_select = $pdo->prepare($sql_select);
    $stmt_select->execute([$meal_id]);
    $meal = $stmt_select->fetch();

    if ($meal) {
        $image_to_delete = $meal['image_url'];

        // --- الخطوة ب: حذف السجل من قاعدة البيانات ---
        $sql_delete = "DELETE FROM meals WHERE id = ?";
        $stmt_delete = $pdo->prepare($sql_delete);
        $stmt_delete->execute([$meal_id]);

        // --- الخطوة ج: حذف ملف الصورة من الخادم ---
        // (نتحقق أن الملف موجود فعلاً قبل محاولة حذفه)
        if (file_exists($image_to_delete)) {
            unlink($image_to_delete); // دالة حذف الملفات
        }
        
        // --- الخطوة د: إعادة التوجيه إلى صفحة الإدارة ---
        header("Location: view_meals.php?success=delete");
        exit;
        
    } else {
        // الوجبة غير موجودة أصلاً
        header("Location: view_meals.php?error=notfound");
        exit;
    }

} catch (PDOException $e) {
    die("❌ خطأ في حذف الوجبة: " . $e->getMessage());
}
?>