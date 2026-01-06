<?php
/**
 * csrf.php - نظام حماية CSRF
 * يوفر توليد والتحقق من CSRF Tokens
 */

// التأكد من بدء الجلسة
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * توليد CSRF Token
 * @return string
 */
function generateCSRFToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * التحقق من CSRF Token
 * @param string $token
 * @return bool
 */
function validateCSRFToken(string $token): bool
{
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * الحصول على CSRF Token من POST أو GET
 * @return string
 */
function getCSRFTokenFromRequest(): string
{
    return $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
}

/**
 * التحقق من CSRF Token في الطلب الحالي
 * @return bool
 */
function verifyCSRFToken(): bool
{
    $token = getCSRFTokenFromRequest();
    if (empty($token)) {
        return false;
    }
    return validateCSRFToken($token);
}

/**
 * إعادة توليد CSRF Token (يستخدم بعد تسجيل الدخول)
 * @return string
 */
function regenerateCSRFToken(): string
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

/**
 * توليد حقل hidden للـ CSRF Token في النماذج
 * @return string
 */
function csrfField(): string
{
    $token = generateCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

