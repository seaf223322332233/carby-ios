<?php
require_once 'auth_client.php';
require_once 'db_connect.php';
require_once 'csrf.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // التحقق من CSRF Token
    if (!verifyCSRFToken()) {
        header("Location: client_dashboard.php?error=csrf");
        exit;
    }
    $start = $_POST['start_date'];
    $end = $_POST['end_date'];
    
    // تحقق: يجب أن يكون قبل 24 ساعة
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    if ($start < $tomorrow) {
        $error = "يجب أن يبدأ الإيقاف بعد 24 ساعة على الأقل.";
    } else {
        // حساب الفرق
        $diff = (strtotime($end) - strtotime($start)) / (60*60*24);
        if ($diff > 7) {
            $error = "أقصى مدة للإيقاف هي 7 أيام.";
        } else {
            // حفظ الإيقاف
            $stmt = $pdo->prepare("INSERT INTO subscription_pauses (client_id, start_date, end_date) VALUES (?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $start, $end]);
            
            // (اختياري: تمديد اشتراك العميل بعدد أيام الإيقاف تلقائياً)
            $stmt_extend = $pdo->prepare("UPDATE client_details SET subscription_end_date = DATE_ADD(subscription_end_date, INTERVAL ? DAY) WHERE user_id = ?");
            $stmt_extend->execute([$diff, $_SESSION['user_id']]);
            
            header("Location: client_dashboard.php?success=paused"); exit;
        }
    }
}
?>