<?php
// manage_products.php - النسخة الكاملة مع التعديل الجماعي والتكرار
// -------------------------------------------------------------------

// إجبار المتصفح على استخدام ترميز UTF-8
header('Content-Type: text/html; charset=utf-8');

// 1. الاتصال والحماية
if (file_exists('auth_admin.php')) require_once 'auth_admin.php';
require_once 'db_connect.php'; 

// ============================================================
// 2. معالجة الإجراءات الجماعية (Bulk Actions)
// ============================================================
$status_msg = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_action'])) {
    
    if (isset($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
        $ids = $_POST['selected_ids'];
        $action = $_POST['bulk_action'];

        // أ) حالة التكرار (Duplicate)
        if ($action == 'duplicate') {
            $count = 0;
            try {
                $pdo->beginTransaction();
                
                // تحضير استعلام جلب المنتج الأصلي
                $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
                
                // تحضير استعلام نسخ المنتج
                $ins_sql = "INSERT INTO products (name, category_id, price, barcode, description, stock_qty, offer_price, offer_end_date, calories, protein, carbs, fat, image) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $ins_stmt = $pdo->prepare($ins_sql);

                // تحضير استعلام جلب الخيارات
                $opt_get = $pdo->prepare("SELECT * FROM product_options WHERE product_id = ?");
                
                // تحضير استعلام نسخ الخيارات
                $opt_ins = $pdo->prepare("INSERT INTO product_options (product_id, name, quantity, price, sku, custom_fields) VALUES (?, ?, ?, ?, ?, ?)");

                foreach ($ids as $id) {
                    // 1. جلب بيانات المنتج الأصلي
                    $stmt->execute([$id]);
                    $prod = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($prod) {
                        // 2. إنشاء نسخة جديدة (نضيف كلمة "نسخة" للاسم)
                        $new_name = $prod['name'] . ' (نسخة)';
                        
                        $ins_stmt->execute([
                            $new_name, $prod['category_id'], $prod['price'], $prod['barcode'], 
                            $prod['description'], $prod['stock_qty'], $prod['offer_price'], 
                            $prod['offer_end_date'], $prod['calories'], $prod['protein'], 
                            $prod['carbs'], $prod['fat'], $prod['image'] // نستخدم نفس مسار الصورة
                        ]);
                        
                        $new_prod_id = $pdo->lastInsertId();

                        // 3. نسخ الخيارات المرتبطة
                        $opt_get->execute([$id]);
                        $options = $opt_get->fetchAll(PDO::FETCH_ASSOC);
                        
                        foreach ($options as $opt) {
                            $opt_ins->execute([
                                $new_prod_id, $opt['name'], $opt['quantity'], $opt['price'], 
                                $opt['sku'], $opt['custom_fields']
                            ]);
                        }
                        $count++;
                    }
                }
                $pdo->commit();
                $status_msg = "<div class='alert-success'><i class='fas fa-clone'></i> تم تكرار ($count) منتج بنجاح!</div>";

            } catch (Exception $e) {
                $pdo->rollBack();
                $status_msg = "<div class='alert-danger'>حدث خطأ أثناء التكرار: " . $e->getMessage() . "</div>";
            }

        // ب) حالة الحذف الجماعي (Delete)
        } elseif ($action == 'delete') {
            $count = 0;
            foreach ($ids as $id) {
                // جلب الصورة لحذفها
                $stmt = $pdo->prepare("SELECT image FROM products WHERE id = ?");
                $stmt->execute([$id]);
                $img = $stmt->fetchColumn();
                if ($img && file_exists('uploads/' . $img)) @unlink('uploads/' . $img);
                
                // الحذف من القاعدة
                $del = $pdo->prepare("DELETE FROM products WHERE id = ?");
                $del->execute([$id]);
                $count++;
            }
            $status_msg = "<div class='alert-danger'><i class='fas fa-trash'></i> تم حذف ($count) منتج نهائياً.</div>";
        }
    } else {
        $status_msg = "<div class='alert-warning'>يرجى تحديد منتج واحد على الأقل.</div>";
    }
}

// 3. جلب المنتجات للعرض
$sql = "SELECT p.*, c.name as category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        ORDER BY p.id DESC";
$products = $pdo->query($sql)->fetchAll();

