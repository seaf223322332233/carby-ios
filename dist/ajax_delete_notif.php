<?php
session_start();
require_once 'db_connect.php';

if (isset($_POST['id']) && isset($_SESSION['user_id'])) {
    $notif_id = (int)$_POST['id'];
    $client_id = $_SESSION['user_id'];
    
    // حذف الإشعار بشرط أن يخص العميل الحالي فقط (للأمان)
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND client_id = ?");
    $stmt->execute([$notif_id, $client_id]);
    
    echo "deleted";
}
?>