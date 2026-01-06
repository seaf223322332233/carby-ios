<?php
session_start();
require_once 'db_connect.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['log_id']) && isset($_POST['new_meal_id'])) {
    
    $log_id = (int)$_POST['log_id'];
    $new_meal_id = (int)$_POST['new_meal_id'];
    $client_id = $_SESSION['user_id'];

    try {
        // التحقق مرة أخيرة أن الطلب يخص العميل وحالته pending
        $check = $pdo->prepare("SELECT id FROM delivery_log WHERE id = ? AND client_id = ? AND status = 'pending'");
        $check->execute([$log_id, $client_id]);
        
        if ($check->rowCount() > 0) {
            // تحديث الوجبة
            $update = $pdo->prepare("UPDATE delivery_log SET meal_id = ? WHERE id = ?");
            $update->execute([$new_meal_id, $log_id]);
            
            header("Location: client_history.php?success=1");
            exit;
        } else {
            die("خطأ: لا يمكن تعديل هذا الطلب.");
        }

    } catch (Exception $e) {
        die("خطأ: " . $e->getMessage());
    }
}

header("Location: client_history.php");
exit;
?>