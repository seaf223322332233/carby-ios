<?php
require_once 'auth_chef.php'; 
require_once 'db_connect.php'; 

if (!isset($_GET['type']) || !isset($_GET['id'])) {
    header("Location: chef_dashboard.php");
    exit;
}

$type = $_GET['type'];
$id = (int)$_GET['id'];

try {
    if ($type == 'subscription') {
        // تحديث جدول الاشتراكات
        $sql = "UPDATE delivery_log SET status = 'prepared' WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        
    } elseif ($type == 'menu') {
        // تحديث جدول المنيو
        // ملاحظة: في المنيو، الحالة تكون في جدول individual_orders (الرأس)
        // لكننا استلمنا ID العنصر (individual_order_items)
        // لذا سنجلب رقم الطلب الرئيسي ونحدثه
        
        // 1. جلب رقم الطلب الرئيسي
        $stmt_get_order = $pdo->prepare("SELECT order_id FROM individual_order_items WHERE id = ?");
        $stmt_get_order->execute([$id]);
        $order_id = $stmt_get_order->fetchColumn();
        
        if ($order_id) {
            // 2. تحديث حالة الطلب الرئيسي
            $sql = "UPDATE individual_orders SET status = 'prepared' WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$order_id]);
            
            // (اختياري: إرسال إشعار للعميل بأن الطلب قيد التحضير)
            $stmt_notify = $pdo->prepare("INSERT INTO notifications (client_id, message) 
                SELECT user_id, 'الشيف يقوم بتحضير طلبك الآن 👨‍🍳' FROM individual_orders WHERE id = ? AND user_id IS NOT NULL");
            $stmt_notify->execute([$order_id]);
        }
    }
    
    header("Location: chef_dashboard.php?success=received");
    exit;

} catch (PDOException $e) {
    die("حدث خطأ: " . $e->getMessage());
}
?>