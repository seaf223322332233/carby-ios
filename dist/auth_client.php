<?php
// ابدأ الجلسة (يجب أن يكون أول شيء)
session_start();

// --- هذا هو "حارس العميل" ---

// 1. هل المستخدم مسجل دخوله؟
// 2. هل دور المستخدم هو "client"؟
if ( !isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client' ) {
    
    // إذا لم يكن مسجل دخوله، أو كان مسجلاً ولكنه ليس "عميل" (مثل مدير يحاول فتح الرابط)
    
    // 1. (اختياري) امسح أي بيانات جلسة خاطئة
    session_unset();
    session_destroy();

    // 2. أعد توجيهه إلى صفحة تسجيل الدخول
    header("Location: login.php?error=auth_client");
    exit; // أوقف تنفيذ باقي الكود فوراً
}

// إذا وصل الكود إلى هنا، فالمستخدم هو "عميل" معتمد.
$client_id = $_SESSION['user_id'];
$client_name = $_SESSION['name'];

?>