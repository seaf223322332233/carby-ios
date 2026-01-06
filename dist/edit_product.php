<?php
// edit_product.php - (تصميم مبهر + خيارات مصنفة + توافق 100% مع handle_product الجديد)
// ===================================================================================

header('Content-Type: text/html; charset=utf-8');

if (file_exists('auth_admin.php')) require_once 'auth_admin.php';
require_once 'db_connect.php';

// 1) التحقق من ID المنتج
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: manage_products.php");
    exit;
}
$product_id = (int)$_GET['id'];

// 2) جلب بيانات المنتج
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$product_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$product) die("المنتج غير موجود.");

// 3) جلب الأحجام
$stmtSizes = $pdo->prepare("SELECT * FROM product_sizes WHERE product_id = ? ORDER BY id ASC");
$stmtSizes->execute([$product_id]);
$existing_sizes = $stmtSizes->fetchAll(PDO::FETCH_ASSOC);

// 4) جلب تصنيفات المنتجات (categories)
$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// 5) جلب روابط الخيارات المحفوظة للمنتج (product_options)
$linked_map = [];
$opt_stmt = $pdo->prepare("SELECT global_option_id, quantity, price FROM product_options WHERE product_id = ?");
$opt_stmt->execute([$product_id]);
$saved_options = $opt_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($saved_options as $opt) {
    $gid = (int)$opt['global_option_id'];
    $linked_map[$gid] = [
        'quantity' => (float)($opt['quantity'] ?? 1),
        'price'    => $opt['price'] // قد تكون NULL
    ];
}

// 6) جلب تصنيفات الخيارات + الخيارات (مصنفة)
$opt_categories = [];
$opt_by_cat = []; // [cat_id => options[]]

