<?php
session_start();
require_once 'db_connect.php';
require_once 'csrf.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_SESSION['user_id'])) {
    // التحقق من CSRF Token
    if (!verifyCSRFToken()) {
        header("Location: client_dashboard.php?error=csrf");
        exit;
    }
    
    $client_id = $_SESSION['user_id'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    
    // 1. التحقق من التواريخ
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $today = new DateTime();
    
    // يجب أن يكون البدء غداً على الأقل (قبل 24 ساعة)
    if ($start <= $today) {
        header("Location: client_dashboard.php?error=date_invalid"); exit;
    }
    
    // حساب الفرق بالأيام
    $diff = $start->diff($end)->days + 1; // +1 لحساب اليوم الأخير
    
    // التحقق من المدة (أقصى حد 7 أيام)
    if ($diff > 7 || $diff < 1) {
        header("Location: client_dashboard.php?error=duration_limit"); exit;
    }
    
    // 2. التحقق من رصيد الإيقاف (مرة واحدة فقط)
    $stmt = $pdo->prepare("SELECT pause_count, subscription_end_date FROM client_details WHERE user_id = ?");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch();
    
    if ($client['pause_count'] >= 1) {
        header("Location: client_dashboard.php?error=pause_limit_reached"); exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // أ. تسجيل الإيقاف
        $sql_pause = "INSERT INTO subscription_pauses (client_id, start_date, end_date, days_count) VALUES (?, ?, ?, ?)";
        $pdo->prepare($sql_pause)->execute([$client_id, $start_date, $end_date, $diff]);
        
        // ب. تمديد الاشتراك
        // تاريخ الانتهاء الجديد = القديم + عدد أيام الإيقاف
        $new_end_date = date('Y-m-d', strtotime($client['subscription_end_date'] . " + $diff days"));
        
        $sql_update = "UPDATE client_details SET subscription_end_date = ?, pause_count = pause_count + 1 WHERE user_id = ?";
        $pdo->prepare($sql_update)->execute([$new_end_date, $client_id]);
        
        $pdo->commit();
        header("Location: client_dashboard.php?success=paused");
        
    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: client_dashboard.php?error=system");
    }
} else {
    header("Location: client_dashboard.php");
}
?>