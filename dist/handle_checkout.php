<?php
// handle_checkout.php - معالجة إتمام الطلب وحفظه
// ==============================================

session_start();
require_once 'db_connect.php';

header('Content-Type: application/json; charset=utf-8');

// 1. التحقق من السلة وتسجيل الدخول
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'يرجى تسجيل الدخول أولاً']);
    exit;
}

if (empty($_SESSION['cart'])) {
    echo json_encode(['status' => 'error', 'message' => 'السلة فارغة']);
    exit;
}

$client_id = $_SESSION['user_id'];
$today = date('Y-m-d');

try {
    $pdo->beginTransaction();

    // 2. جلب بيانات العميل (للعنوان ورقم الهاتف)
    $stmt_user = $pdo->prepare("SELECT u.name, cd.phone_number, cd.address_text, cd.Maps_link 
                                FROM users u 
                                LEFT JOIN client_details cd ON u.id = cd.user_id 
                                WHERE u.id = ?");
    $stmt_user->execute([$client_id]);
    $user_info = $stmt_user->fetch(PDO::FETCH_ASSOC);

    $customer_name = $user_info['name'] ?? 'عميل';
    $phone = $user_info['phone_number'] ?? '';
    $address = $user_info['address_text'] ?? '';
    $maps = $user_info['Maps_link'] ?? '';

    // 3. حساب الإجمالي
    $total_price = 0;
    foreach ($_SESSION['cart'] as $item) {
        $total_price += ($item['final_price'] * $item['qty']);
    }

    // 4. إنشاء الطلب الرئيسي (individual_orders)
    $sql_order = "INSERT INTO individual_orders 
                  (user_id, customer_name, customer_phone, address_text, Maps_link, total_price, status, order_date, payment_status, order_type) 
                  VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, 'unpaid', 'delivery')";
    
    $stmt_order = $pdo->prepare($sql_order);
    $stmt_order->execute([
        $client_id, 
        $customer_name, 
        $phone, 
        $address, 
        $maps, 
        $total_price, 
        $today
    ]);

    $order_id = $pdo->lastInsertId();

    // 5. إدراج عناصر الطلب (individual_order_items)
    $sql_item = "INSERT INTO individual_order_items 
                 (order_id, meal_id, quantity, price, options_json) 
                 VALUES (?, ?, ?, ?, ?)";
    
    $stmt_item = $pdo->prepare($sql_item);

    foreach ($_SESSION['cart'] as $item) {
        // تحويل الخيارات إلى نص JSON لحفظها
        $options_json = !empty($item['options']) ? json_encode($item['options'], JSON_UNESCAPED_UNICODE) : null;

        $stmt_item->execute([
            $order_id,
            $item['product_id'],
            $item['qty'],
            $item['final_price'], // سعر الوحدة شامل الإضافات
            $options_json // هنا يتم حفظ الخيارات
        ]);
    }

    // 6. نجاح العملية وتفريغ السلة
    $pdo->commit();
    unset($_SESSION['cart']);

    echo json_encode([
        'status' => 'success', 
        'message' => 'تم إرسال الطلب بنجاح!',
        'order_id' => $order_id
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    // تسجيل الخطأ للإدارة (اختياري) وعرض رسالة للمستخدم
    echo json_encode([
        'status' => 'error', 
        'message' => 'حدث خطأ أثناء حفظ الطلب: ' . $e->getMessage()
    ]);
}
?>