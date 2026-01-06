<?php
session_start();
require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: menu.php");
    exit;
}

$product_id = (int)($_POST['product_id'] ?? 0);
$qty        = max(1, (int)($_POST['qty'] ?? 1));

/* جلب المنتج من قاعدة البيانات */
$stmt = $pdo->prepare("SELECT id, name, price FROM products WHERE id = ?");
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if (!$product) {
    die('المنتج غير موجود');
}

/* تهيئة السلة */
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

/* إضافة أو تحديث المنتج */
if (isset($_SESSION['cart'][$product_id])) {
    $_SESSION['cart'][$product_id]['qty'] += $qty;
} else {
    $_SESSION['cart'][$product_id] = [
        'product_id'  => $product['id'],
        'name'        => $product['name'],
        'qty'         => $qty,
        'price'       => (float)$product['price'],
        'final_price' => (float)$product['price'],
        'options'     => []
    ];
}

header("Location: cart.php");
exit;
