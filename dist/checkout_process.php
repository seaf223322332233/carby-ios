<?php
// checkout_process.php - معالجة الطلب (متوافق مع قاعدة البيانات المرفقة)
// ======================================================================
session_start();
require_once 'db_connect.php';
header('Content-Type: application/json; charset=utf-8');

// التحقق من السلة
if (empty($_SESSION['cart'])) {
    echo json_encode(['status' => 'error', 'message' => 'السلة فارغة']);
    exit;
}

// استقبال البيانات
$user_id = $_SESSION['user_id'] ?? NULL;
$customer_name = $_POST['customer_name'] ?? 'Guest';
$customer_phone = $_POST['customer_phone'] ?? '';
$order_type = $_POST['order_type'] ?? 'delivery';
$address_text = $_POST['address_text'] ?? '';
$maps_link = $_POST['Maps_link'] ?? '';
$branch_id = !empty($_POST['branch_id']) ? $_POST['branch_id'] : NULL;
$payment_method = $_POST['payment_method'] ?? 'cod';
$today = date('Y-m-d');

// إذا كان استلام، نعدل العنوان ليكون واضحاً
if ($order_type == 'pickup') {
    $address_text = "استلام من الفرع";
}

// حساب الإجمالي
$total_price = 0;
foreach ($_SESSION['cart'] as $item) {
    // استخدمنا السعر النهائي المحفوظ في السلة
    $price = $item['final_price'] ?? $item['price'];
    $total_price += ($price * $item['qty']);
}

try {
    $pdo->beginTransaction();

    // 1. إدخال الطلب الرئيسي في individual_orders
    // الأعمدة متطابقة مع ملف SQL المرفق
    $sql = "INSERT INTO individual_orders 
            (user_id, customer_name, customer_phone, address_text, Maps_link, total_price, status, order_date, payment_status, order_type, branch_id, payment_method) 
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, 'unpaid', ?, ?, ?)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $user_id,
        $customer_name,
        $customer_phone,
        $address_text,
        $maps_link,
        $total_price,
        $today,
        $order_type,
        $branch_id,
        $payment_method
    ]);

    $order_id = $pdo->lastInsertId();

    // 2. إدخال عناصر الطلب في individual_order_items
    $sql_item = "INSERT INTO individual_order_items 
                 (order_id, meal_id, quantity, price, options_json) 
                 VALUES (?, ?, ?, ?, ?)";
    $stmt_item = $pdo->prepare($sql_item);

    foreach ($_SESSION['cart'] as $item) {
        $price = $item['final_price'] ?? $item['price'];
        // تحويل الخيارات لـ JSON
        $options_json = !empty($item['options']) ? json_encode($item['options'], JSON_UNESCAPED_UNICODE) : NULL;
        
        $stmt_item->execute([
            $order_id,
            $item['product_id'],
            $item['qty'],
            $price,
            $options_json
        ]);
    }

    $pdo->commit();
    unset($_SESSION['cart']); // تفريغ السلة

    echo json_encode(['status' => 'success', 'message' => 'تم الطلب بنجاح', 'order_id' => $order_id]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()]);
}
?>