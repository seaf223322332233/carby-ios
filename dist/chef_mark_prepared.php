<?php
// هذا الملف يمكن استدعاؤه بواسطة الشيف أو المجهز أو المدير
session_start();
if ( !isset($_SESSION['user_id']) || 
     ($_SESSION['role'] !== 'chef' && $_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'preparer') ) {
    header("Location: login.php?error=auth");
    exit;
}

require_once 'db_connect.php'; 

if (!isset($_GET['client_id']) || !is_numeric($_GET['client_id'])) {
    die("خطأ: معرّف العميل غير موجود.");
}
$client_id = (int)$_GET['client_id'];
$today_date = date('Y-m-d');

// *** تحديد صفحة العودة (مهم) ***
$return_page = 'chef_dashboard.php'; // الافتراضي
if (isset($_GET['return_to']) && $_GET['return_to'] == 'preparer') {
    $return_page = 'preparer_dashboard.php'; // العودة لصفحة المجهز
}

try {
    // تحديث جميع أصناف العميل لليوم الحالي
    $sql = "UPDATE delivery_log SET status = 'prepared'
            WHERE client_id = ? AND delivery_date = ? AND status = 'pending'";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$client_id, $today_date]);
    
    // العودة إلى الصفحة الصحيحة
    header("Location: $return_page?success=prepared");
    exit;

} catch (PDOException $e) {
    die("❌ حدث خطأ أثناء تحديث حالة الطلب: " . $e->getMessage());
}
?>