<?php
require_once 'db_connect.php'; 

// *** هذا الملف لا يتم زيارته يدوياً، بل يتم استدعاؤه من قبل بوابة الدفع (Webhook) ***

// 1. قراءة البيانات المرسلة من بوابة الدفع (غالباً JSON أو POST)
$data = file_get_contents('php://input'); 
// $response_data = json_decode($data, true); 

// 2. مثال للمصادقة (يجب تعديل هذا ليناسب وثائق Tabby/Tamara):
// افترض أن المنصة ترسل order_id والحالة:
$order_id = $_GET['order_id'] ?? 0;
$payment_status = $_GET['status'] ?? 'failed'; // (paid, authorized, expired)

if ($order_id > 0 && $payment_status == 'paid') {
    
    // 3. تحديث حالة الطلب في قاعدة البيانات
    try {
        $sql = "UPDATE individual_orders SET payment_status = 'paid', status = 'pending' WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$order_id]);
        
        // (لا يوجد إعادة توجيه هنا، فقط رسالة نجاح بسيطة لبوابة الدفع)
        echo json_encode(['status' => 'success', 'message' => 'Order updated.']);
        
    } catch (Exception $e) {
        // تسجيل الخطأ
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error']);
    }

} else {
    // فشل الدفع أو البيانات غير صالحة
    http_response_code(400); 
    echo json_encode(['status' => 'failure', 'message' => 'Invalid or failed payment status.']);
}

?>