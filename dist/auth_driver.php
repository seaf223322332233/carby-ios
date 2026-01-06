<?php
// ابدأ الجلسة (يجب أن يكون أول شيء)
session_start();

// --- هذا هو "حارس السائق" ---

// 1. هل المستخدم مسجل دخوله؟
// 2. هل دور المستخدم هو "driver"؟
// (سنسمح أيضاً للمدير "admin" برؤية صفحة السائق لتسهيل الاختبار)
if ( !isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'driver' && $_SESSION['role'] !== 'admin') ) {
    
    // 1. امسح أي بيانات جلسة خاطئة
    session_unset();
    session_destroy();

    // 2. أعد توجيهه إلى صفحة تسجيل الدخول
    header("Location: login.php?error=auth_driver");
    exit; // أوقف تنفيذ باقي الكود فوراً
}

// إذا وصل الكود إلى هنا، فالمستخدم هو "سائق" أو "مدير" معتمد.
$user_id_session = $_SESSION['user_id'];
$user_name_session = $_SESSION['name'];

?>