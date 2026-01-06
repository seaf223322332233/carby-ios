<?php
/**
 * lang_handler.php - نظام إدارة اللغة
 * يدعم العربية والإنجليزية
 * 
 * مهم: يجب استدعاء هذا الملف قبل أي header() أو output
 */

// بدء الجلسة إذا لم تكن بدأت
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// معالجة تغيير اللغة - يجب أن تكون قبل أي output
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
    // حفظ اللغة في Session
    $_SESSION['lang'] = $_GET['lang'];
    
    // إعادة التوجيه إلى نفس الصفحة بدون معامل lang
    $currentPage = basename($_SERVER['PHP_SELF']);
    $params = $_GET;
    unset($params['lang']);
    
    // بناء URL بدون معامل lang
    $url = $currentPage;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    
    // إعادة التوجيه - استخدام relative URL
    // التأكد من عدم وجود output قبل header
    if (ob_get_level()) {
        ob_clean();
    }
    
    if (!headers_sent()) {
        header("Location: " . $url, true, 302);
        exit;
    } else {
        // إذا تم إرسال headers بالفعل، استخدم JavaScript
        if (ob_get_level()) {
            ob_end_clean();
        }
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';
        echo '<script>window.location.href = "' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '";</script>';
        echo '</body></html>';
        exit;
    }
}

// تحديد اللغة الحالية
$currentLang = $_SESSION['lang'] ?? 'ar';

// دالة للحصول على النص المترجم
function t($key, $ar = '', $en = '') {
    global $currentLang;
    if ($currentLang === 'en' && $en !== '') {
        return $en;
    }
    return $ar !== '' ? $ar : $key;
}

// دالة لعرض زر اللغة
function langSwitcher() {
    global $currentLang;
    
    // الحصول على الصفحة الحالية
    $currentPage = basename($_SERVER['PHP_SELF']);
    
    // الحصول على جميع المعاملات من URL
    $params = $_GET;
    // إضافة/تحديث معامل lang
    $params['lang'] = ($currentLang === 'ar' ? 'en' : 'ar');
    
    // بناء URL مع معامل lang
    $url = $currentPage . '?' . http_build_query($params);
    
    $otherLang = $currentLang === 'ar' ? 'en' : 'ar';
    $otherLangName = $currentLang === 'ar' ? 'English' : 'العربية';
    $otherLangIcon = $currentLang === 'ar' ? '🇬🇧' : '🇸🇦';
    
    return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" 
             class="lang-switcher" 
             title="' . htmlspecialchars(($currentLang === 'ar' ? 'Switch to English' : 'التبديل إلى العربية'), ENT_QUOTES, 'UTF-8') . '">
             <i class="fas fa-language"></i> ' . $otherLangIcon . ' ' . $otherLangName . '
           </a>';
}