// معالجة رسائل الرابط (GET)
if (isset($_GET['status']) && empty($status_msg)) {
    if ($_GET['status'] == 'success') $status_msg = '<div class="alert-success">تم حفظ البيانات بنجاح!</div>';
    if ($_GET['status'] == 'deleted') $status_msg = '<div class="alert-danger">تم حذف المنتج.</div>';
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المنتجات</title>
    
    <link rel="stylesheet" href="admin_colors.php">
    <link rel="stylesheet" href="admin-unified-style-v2.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        /* تنسيقات شريط الإجراءات الجماعية */
        .bulk-toolbar {
            background: #fff;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            border: 1px solid #e0e0e0;
        }
        
        .bulk-btn {
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
            display: flex; align-items: center; gap: 8px;
            font-family: inherit;
        }

        .btn-duplicate { background: #eef2ff; color: #4a69bd; border: 1px solid #4a69bd; }
        .btn-duplicate:hover { background: #4a69bd; color: #fff; }

        .btn-bulk-delete { background: #fff0f0; color: #e74c3c; border: 1px solid #e74c3c; }
        .btn-bulk-delete:hover { background: #e74c3c; color: #fff; }

        /* تنسيق مربع الاختيار داخل الكارت */
        .card-checkbox-wrapper {
            position: absolute;
            top: 15px;
            right: 15px;
            z-index: 10;
        }
        
        .custom-checkbox {
            width: 22px;
            height: 22px;
            cursor: pointer;
            accent-color: #6f42c1; /* لون بنفسجي عند التحديد */
        }

        /* تعديل مكان شارة التصنيف لتكون في اليسار بدلاً من اليمين (لإفساح المجال للاختيار) */
        .category-badge-left {
            position: absolute;
            top: 15px;
            left: 15px;
            background: rgba(255, 255, 255, 0.95);
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: bold;
            color: #6f42c1;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            z-index: 2;
        }

        /* رسائل التنبيه */
        .alert-success { background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-danger { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        /* ========================================= */
/* إصلاح شبكة عرض المنتجات (Grid System)     */
/* ========================================= */

/* 1. الحاوية الرئيسية (الشبكة) */
.product-grid {
    display: grid;
    /* هذا السطر يجعل الكروت تتجاوب تلقائياً مع الشاشة */
    /* يعرض 3 أو 4 منتجات في الصف للشاشات الكبيرة، و 1 للجوال */
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 25px; /* المسافة بين الكروت */
    padding-bottom: 50px;
}

/* 2. تصميم كرت المنتج */
.product-card {
    background: #fff;
    border-radius: 16px; /* حواف دائرية ناعمة */
    overflow: hidden; /* قص أي محتوى يخرج عن الإطار */
    border: 1px solid #f0f0f0;
    box-shadow: 0 4px 20px rgba(0,0,0,0.04); /* ظل خفيف جداً */
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    display: flex;
    flex-direction: column; /* ترتيب المحتوى عمودياً */
    height: 100%; /* لضمان تساوي ارتفاع جميع الكروت في نفس الصف */
    position: relative;
}

.product-card:hover {
    transform: translateY(-5px); /* حركة خفيفة للأعلى عند المرور */
    box-shadow: 0 10px 25px rgba(0,0,0,0.1);
}

/* 3. حاوية الصورة (لضبط الارتفاع الموحد) */
.prod-img-container {
    width: 100%;
    height: 200px; /* ارتفاع ثابت لجميع الصور مهما كان حجمها الأصلي */
    background-color: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
    border-bottom: 1px solid #eee;
}

/* 4. الصورة نفسها */
.prod-img {
    width: 100%;
    height: 100%;
    object-fit: cover; /* أهم خاصية: تملأ الإطار دون مط الصورة */
    transition: transform 0.5s ease;
}

.product-card:hover .prod-img {
    transform: scale(1.08); /* تكبير بسيط للصورة عند المرور */
}

/* 5. أيقونة (لا توجد صورة) */
.no-image-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #ccc;
    font-size: 0.9rem;
}

/* 6. شارة التصنيف (Category Badge) */
.category-badge {
    position: absolute;
    top: 15px;
    left: 15px; /* وضعناها يساراً لتبدو أجمل مع اللغة العربية */
    background: rgba(255, 255, 255, 0.95);
    padding: 5px 12px;
    border-radius: 30px;
    font-size: 0.75rem;
    font-weight: bold;
    color: #6f42c1; /* اللون البنفسجي */
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    z-index: 2;
}

/* 7. تفاصيل المنتج (النص) */
.prod-details {
    padding: 15px 20px;
    flex-grow: 1; /* يأخذ المساحة المتبقية لدفع الأزرار للأسفل */
    display: flex;
    flex-direction: column;
}

.prod-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #333;
    margin: 0 0 8px 0;
    white-space: nowrap; /* منع نزول العنوان لسطرين */
    overflow: hidden;
    text-overflow: ellipsis; /* وضع (...) إذا كان العنوان طويلاً */
}

.prod-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    font-size: 0.85rem;
    color: #888;
}

/* 8. السعر */
.price-area {
    margin-top: auto; /* دفع السعر للأسفل */
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 10px;
    border-top: 1px dashed #eee;
}

.current-price {
    font-size: 1.2rem;
    font-weight: 800;
    color: #27ae60; /* الأخضر */
}

.old-price {
    text-decoration: line-through;
    color: #aaa;
    font-size: 0.9rem;
    margin-right: 8px;
}

/* 9. شريط الأزرار (تعديل وحذف) */
.actions-bar {
    padding: 15px 20px;
    background-color: #fcfcfc;
    border-top: 1px solid #f0f0f0;
    display: flex;
    gap: 10px; /* مسافة بين الزرين */
}

.btn-action {
    flex: 1; /* الزر يأخذ نصف المساحة */
    padding: 10px 0;
    text-align: center;
    border-radius: 8px;
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 600;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
}

.btn-edit {
    background-color: #eef2ff;
    color: #4a69bd;
}
.btn-edit:hover {
    background-color: #4a69bd;
    color: #fff;
}

.btn-delete {
    background-color: #fff0f0;
    color: #e74c3c;
}
.btn-delete:hover {
    background-color: #e74c3c;
    color: #fff;
}
        
    </style>
</head>
<body>

    <div class="sidebar">
        <?php include 'sidebar.php'; ?>
    </div>

    <div class="main-content">
        
        <header class="top-bar">
            <div>
                <h2>إدارة المنتجات</h2>
                <span>عرض وتعديل وتكرار الوجبات</span>
            </div>
            <a href="add_product.php" style="background:#6f42c1; color:white; padding:10px 25px; border-radius:50px; text-decoration:none; font-weight:bold; box-shadow:0 4px 10px rgba(111, 66, 193, 0.3);">
                <i class="fas fa-plus"></i> إضافة منتج جديد
            </a>
        </header>

        <main class="content-wrapper">
            
            <?php echo $status_msg; ?>

            <form method="POST" action="" id="bulkForm">
                
                <div class="bulk-toolbar">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" id="selectAll" class="custom-checkbox">
                        <label for="selectAll" style="margin:0; font-weight:bold; cursor:pointer;">تحديد الكل</label>
                    </div>
                    
                    <div style="height:30px; width:1px; background:#ddd; margin:0 10px;"></div>

                    <button type="submit" name="bulk_action" value="duplicate" class="bulk-btn btn-duplicate">
                        <i class="fas fa-clone"></i> تكرار المحدد
                    </button>

                    <button type="submit" name="bulk_action" value="delete" class="bulk-btn btn-bulk-delete" onclick="return confirm('هل أنت متأكد من حذف المنتجات المحددة؟');">
                        <i class="fas fa-trash"></i> حذف المحدد
                    </button>
                </div>

                <?php if (count($products) > 0): ?>
                    <div class="product-grid">
                        <?php foreach ($products as $prod): ?>
                            
                            <?php 
                                $has_offer = false;
                                $display_price = $prod['price'];
                                if (!empty($prod['offer_price']) && $prod['offer_price'] > 0) {
                                    if (empty($prod['offer_end_date']) || new DateTime($prod['offer_end_date']) > new DateTime()) {
                                        $has_offer = true;
                                        $display_price = $prod['offer_price'];
                                    }
                                }
                            ?>

                            <div class="product-card" style="background:#fff; border-radius:15px; overflow:hidden; box-shadow:0 5px 15px rgba(0,0,0,0.05); border:1px solid #eee; position:relative; display:flex; flex-direction:column;">
                                
                                <div class="card-checkbox-wrapper">
                                    <input type="checkbox" name="selected_ids[]" value="<?php echo $prod['id']; ?>" class="custom-checkbox item-checkbox">
                                </div>

                                <div class="category-badge-left">
                                    <i class="fas fa-utensils"></i> <?php echo htmlspecialchars($prod['category_name'] ?? 'عام'); ?>
                                </div>

                                <div class="prod-img-container" style="height:200px; width:100%; display:flex; align-items:center; justify-content:center; overflow:hidden; background:#f9f9f9;">
                                    <?php if (!empty($prod['image'])): ?>
                                        <img src="uploads/<?php echo htmlspecialchars($prod['image']); ?>" style="width:100%; height:100%; object-fit:cover;">
                                    <?php else: ?>
                                        <div style="color:#ccc; text-align:center;"><i class="fas fa-image fa-3x"></i></div>
                                    <?php endif; ?>
                                </div>

                                <div class="prod-details" style="padding:15px; flex-grow:1; display:flex; flex-direction:column;">
                                    <h3 style="font-size:1.1rem; color:#333; margin:0 0 10px 0;"><?php echo htmlspecialchars($prod['name']); ?></h3>
                                    
                                    <div style="display:flex; justify-content:space-between; font-size:0.85rem; color:#777; margin-bottom:10px;">
                                        <span><?php echo !empty($prod['barcode']) ? $prod['barcode'] : '#---'; ?></span>
                                        <?php if ((int)$prod['stock_qty'] === -1): ?>
                                            <span style="color:#2980b9; background:#e8f4fd; padding:2px 8px; border-radius:4px; font-weight:bold;">
                                                <i class="fas fa-infinity"></i> لا محدود
                                            </span>
                                        <?php elseif ((int)$prod['stock_qty'] > 0): ?>
                                            <span style="color:#27ae60; background:#e8f8f5; padding:2px 8px; border-radius:4px;">متاح: <?php echo $prod['stock_qty']; ?></span>
                                        <?php else: ?>
                                            <span style="color:#e74c3c; background:#fdedec; padding:2px 8px; border-radius:4px;">نفذت الكمية</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div style="margin-top:auto; padding-top:10px; border-top:1px dashed #eee; display:flex; justify-content:space-between; align-items:center;">
                                        <div>
                                            <span style="font-size:1.2rem; font-weight:bold; color:#27ae60;"><?php echo number_format($display_price, 2); ?> ر.س</span>
                                            <?php if ($has_offer): ?>
                                                <span style="text-decoration:line-through; color:#aaa; font-size:0.9rem; margin-right:5px;"><?php echo number_format($prod['price'], 2); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="actions-bar" style="background:#fcfcfc; padding:10px 15px; border-top:1px solid #f0f0f0; display:flex; gap:10px;">
                                    <a href="edit_product.php?id=<?php echo $prod['id']; ?>" style="flex:1; text-align:center; padding:8px; background:#eef2ff; color:#4a69bd; border-radius:8px; text-decoration:none; font-size:0.9rem; font-weight:bold;">
                                        <i class="fas fa-edit"></i> تعديل
                                    </a>
                                    <a href="delete_product.php?id=<?php echo $prod['id']; ?>" onclick="return confirm('حذف نهائي لهذا المنتج؟');" style="flex:1; text-align:center; padding:8px; background:#fff0f0; color:#e74c3c; border-radius:8px; text-decoration:none; font-size:0.9rem; font-weight:bold;">
                                        <i class="fas fa-trash-alt"></i> حذف
                                    </a>
                                </div>
                            </div>

                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align:center; padding:50px; background:white; border-radius:15px; border:1px dashed #ddd;">
                        <h3 style="color:#777;">لا توجد منتجات. ابدأ بإضافة منتج جديد.</h3>
                    </div>
                <?php endif; ?>
            </form>

        </main>
    </div>

    <script>
        document.getElementById('selectAll').addEventListener('change', function() {
            var checkboxes = document.querySelectorAll('.item-checkbox');
            for (var checkbox of checkboxes) {
                checkbox.checked = this.checked;
            }
        });
    </script>
</body>
</html>