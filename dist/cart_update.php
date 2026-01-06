<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status'=>'error']);
    exit;
}

$id  = (int)($_POST['product_id'] ?? 0);
$qty = max(1, (int)($_POST['qty'] ?? 1));

if (!isset($_SESSION['cart'][$id])) {
    echo json_encode(['status'=>'error','msg'=>'not found']);
    exit;
}

$_SESSION['cart'][$id]['qty'] = $qty;

/* إعادة حساب الإجمالي */
$total = 0;
foreach ($_SESSION['cart'] as $item) {
    $total += $item['final_price'] * $item['qty'];
}

echo json_encode([
    'status' => 'success',
    'qty'    => $qty,
    'total'  => number_format($total,2)
]);
