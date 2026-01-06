<?php
require_once 'auth_admin.php';
require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['ids'])) {
    
    $action = $_POST['bulk_action'];
    $value = (float)$_POST['bulk_value'];
    $ids_placeholders = implode(',', array_fill(0, count($_POST['ids']), '?'));
    $ids = $_POST['ids'];

    try {
        $pdo->beginTransaction();

        if ($action == 'update_price') {
            // تغيير السعر لقيمة ثابتة
            $sql = "UPDATE products SET price = ? WHERE id IN ($ids_placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$value], $ids));

        } elseif ($action == 'increase_price_percent') {
            // زيادة السعر بنسبة مئوية
            $sql = "UPDATE products SET price = price + (price * ? / 100) WHERE id IN ($ids_placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$value], $ids));

        } elseif ($action == 'decrease_price_percent') {
            // عمل خصم (تحديث سعر العرض)
            // العرض = السعر الأصلي - النسبة
            $sql = "UPDATE products SET offer_price = price - (price * ? / 100) WHERE id IN ($ids_placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$value], $ids));

        } elseif ($action == 'update_weight') {
            // تغيير الوزن
            $sql = "UPDATE products SET weight = ? WHERE id IN ($ids_placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$value], $ids));

        } elseif ($action == 'delete') {
            // حذف
            $sql = "DELETE FROM products WHERE id IN ($ids_placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($ids);
        }

        $pdo->commit();
        header("Location: manage_products.php?success=1");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die("خطأ: " . $e->getMessage());
    }
}

header("Location: manage_products.php");
?>