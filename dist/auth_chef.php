<?php
// ابدأ الجلسة (يجب أن يكون أول شيء)
session_start();

// --- هذا هو "حارس الشيف" ---

// 1. هل المستخدم مسجل دخوله؟
// 2. هل دور المستخدم هو "chef"?
// (سنسمح أيضاً للمدير "admin" برؤية صفحة الشيف لتسهيل الاختبار)
if ( !isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'chef' && $_SESSION['role'] !== 'admin') ) {
    
    // 1. امسح أي بيانات جلسة خاطئة
    session_unset();
    session_destroy();

    // 2. أعد توجيهه إلى صفحة تسجيل الدخول
    header("Location: login.php?error=auth_chef");
    exit; // أوقف تنفيذ باقي الكود فوراً
}

// إذا وصل الكود إلى هنا، فالمستخدم هو "شيف" أو "مدير" معتمد.
$user_id_session = $_SESSION['user_id'];
$user_name_session = $_SESSION['name'];

?>