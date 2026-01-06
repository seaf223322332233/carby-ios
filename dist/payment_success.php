<?php
require_once __DIR__ . '/auth.php';
requireRole(['client']);
require_once __DIR__ . '/db_connect.php';

$orderId   = (int)($_POST['order_id'] ?? 0);
$paymentId = (int)($_POST['payment_id'] ?? 0);

if ($orderId <= 0 || $paymentId <= 0) {
    die('طلب غير صالح');
}

$pdo->beginTransaction();

try {
    // تحديث الدفع
    $stmt = $pdo->prepare("
        UPDATE payments
        SET status = 'paid', transaction_id = ?
        WHERE id = ?
    ");
    $stmt->execute([
        'TXN-' . time(),
        $paymentId
    ]);

    // تحديث حالة الطلب
    $stmt = $pdo->prepare("
        UPDATE orders
        SET status = 'paid'
        WHERE id = ?
    ");
    $stmt->execute([$orderId]);

    $pdo->commit();

    header("Location: client_orders.php?paid=1");
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    die('فشل الدفع');
}
