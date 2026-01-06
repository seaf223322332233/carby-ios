<?php
/**
 * auth.php - حماية الصفحات
 * نظام مصادقة موحد مع تحسينات أمنية
 */

// تحميل ملف الإعدادات
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// تحسين أمان الجلسة
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');
    
    // تفعيل cookie_secure فقط في HTTPS
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', 1);
    }
    
    // تعيين وقت انتهاء الجلسة
    if (defined('SESSION_LIFETIME')) {
        ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
        ini_set('session.cookie_lifetime', SESSION_LIFETIME);
    }
    
    session_start();
}

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header("Location: login.php?error=auth");
    exit;
}

/**
 * دالة للتحقق من الصلاحيات
 * @param array $allowedRoles
 */
function requireRole(array $allowedRoles)
{
    if (!in_array($_SESSION['role'], $allowedRoles, true)) {
        header("Location: login.php?error=auth");
        exit;
    }
}