try {
    $checkCat = $pdo->query("SHOW TABLES LIKE 'option_categories'");
    $checkOpt = $pdo->query("SHOW TABLES LIKE 'global_options'");

    if ($checkOpt->rowCount() > 0) {

        // التصنيفات (إن وجدت)
        if ($checkCat->rowCount() > 0) {
            $opt_categories = $pdo->query("
                SELECT * FROM option_categories
                WHERE is_active = 1
                ORDER BY sort_order ASC, id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        // الخيارات (نشطة فقط)
        // ملاحظة: نفترض وجود أعمدة: category_id, is_active
        $global_options = $pdo->query("
            SELECT o.*, c.name AS category_name, c.sort_order
            FROM global_options o
            LEFT JOIN option_categories c ON c.id = o.category_id
            WHERE (o.is_active IS NULL OR o.is_active = 1)
            ORDER BY (c.sort_order IS NULL), c.sort_order ASC, o.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($global_options as $o) {
            $cat_id = (int)($o['category_id'] ?? 0);
            if (!isset($opt_by_cat[$cat_id])) $opt_by_cat[$cat_id] = [];
            $opt_by_cat[$cat_id][] = $o;
        }

        // لو ما فيه تصنيفات بالجدول أو كلها غير فعالة: نعمل تصنيف افتراضي (بدون تصنيف)
        if (empty($opt_categories)) {
            $opt_categories = [
                ['id' => 0, 'name' => 'بدون تصنيف']
            ];
        }
    }
} catch (PDOException $e) {
    // نتركها فاضية لو حصل مشكلة
}

// Helper: عنوان بسيط للـ unit
function unitLabel($unit) {
    $map = [
        'gram'  => 'جرام',
        'ml'    => 'مل',
        'piece' => 'قطعة',
        'kg'    => 'كيلو',
        'liter' => 'لتر'
    ];
    return $map[$unit] ?? $unit;
}

// Helper: حساب عدد tiers لعرض "عدد المستويات"
function tiersCount($pricing_config) {
    $pricing = json_decode($pricing_config ?? '', true);
    return count($pricing['tiers'] ?? []);
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تعديل: <?php echo htmlspecialchars($product['name']); ?></title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin-unified-style-v2.css">

    <style>
        :root {
            --primary: #4f46e5;
            --bg-color: #f3f4f6;
            --card-bg: #ffffff;
            --text-main: #111827;
            --border-color: #e5e7eb;
        }
        body { font-family:'Tajawal',sans-serif; background:var(--bg-color); color:var(--text-main); margin:0; padding:0; direction:rtl; }

        .page-header{
            display:flex; justify-content:space-between; align-items:center;
            background:#fff; padding:20px 30px; border-radius:16px;
            box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom:30px;
        }
        .btn-back{
            background:#f3f4f6; color:#374151; padding:10px 25px; border-radius:12px;
            text-decoration:none; font-weight:700; transition:0.2s;
        }

        .section-card{
            background:#fff; border-radius:20px; padding:30px;
            box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom:30px;
            border:1px solid var(--border-color);
        }
        .card-header{
            display:flex; align-items:center; gap:10px; margin-bottom:25px;
            padding-bottom:15px; border-bottom:2px dashed var(--border-color);
        }
        .card-header h3 { margin:0; font-size:1.3rem; font-weight:800; color:var(--text-main); }

        label { display:block; font-weight:800; margin-bottom:8px; color:#374151; font-size:0.95rem; }
        .form-control{
            width:100%; height:50px; padding:0 15px; border-radius:10px;
            border:2px solid var(--border-color); background:#fff;
            font-family:'Tajawal',sans-serif; font-size:1rem; transition:0.2s; box-sizing:border-box;
        }
        .form-control:focus { border-color:var(--primary); box-shadow:0 0 0 4px rgba(79,70,229,0.1); outline:none; }
        .form-control[readonly] { background-color:#f3f4f6; cursor:not-allowed; }

        textarea.form-control{
            min-height:200px !important; height:auto; padding:15px; line-height:1.6; resize:vertical;
        }

        /* stock */
        .stock-wrapper{position:relative;}
        .stock-overlay{
            position:absolute; top:0; left:0; width:100%; height:100%;
            background:#ecfdf5; border-radius:10px; border:2px solid #a7f3d0;
            display:none; align-items:center; justify-content:center;
            font-size:2rem; color:#10b981; font-weight:bold;
        }
        .toggle-switch{position:relative; display:inline-block; width:50px; height:26px;}
        .toggle-switch input{opacity:0; width:0; height:0;}
        .slider{position:absolute; cursor:pointer; inset:0; background-color:#ccc; transition:.4s; border-radius:34px;}
        .slider:before{position:absolute; content:""; height:20px; width:20px; left:3px; bottom:3px; background-color:white; transition:.4s; border-radius:50%;}
        input:checked + .slider{background-color:var(--primary);}
        input:checked + .slider:before{transform:translateX(24px);}

        /* sizes */
        .size-card{background:#f9fafb; border:2px solid #e5e7eb; border-radius:16px; padding:25px; margin-bottom:25px; position:relative;}
        .size-main-grid{display:grid; grid-template-columns:1.5fr 1fr 1fr 1fr; gap:20px; margin-bottom:20px; border-bottom:1px solid #e5e7eb; padding-bottom:20px;}
        .size-macro-grid{display:grid; grid-template-columns:repeat(4,1fr); gap:15px;}
        .input-hero{
            height:60px !important; font-size:1.3rem !important; font-weight:800 !important;
            text-align:center; color:var(--primary); background:#fff !important; border:2px solid #d1d5db;
        }
        .field-label{display:block; font-size:.85rem; color:#6b7280; margin-bottom:5px; font-weight:bold;}
        .macro-box input { text-align:center; font-weight:bold; border-bottom-width:4px; }
        .macro-cal input { border-color:#fca5a5; }
        .macro-pro input { border-color:#93c5fd; }
        .macro-carb input { border-color:#fde047; }
        .macro-fat input { border-color:#86efac; }

        .btn-trash{
            position:absolute; top:15px; left:15px;
            background:#fee2e2; color:#ef4444; width:40px; height:40px;
            border:none; border-radius:50%; cursor:pointer;
            display:flex; align-items:center; justify-content:center; transition:.2s;
        }
        .btn-trash:hover{background:#ef4444; color:#fff;}
        .btn-add-size{
            width:100%; padding:15px; background:#e0e7ff; color:var(--primary);
            border:2px dashed var(--primary); border-radius:12px; font-weight:800; font-size:1.1rem;
            cursor:pointer; transition:.3s;
        }
        .btn-add-size:hover{background:var(--primary); color:#fff;}

        /* categories pills */
        .categories-grid{display:flex; flex-wrap:wrap; gap:10px;}
        .cat-option{display:none;}
        .cat-label{
            padding:12px 25px; background:#f3f4f6; border-radius:50px;
            cursor:pointer; font-weight:700; color:#6b7280; transition:.3s;
        }
        .cat-option:checked + .cat-label{
            background:var(--primary); color:#fff; box-shadow:0 4px 12px rgba(79,70,229,.3);
        }

        /* options - grouped */
        .options-toolbar{
            display:flex; gap:10px; align-items:center; justify-content:space-between;
            padding:12px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; margin-bottom:12px;
        }
        .search-input{height:44px; border-radius:12px;}
        .options-groups{display:flex; flex-direction:column; gap:14px;}
        .opt-group{
            background:#f9fafb; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden;
        }
        .opt-group-header{
            display:flex; justify-content:space-between; align-items:center;
            padding:14px 16px; cursor:pointer;
            background:#fff; border-bottom:1px dashed #e5e7eb;
        }
        .opt-group-title{font-weight:900; color:#111827; display:flex; gap:10px; align-items:center;}
        .opt-count{font-size:.85rem; color:#6b7280; font-weight:bold;}
        .opt-group-body{padding:10px; display:block;}

        .options-list{max-height:380px; overflow-y:auto; border-radius:12px;}
        .option-item{
            display:flex; align-items:center; justify-content:space-between;
            background:#fff; padding:12px; margin-bottom:8px;
            border-radius:10px; border:1px solid #e5e7eb; transition:.2s;
        }
        .option-item.active{border-color:var(--primary); background:#eef2ff;}
        .option-main{display:flex; align-items:center; gap:15px; flex:1;}
        .option-check{width:22px; height:22px; accent-color:var(--primary); cursor:pointer;}
        .option-inputs{display:flex; gap:10px; opacity:.4; pointer-events:none; transition:.3s;}
        .option-item.active .option-inputs{opacity:1; pointer-events:auto;}

        .mini-badge{
            display:inline-flex; align-items:center; gap:8px;
            padding:6px 10px; border-radius:999px; font-weight:800; font-size:.8rem;
            background:#eef2ff; color:#3730a3;
        }
        .mini-muted{background:#f3f4f6; color:#6b7280;}

        .image-upload-box{
            width:100%; height:250px; border:3px dashed #d1d5db; border-radius:16px;
            background:#f9fafb; display:flex; flex-direction:column; align-items:center; justify-content:center;
            cursor:pointer; position:relative; overflow:hidden;
        }
        .image-upload-box img{width:100%; height:100%; object-fit:cover; position:absolute; inset:0;}

        .btn-save{
            width:100%; padding:20px; background:#10b981; color:#fff;
            border:none; border-radius:14px; font-size:1.3rem; font-weight:800;
            cursor:pointer; box-shadow:0 4px 15px rgba(16,185,129,.4); margin-top:20px;
        }
        .btn-save:hover{background:#059669;}

        @media (max-width:1100px){
            .product-grid{grid-template-columns:1fr !important;}
            .size-main-grid{grid-template-columns:1fr 1fr;}
            .size-macro-grid{grid-template-columns:1fr 1fr;}
        }
    </style>
</head>
<body>

<div class="sidebar">
    <?php if(file_exists('sidebar.php')) include 'sidebar.php'; ?>
</div>

<div class="main-content">

    <div class="page-header">
        <div>
            <h1 style="margin:0; font-size:1.8rem; font-weight:800; color:#111827;">تعديل المنتج</h1>
            <p style="margin:5px 0 0; color:#6b7280;"><?php echo htmlspecialchars($product['name']); ?></p>
        </div>
        <a href="manage_products.php" class="btn-back"><i class="fas fa-arrow-right"></i> عودة</a>
    </div>

    <form action="handle_product.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
        <input type="hidden" name="old_image" value="<?php echo htmlspecialchars($product['image']); ?>">

        <div class="product-grid" style="display:grid; grid-template-columns:2.5fr 1fr; gap:30px;">

            <div class="right-col">

                <div class="section-card">
                    <div class="card-header">
                        <i class="fas fa-info-circle" style="font-size:1.5rem; color:var(--primary);"></i>
                        <h3>البيانات الأساسية</h3>
                    </div>

                    <div class="form-group" style="margin-bottom:25px;">
                        <label>اسم الوجبة</label>
                        <input type="text" name="name" class="form-control" style="font-size:1.1rem; font-weight:bold;" value="<?php echo htmlspecialchars($product['name']); ?>" required>
                    </div>

                    <div class="form-group" style="margin-bottom:25px;">
                        <label>التصنيف</label>
                        <div class="categories-grid">
                            <?php foreach($categories as $cat): ?>
                                <input type="radio" name="category_id" value="<?php echo (int)$cat['id']; ?>" id="cat_<?php echo (int)$cat['id']; ?>" class="cat-option" <?php echo ((int)$product['category_id'] === (int)$cat['id']) ? 'checked' : ''; ?> required>
                                <label for="cat_<?php echo (int)$cat['id']; ?>" class="cat-label"><?php echo htmlspecialchars($cat['name']); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:25px;">
                        <div>
                            <label>الباركود (SKU)</label>
                            <input type="text" name="barcode" class="form-control" value="<?php echo htmlspecialchars($product['barcode'] ?? ''); ?>">
                        </div>

                        <div>
                            <label>المخزون المتوفر</label>
                            <div style="display:flex; gap:15px; align-items:center; background:#f9fafb; padding:10px; border-radius:12px; border:1px solid #e5e7eb;">
                                <div class="stock-wrapper" style="flex:1;">
                                    <input type="number" name="stock_qty" id="stockQty" class="form-control input-hero" style="height:50px!important;"
                                           value="<?php echo ((int)$product['stock_qty'] === -1) ? 100 : (int)$product['stock_qty']; ?>"
                                           <?php echo ((int)$product['stock_qty'] === -1) ? 'readonly' : ''; ?>>
                                    <div id="stockOverlay" class="stock-overlay"><i class="fas fa-infinity"></i></div>
                                </div>
                                <div style="text-align:center;">
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="unlimitedStock" onchange="toggleStock()" <?php echo ((int)$product['stock_qty'] === -1) ? 'checked' : ''; ?>>
                                        <span class="slider"></span>
                                    </label>
                                    <div style="font-size:.8rem; font-weight:bold; color:#666; margin-top:5px;">غير محدود</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>وصف الوجبة (تفاصيل كاملة)</label>
                        <textarea name="description" class="form-control" placeholder="اكتب تفاصيل ومكونات الوجبة هنا..."><?php echo htmlspecialchars($product['description'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="section-card">
                    <div class="card-header">
                        <i class="fas fa-weight-hanging" style="font-size:1.5rem; color:var(--primary);"></i>
                        <h3>الأحجام والأسعار</h3>
                    </div>

                    <div id="sizes-container">
                        <?php
                        $idx = 0;
                        if (!empty($existing_sizes)):
                            foreach ($existing_sizes as $size):
                        ?>
                        <div class="size-card" id="size-row-<?php echo $idx; ?>">
                            <button type="button" class="btn-trash" onclick="removeSizeRow(<?php echo $idx; ?>)"><i class="fas fa-trash"></i></button>

                            <div class="size-main-grid">
                                <div>
                                    <span class="field-label">اسم الحجم</span>
                                    <input type="text" name="sizes[<?php echo $idx; ?>][size_name]" class="form-control" value="<?php echo htmlspecialchars($size['size_name']); ?>">
                                </div>
                                <div>
                                    <span class="field-label">الوزن / الكمية</span>
                                    <input type="number" step="0.01" name="sizes[<?php echo $idx; ?>][weight]" class="form-control input-hero" value="<?php echo (float)$size['weight']; ?>" required>
                                </div>
                                <div>
                                    <span class="field-label">الوحدة</span>
                                    <select name="sizes[<?php echo $idx; ?>][unit]" class="form-control" style="height:60px; font-weight:bold;">
                                        <option value="gram"  <?php if($size['unit'] === 'gram')  echo 'selected'; ?>>جرام (g)</option>
                                        <option value="ml"    <?php if($size['unit'] === 'ml')    echo 'selected'; ?>>مل (ml)</option>
                                        <option value="piece" <?php if($size['unit'] === 'piece') echo 'selected'; ?>>قطعة</option>
                                        <option value="kg"    <?php if($size['unit'] === 'kg')    echo 'selected'; ?>>كيلو (kg)</option>
                                        <option value="liter" <?php if($size['unit'] === 'liter') echo 'selected'; ?>>لتر</option>
                                    </select>
                                </div>
                                <div>
                                    <span class="field-label">السعر (ر.س)</span>
                                    <input type="number" step="0.01" name="sizes[<?php echo $idx; ?>][price]" class="form-control input-hero" value="<?php echo (float)$size['price']; ?>" required>
                                </div>
                            </div>

                            <div style="margin-bottom:10px; font-weight:bold; color:#666;">القيم الغذائية:</div>
                            <div class="size-macro-grid">
                                <div class="macro-box macro-cal"><span class="field-label">سعرات 🔥</span><input type="number" name="sizes[<?php echo $idx; ?>][calories]" class="form-control" value="<?php echo (float)($size['calories'] ?? 0); ?>" placeholder="0"></div>
                                <div class="macro-box macro-pro"><span class="field-label">بروتين 🥩</span><input type="number" step="0.1" name="sizes[<?php echo $idx; ?>][protein]" class="form-control" value="<?php echo (float)($size['protein'] ?? 0); ?>" placeholder="0"></div>
                                <div class="macro-box macro-carb"><span class="field-label">كارب 🥔</span><input type="number" step="0.1" name="sizes[<?php echo $idx; ?>][carbs]" class="form-control" value="<?php echo (float)($size['carbs'] ?? 0); ?>" placeholder="0"></div>
                                <div class="macro-box macro-fat"><span class="field-label">دهون 🥑</span><input type="number" step="0.1" name="sizes[<?php echo $idx; ?>][fats]" class="form-control" value="<?php echo (float)($size['fats'] ?? 0); ?>" placeholder="0"></div>
                            </div>
                        </div>
                        <?php
                            $idx++;
                            endforeach;
                        else:
                        ?>
                        <div class="size-card" id="size-row-0">
                            <div class="size-main-grid">
                                <div><span class="field-label">اسم الحجم</span><input type="text" name="sizes[0][size_name]" class="form-control" value="افتراضي"></div>
                                <div><span class="field-label">الوزن</span><input type="number" step="0.01" name="sizes[0][weight]" class="form-control input-hero" value="<?php echo (float)$product['weight']; ?>"></div>
                                <div>
                                    <span class="field-label">الوحدة</span>
                                    <select name="sizes[0][unit]" class="form-control" style="height:60px; font-weight:bold;">
                                        <option value="gram">جرام</option>
                                        <option value="ml">مل</option>
                                        <option value="piece">قطعة</option>
                                        <option value="kg">كيلو</option>
                                        <option value="liter">لتر</option>
                                    </select>
                                </div>
                                <div><span class="field-label">السعر</span><input type="number" step="0.01" name="sizes[0][price]" class="form-control input-hero" value="<?php echo (float)$product['price']; ?>"></div>
                            </div>
                            <div class="size-macro-grid">
                                <div class="macro-box macro-cal"><span class="field-label">سعرات</span><input type="number" name="sizes[0][calories]" class="form-control" value="<?php echo (float)($product['calories'] ?? 0); ?>"></div>
                                <div class="macro-box macro-pro"><span class="field-label">بروتين</span><input type="number" name="sizes[0][protein]" class="form-control" value="<?php echo (float)($product['protein'] ?? 0); ?>"></div>
                                <div class="macro-box macro-carb"><span class="field-label">كارب</span><input type="number" name="sizes[0][carbs]" class="form-control" value="<?php echo (float)($product['carbs'] ?? 0); ?>"></div>
                                <div class="macro-box macro-fat"><span class="field-label">دهون</span><input type="number" name="sizes[0][fats]" class="form-control" value="<?php echo (float)($product['fat'] ?? 0); ?>"></div>
                            </div>
                        </div>
                        <?php $idx = 1; endif; ?>
                    </div>

                    <button type="button" class="btn-add-size" onclick="addSizeRow()">
                        <i class="fas fa-plus-circle"></i> إضافة حجم جديد
                    </button>
                </div>

                <!-- خيارات وإضافات (مصنفة) -->
                <div class="section-card">
                    <div class="card-header">
                        <i class="fas fa-list-ul" style="font-size:1.5rem; color:var(--primary);"></i>
                        <h3>خيارات وإضافات (حسب التصنيف)</h3>
                    </div>

                    <?php if (empty($opt_categories) && empty($opt_by_cat)): ?>
                        <div style="text-align:center; padding:20px; color:red;">لا توجد خيارات في المكتبة.</div>
                    <?php else: ?>

                        <div class="options-toolbar">
                            <div style="display:flex; gap:10px; align-items:center; width:100%;">
                                <input id="optSearch" type="text" class="form-control search-input" placeholder="ابحث عن خيار... (مثال: رز / صوص / عصير)">
                            </div>
                            <div style="display:flex; gap:10px; align-items:center;">
                                <span class="mini-badge mini-muted" id="selCount">0 محدد</span>
                            </div>
                        </div>

                        <div class="options-groups" id="groupsWrap">
                            <?php foreach ($opt_categories as $cat):
                                $cat_id = (int)$cat['id'];
                                $cat_name = $cat['name'] ?? 'بدون تصنيف';
                                $list = $opt_by_cat[$cat_id] ?? [];
                                if (empty($list)) continue;
                            ?>
                            <div class="opt-group" data-group>
                                <div class="opt-group-header" onclick="toggleGroupBody(this)">
                                    <div class="opt-group-title">
                                        <i class="fas fa-folder-open" style="color:var(--primary);"></i>
                                        <span><?php echo htmlspecialchars($cat_name); ?></span>
                                    </div>
                                    <div class="opt-count"><?php echo count($list); ?> خيار</div>
                                </div>

                                <div class="opt-group-body">
                                    <div class="options-list">
                                        <?php foreach ($list as $opt):
                                            $gid = (int)$opt['id'];
                                            $is_linked = isset($linked_map[$gid]);
                                            $price_override = $is_linked ? $linked_map[$gid]['price'] : null;

                                            $tiers = tiersCount($opt['pricing_config'] ?? '');
                                            $unit_txt = unitLabel($opt['unit'] ?? '');
                                        ?>
                                        <div class="option-item <?php echo $is_linked ? 'active' : ''; ?>" id="opt-row-<?php echo $gid; ?>" data-opt-item data-opt-name="<?php echo htmlspecialchars($opt['name']); ?>">
                                            <div class="option-main">
                                                <input
                                                    type="checkbox"
                                                    name="linked_options[<?php echo $gid; ?>][checked]"
                                                    value="1"
                                                    class="option-check"
                                                    onchange="toggleOption(<?php echo $gid; ?>)"
                                                    <?php echo $is_linked ? 'checked' : ''; ?>
                                                >
                                                <div>
                                                    <strong style="font-size:1rem; display:block;"><?php echo htmlspecialchars($opt['name']); ?></strong>
                                                    <small style="color:#666; display:flex; gap:8px; flex-wrap:wrap;">
                                                        <span class="mini-badge"><?php echo htmlspecialchars($unit_txt); ?></span>
                                                        <span class="mini-badge mini-muted"><?php echo (int)$tiers; ?> مستويات</span>
                                                    </small>
                                                </div>
                                            </div>

                                            <div class="option-inputs">
                                                <div>
                                                    <span class="field-label">سعر خاص (اختياري)</span>
                                                    <input
                                                        type="number"
                                                        step="0.5"
                                                        name="linked_options[<?php echo $gid; ?>][price_override]"
                                                        class="form-control"
                                                        style="width:160px; height:40px; padding:5px;"
                                                        value="<?php echo ($price_override === null) ? '' : htmlspecialchars((string)$price_override); ?>"
                                                        placeholder="تلقائي من المكتبة"
                                                    >
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>

                            <!-- في حال وجود خيارات بدون تصنيف (category_id = NULL/0) -->
                            <?php
                            $uncat = $opt_by_cat[0] ?? [];
                            if (!empty($uncat) && (count($opt_categories) === 0 || (count($opt_categories) === 1 && (int)$opt_categories[0]['id'] !== 0))):
                            ?>
                            <div class="opt-group" data-group>
                                <div class="opt-group-header" onclick="toggleGroupBody(this)">
                                    <div class="opt-group-title">
                                        <i class="fas fa-folder-open" style="color:var(--primary);"></i>
                                        <span>بدون تصنيف</span>
                                    </div>
                                    <div class="opt-count"><?php echo count($uncat); ?> خيار</div>
                                </div>
                                <div class="opt-group-body">
                                    <div class="options-list">
                                        <?php foreach ($uncat as $opt):
                                            $gid = (int)$opt['id'];
                                            $is_linked = isset($linked_map[$gid]);
                                            $price_override = $is_linked ? $linked_map[$gid]['price'] : null;

                                            $tiers = tiersCount($opt['pricing_config'] ?? '');
                                            $unit_txt = unitLabel($opt['unit'] ?? '');
                                        ?>
                                        <div class="option-item <?php echo $is_linked ? 'active' : ''; ?>" id="opt-row-<?php echo $gid; ?>" data-opt-item data-opt-name="<?php echo htmlspecialchars($opt['name']); ?>">
                                            <div class="option-main">
                                                <input type="checkbox" name="linked_options[<?php echo $gid; ?>][checked]" value="1" class="option-check" onchange="toggleOption(<?php echo $gid; ?>)" <?php echo $is_linked ? 'checked' : ''; ?>>
                                                <div>
                                                    <strong style="font-size:1rem; display:block;"><?php echo htmlspecialchars($opt['name']); ?></strong>
                                                    <small style="color:#666; display:flex; gap:8px; flex-wrap:wrap;">
                                                        <span class="mini-badge"><?php echo htmlspecialchars($unit_txt); ?></span>
                                                        <span class="mini-badge mini-muted"><?php echo (int)$tiers; ?> مستويات</span>
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="option-inputs">
                                                <div>
                                                    <span class="field-label">سعر خاص (اختياري)</span>
                                                    <input type="number" step="0.5" name="linked_options[<?php echo $gid; ?>][price_override]" class="form-control" style="width:160px; height:40px; padding:5px;"
                                                           value="<?php echo ($price_override === null) ? '' : htmlspecialchars((string)$price_override); ?>" placeholder="تلقائي من المكتبة">
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                    <?php endif; ?>
                </div>

            </div>

            <div class="left-col">
                <div class="section-card">
                    <div class="card-header"><h3>الصورة</h3></div>

                    <label for="imgInput" class="image-upload-box">
                        <?php if (!empty($product['image'])): ?>
                            <img id="previewImg" src="uploads/<?php echo htmlspecialchars($product['image']); ?>" style="display:block;">
                        <?php else: ?>
                            <img id="previewImg" src="#" style="display:none;">
                            <div id="uploadText" style="text-align:center;">
                                <i class="fas fa-cloud-upload-alt fa-3x" style="color:#d1d5db;"></i><br>
                                <span style="font-weight:bold; color:#6b7280;">رفع/تغيير الصورة</span>
                            </div>
                        <?php endif; ?>
                    </label>
                    <input type="file" name="image" id="imgInput" accept="image/*" style="display:none;" onchange="previewImage(this)">
                </div>

                <button type="submit" class="btn-save">
                    <i class="fas fa-save"></i> حفظ التعديلات
                </button>
            </div>

        </div>
    </form>
</div>

<script>
function toggleStock() {
    var stockInput = document.getElementById('stockQty');
    var overlay = document.getElementById('stockOverlay');
    var checkbox = document.getElementById('unlimitedStock');

    if (checkbox.checked) {
        // تعيين -1 للكمية غير المحدودة
        stockInput.value = '-1';
        stockInput.setAttribute('readonly', 'readonly'); // جعل الحقل للقراءة فقط (يُرسل القيمة لكن لا يمكن التعديل)
        overlay.style.display = 'flex';
    } else {
        // إعادة تفعيل الحقل وتعيين قيمة افتراضية
        stockInput.removeAttribute('readonly');
        if (stockInput.value == '-1' || stockInput.value == '') {
            stockInput.value = '100';
        }
        overlay.style.display = 'none';
    }
}
document.addEventListener('DOMContentLoaded', () => {
    toggleStock();
    updateSelectedCount();
    wireSearch();
});

let sizeIndex = <?php echo (int)$idx; ?>;

function addSizeRow() {
    const container = document.getElementById('sizes-container');
    const html = `
    <div class="size-card" id="size-row-${sizeIndex}">
        <button type="button" class="btn-trash" onclick="removeSizeRow(${sizeIndex})"><i class="fas fa-trash"></i></button>

        <div class="size-main-grid">
            <div>
                <span class="field-label">اسم الحجم</span>
                <input type="text" name="sizes[${sizeIndex}][size_name]" class="form-control" placeholder="مثال: وسط">
            </div>
            <div>
                <span class="field-label">الوزن</span>
                <input type="number" step="0.01" name="sizes[${sizeIndex}][weight]" class="form-control input-hero" placeholder="0" required>
            </div>
            <div>
                <span class="field-label">الوحدة</span>
                <select name="sizes[${sizeIndex}][unit]" class="form-control" style="height:60px; font-weight:bold;">
                    <option value="gram">جرام</option>
                    <option value="ml">مل</option>
                    <option value="piece">قطعة</option>
                    <option value="kg">كيلو</option>
                    <option value="liter">لتر</option>
                </select>
            </div>
            <div>
                <span class="field-label">السعر</span>
                <input type="number" step="0.01" name="sizes[${sizeIndex}][price]" class="form-control input-hero" placeholder="0.00" required>
            </div>
        </div>

        <div style="margin-bottom:10px; font-weight:bold; color:#666;">القيم الغذائية:</div>
        <div class="size-macro-grid">
            <div class="macro-box macro-cal"><span class="field-label">سعرات</span><input type="number" name="sizes[${sizeIndex}][calories]" class="form-control" placeholder="0"></div>
            <div class="macro-box macro-pro"><span class="field-label">بروتين</span><input type="number" step="0.1" name="sizes[${sizeIndex}][protein]" class="form-control" placeholder="0"></div>
            <div class="macro-box macro-carb"><span class="field-label">كارب</span><input type="number" step="0.1" name="sizes[${sizeIndex}][carbs]" class="form-control" placeholder="0"></div>
            <div class="macro-box macro-fat"><span class="field-label">دهون</span><input type="number" step="0.1" name="sizes[${sizeIndex}][fats]" class="form-control" placeholder="0"></div>
        </div>
    </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
    sizeIndex++;
}
function removeSizeRow(index) {
    const row = document.getElementById(`size-row-${index}`);
    if (row) row.remove();
}

function previewImage(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('previewImg').src = e.target.result;
            document.getElementById('previewImg').style.display = 'block';
            if (document.getElementById('uploadText')) document.getElementById('uploadText').style.display = 'none';
        }
        reader.readAsDataURL(input.files[0]);
    }
}

// تفعيل/تعطيل الخيار + تحديث العدّاد
function toggleOption(id) {
    var row = document.getElementById('opt-row-' + id);
    var checkbox = row.querySelector('.option-check');
    if (checkbox.checked) row.classList.add('active');
    else row.classList.remove('active');

    updateSelectedCount();
}

function updateSelectedCount(){
    const checked = document.querySelectorAll('.option-check:checked').length;
    const box = document.getElementById('selCount');
    if (box) box.textContent = checked + ' محدد';
}

// بحث سريع داخل كل المجموعات
function wireSearch(){
    const input = document.getElementById('optSearch');
    if(!input) return;

    input.addEventListener('input', function(){
        const q = (this.value || '').trim().toLowerCase();
        const items = document.querySelectorAll('[data-opt-item]');
        items.forEach(it => {
            const name = (it.getAttribute('data-opt-name') || '').toLowerCase();
            it.style.display = (!q || name.includes(q)) ? '' : 'none';
        });
    });
}

// إخفاء/إظهار جسم المجموعة
function toggleGroupBody(headerEl){
    const group = headerEl.closest('.opt-group');
    if(!group) return;
    const body = group.querySelector('.opt-group-body');
    if(!body) return;
    body.style.display = (body.style.display === 'none') ? 'block' : 'none';
}
</script>

</body>
</html>