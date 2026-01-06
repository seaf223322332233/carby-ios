<?php
/**
 * config.php - ملف الإعدادات المركزي
 * يقرأ البيانات من ملف .env أو يستخدم القيم الافتراضية
 */

// تحديد مسار ملف .env
$envFile = __DIR__ . '/.env';

// قراءة ملف .env إذا كان موجوداً
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // تجاهل التعليقات
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // تقسيم السطر إلى key=value
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // إزالة علامات الاقتباس إن وجدت
            $value = trim($value, '"\'');
            
            // تعيين المتغيرات
            if (!defined($key)) {
                define($key, $value);
            }
        }
    }
}

// القيم الافتراضية (إذا لم يتم العثور على .env)
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'u132225456_chef');
if (!defined('DB_USER')) define('DB_USER', 'u132225456_chef123');
if (!defined('DB_PASS')) define('DB_PASS', 'S102030N@g');

if (!defined('APP_ENV')) define('APP_ENV', 'production');
if (!defined('DEBUG_MODE')) define('DEBUG_MODE', false);
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 3600);

// تحديد وضع التطوير
define('IS_DEBUG', (defined('DEBUG_MODE') && (DEBUG_MODE === true || DEBUG_MODE === 'true' || DEBUG_MODE === '1')));

