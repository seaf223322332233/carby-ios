<?php
/**
 * db_connect.php - الاتصال بقاعدة البيانات
 * يقرأ البيانات من ملف config.php الذي بدوره يقرأ من .env
 */

// تحميل ملف الإعدادات
require_once __DIR__ . '/config.php';

// تعيين ترميز الاتصال لضمان دعم اللغة العربية
$options = [
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4'",
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

// محاولة الاتصال
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        $options
    );
} catch (PDOException $e) {
    // في بيئة الإنتاج: لا نعرض تفاصيل الخطأ للمستخدم
    if (IS_DEBUG) {
        // في وضع التطوير: عرض الخطأ
        die("❌ خطأ في الاتصال بقاعدة البيانات: " . htmlspecialchars($e->getMessage()));
    } else {
        // في الإنتاج: تسجيل الخطأ فقط
        error_log("Database connection error: " . $e->getMessage());
        die("❌ حدث خطأ في الاتصال بالنظام. يرجى المحاولة لاحقاً.");
    }
}
// إذا نجح الاتصال، فإن المتغير $pdo جاهز للاستخدام في الملفات الأخرى
?>