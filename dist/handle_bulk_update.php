<?php
require_once 'auth_admin.php';
require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['products'])) {
    
    try {
        $pdo->beginTransaction();

        $sql = "UPDATE products SET 
                name = ?, category_id = ?, barcode = ?, price = ?, offer_price = ?, 
                weight = ?, weight_unit = ?, stock_qty = ?, is_active = ? 
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);

        foreach ($_POST['products'] as $id => $data) {
            // تنظيف البيانات
            $offer = !empty($data['offer_price']) ? $data['offer_price'] : NULL;
            
            $stmt->execute([
                trim($data['name']),
                (int)$data['category_id'],
                trim($data['barcode']),
                (float)$data['price'],
                $offer,
                (float)$data['weight'],
                $data['weight_unit'],
                (int)$data['stock_qty'],
                (int)$data['is_active'],
                (int)$id
            ]);
        }

        $pdo->commit();
        header("Location: manage_products.php?success=bulk_updated");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die("<h1>❌ حدث خطأ أثناء الحفظ:</h1><p>" . $e->getMessage() . "</p>");
    }

} else {
    header("Location: manage_products.php");
    exit;
}
?>