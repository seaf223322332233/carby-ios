<?php
require_once __DIR__ . '/auth.php';
requireRole(['client']);
require_once __DIR__ . '/db_connect.php';

$orderId = (int)($_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    die('طلب غير صالح');
}

// جلب الطلب
$stmt = $pdo->prepare("
    SELECT * FROM orders
    WHERE id = ? AND user_id = ?
    LIMIT 1
");
$stmt->execute([$orderId, $_SESSION['user_id']]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order || $order['status'] !== 'pending_payment') {
    die('هذا الطلب غير متاح للدفع');
}

// ================================
// إنشاء سجل دفع
$stmt = $pdo->prepare("
    INSERT INTO payments (order_id, method, amount)
    VALUES (?, 'online', ?)
");
$stmt->execute([$orderId, $order['total']]);
$paymentId = $pdo->lastInsertId();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>الدفع الإلكتروني</title>
    <style>
        body { font-family: Arial; background:#f4f6f9; text-align:center; padding:50px; }
        .box { background:white; padding:30px; border-radius:10px; max-width:400px; margin:auto; }
        button { padding:15px 30px; font-size:18px; background:#27ae60; color:white; border:none; border-radius:8px; cursor:pointer; }
    </style>
</head>
<body>

<div class="box">
    <h2>الدفع الإلكتروني</h2>
    <p>رقم الطلب: <strong>#<?= $orderId ?></strong></p>
    <p>المبلغ المطلوب: <strong><?= number_format($order['total'],2) ?> ر.س</strong></p>

    <!-- محاكاة زر الدفع -->
    <form method="POST" action="payment_success.php">
        <input type="hidden" name="order_id" value="<?= $orderId ?>">
        <input type="hidden" name="payment_id" value="<?= $paymentId ?>">
        <button type="submit">دفع الآن</button>
    </form>
</div>

</body>
</html>
