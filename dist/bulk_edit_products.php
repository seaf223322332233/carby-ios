<?php
header('Content-Type: text/html; charset=utf-8');
require_once 'auth_admin.php'; 
require_once 'db_connect.php'; 

// التحقق من وجود منتجات محددة
if (!isset($_POST['ids']) || empty($_POST['ids'])) {
    header("Location: manage_products.php"); exit;
}

$ids = $_POST['ids']; // مصفوفة الـ IDs
$placeholders = implode(',', array_fill(0, count($ids), '?'));

// جلب بيانات المنتجات المحددة فقط
$stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders)");
$stmt->execute($ids);
$products = $stmt->fetchAll();

// جلب التصنيفات للقائمة المنسدلة
$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تعديل مجمع</title>
    <link rel="stylesheet" href="style.css?v=13.0">
    <link rel="stylesheet" href="admin-unified-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    <style>
        /* تنسيق جدول التعديل */
        .edit-table input, .edit-table select {
            width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px;
            font-size: 0.9rem;
        }
        .edit-table td { padding: 5px; vertical-align: top; }
        .col-small { width: 80px; }
        .col-med { width: 120px; }
    </style>
</head>
<body>
    <div class="sidebar"><?php include 'sidebar.php'; ?></div>
    <div class="main-content">
        <header class="top-bar"><div class="user-info">التعديل المجمع</div></header>

        <main class="content-wrapper">
            <div class="form-card">
                <h2><i class="fas fa-edit"></i> تعديل <?php echo count($products); ?> منتجات دفعة واحدة</h2>
                
                <form action="handle_bulk_update.php" method="POST">
                    <div style="overflow-x:auto;">
                        <table class="data-table edit-table">
                            <thead>
                                <tr>
                                    <th>الاسم</th>
                                    <th>التصنيف</th>
                                    <th class="col-med">السعر</th>
                                    <th class="col-med">سعر العرض</th>
                                    <th class="col-med">الوزن</th>
                                    <th class="col-small">المخزون</th>
                                    <th class="col-small">نشط؟</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($products as $p): $pid = $p['id']; ?>
                                <tr>
                                    <td>
                                        <input type="text" name="products[<?php echo $pid; ?>][name]" value="<?php echo htmlspecialchars($p['name']); ?>" required>
                                        <input type="text" name="products[<?php echo $pid; ?>][barcode]" value="<?php echo htmlspecialchars($p['barcode']); ?>" placeholder="الباركود" style="margin-top:5px; color:#777; font-size:0.8rem;">
                                    </td>
                                    
                                    <td>
                                        <select name="products[<?php echo $pid; ?>][category_id]">
                                            <?php foreach($categories as $cat): ?>
                                                <option value="<?php echo $cat['id']; ?>" <?php echo ($cat['id'] == $p['category_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($cat['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>

                                    <td><input type="number" step="0.01" name="products[<?php echo $pid; ?>][price]" value="<?php echo $p['price']; ?>" required></td>
                                    
                                    <td><input type="number" step="0.01" name="products[<?php echo $pid; ?>][offer_price]" value="<?php echo $p['offer_price']; ?>" placeholder="لا يوجد"></td>
                                    
                                    <td>
                                        <input type="number" step="0.01" name="products[<?php echo $pid; ?>][weight]" value="<?php echo $p['weight']; ?>">
                                        <select name="products[<?php echo $pid; ?>][weight_unit]" style="margin-top:5px;">
                                            <option value="gram" <?php echo ($p['weight_unit']=='gram')?'selected':''; ?>>جرام</option>
                                            <option value="kg" <?php echo ($p['weight_unit']=='kg')?'selected':''; ?>>كيلو</option>
                                        </select>
                                    </td>
                                    
                                    <td><input type="number" name="products[<?php echo $pid; ?>][stock_qty]" value="<?php echo $p['stock_qty']; ?>"></td>
                                    
                                    <td>
                                        <select name="products[<?php echo $pid; ?>][is_active]">
                                            <option value="1" <?php echo ($p['is_active']==1)?'selected':''; ?>>نعم</option>
                                            <option value="0" <?php echo ($p['is_active']==0)?'selected':''; ?>>لا</option>
                                        </select>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="margin-top:20px; display:flex; gap:10px; justify-content:flex-end;">
                        <a href="manage_products.php" class="btn btn-danger">إلغاء</a>
                        <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> حفظ كل التعديلات</button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</body>
</html>