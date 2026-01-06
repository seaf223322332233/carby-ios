<?php
// add_product.php - إضافة منتج + خيارات مصنفة (مطابقة 100% لـ handle_product.php)
// ==============================================================================

header('Content-Type: text/html; charset=utf-8');

if (file_exists('auth_admin.php')) require_once 'auth_admin.php';
require_once 'db_connect.php';

/* =========================
   1) تصنيفات المنتجات
========================= */
$categories = [];
try {
    $categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("خطأ: " . $e->getMessage());
}

/* =========================
   2) تصنيفات الخيارات + الخيارات
   option_categories + global_options
========================= */
$option_categories = [];
$optionsByCat = [];

try {
    $checkCat = $pdo->query("SHOW TABLES LIKE 'option_categories'");
    $checkOpt = $pdo->query("SHOW TABLES LIKE 'global_options'");

    if ($checkCat->rowCount() > 0) {
        $option_categories = $pdo->query("
            SELECT id, name, sort_order, is_active
            FROM option_categories
            WHERE is_active=1
            ORDER BY sort_order ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($option_categories as $c) {
            $optionsByCat[(int)$c['id']] = [];
        }
    }

    if ($checkOpt->rowCount() > 0) {
        $opts = $pdo->query("
            SELECT id, name, unit, category_id, pricing_config, is_active
            FROM global_options
            WHERE is_active=1
            ORDER BY name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($opts as $o) {
            $cid = (int)($o['category_id'] ?? 0);
            if (!isset($optionsByCat[$cid])) continue;
            $optionsByCat[$cid][] = $o;
        }
    }
} catch (PDOException $e) {
    // تجاهل
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إضافة منتج جديد</title>

    <link rel="stylesheet" href="admin_colors.php">
    <link rel="stylesheet" href="admin-unified-style-v2.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        /* تنسيقات خاصة لصفحة إضافة المنتج */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--admin-surface);
            padding: 20px 30px;
            border-radius: var(--admin-radius-lg);
            box-shadow: var(--admin-shadow-sm);
            margin-bottom: 30px;
            border: 1px solid var(--admin-border);
        }
        
        .btn-back {
            background: var(--admin-bg);
            color: var(--admin-text-main);
            padding: 10px 25px;
            border-radius: var(--admin-radius-sm);
            text-decoration: none;
            font-weight: 700;
            transition: all 0.2s;
            border: 1px solid var(--admin-border);
        }
        
        .btn-back:hover {
            background: var(--restaurant-primary);
            color: #fff;
            transform: translateY(-2px);
        }
        
        .section-card {
            background: var(--admin-surface);
            border-radius: var(--admin-radius-lg);
            padding: 30px;
            box-shadow: var(--admin-shadow);
            margin-bottom: 30px;
            border: 1px solid var(--admin-border);
        }
        
        .card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px dashed var(--admin-border);
        }
        
        .card-header h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 1000;
            color: var(--admin-text-main);
        }
        
        .form-control {
            width: 100%;
            height: 50px;
            padding: 0 15px;
            border-radius: var(--admin-radius-sm);
            border: 2px solid var(--admin-border);
            background: var(--admin-surface);
            font-family: 'Tajawal', sans-serif;
            font-size: 1rem;
            transition: all 0.2s;
            box-sizing: border-box;
        }
        
        .form-control:focus {
            border-color: var(--restaurant-primary);
            box-shadow: 0 0 0 4px rgba(217, 119, 6, 0.1);
            outline: none;
        }
        
        textarea.form-control {
            min-height: 200px !important;
            height: auto;
            padding: 15px;
            line-height: 1.6;
            resize: vertical;
        }
        
        .size-card {
            background: var(--admin-bg);
            border: 2px solid var(--admin-border);
            border-radius: var(--admin-radius-md);
            padding: 25px;
            margin-bottom: 25px;
            position: relative;
        }
        
        .size-main-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--admin-border);
            padding-bottom: 20px;
        }
        
        .size-macro-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
        }
        
        .input-hero {
            height: 60px !important;
            font-size: 1.3rem !important;
            font-weight: 800 !important;
            text-align: center;
            color: var(--restaurant-primary);
            background-color: var(--admin-surface) !important;
            border: 2px solid var(--admin-border);
        }
        
        .field-label {
            display: block;
            font-size: 0.85rem;
            color: var(--admin-text-muted);
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .macro-box input {
            text-align: center;
            font-weight: bold;
            border-bottom-width: 4px;
        }
        
        .btn-trash {
            position: absolute;
            top: 15px;
            left: 15px;
            background: rgba(220, 38, 38, 0.1);
            color: var(--restaurant-accent);
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        
        .btn-trash:hover {
            background: var(--restaurant-accent);
            color: #fff;
        }
        
        .btn-add-size {
            width: 100%;
            padding: 15px;
            background: rgba(217, 119, 6, 0.1);
            color: var(--restaurant-primary);
            border: 2px dashed var(--restaurant-primary);
            border-radius: var(--admin-radius-sm);
            font-weight: 800;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-add-size:hover {
            background: var(--restaurant-primary);
            color: #fff;
        }
        
        .categories-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .cat-option {
            display: none;
        }
        
        .cat-label {
            padding: 12px 25px;
            background: var(--admin-bg);
            border-radius: 50px;
            cursor: pointer;
            font-weight: 700;
            color: var(--admin-text-secondary);
            transition: all 0.3s;
            border: 1px solid var(--admin-border);
        }
        
        .cat-option:checked + .cat-label {
            background: var(--restaurant-gradient);
            color: #fff;
            box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3);
        }
        
        .stock-wrapper {
            position: relative;
        }
        
        .stock-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(22, 163, 74, 0.1);
            border-radius: var(--admin-radius-sm);
            border: 2px solid rgba(22, 163, 74, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: var(--restaurant-success);
            font-weight: bold;
            display: none;
        }
        
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background-color: #ccc;
            transition: 0.4s;
            border-radius: 34px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background-color: #fff;
            transition: 0.4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: var(--restaurant-primary);
        }
        
        input:checked + .slider:before {
            transform: translateX(24px);
        }
        
        /* Options */
        .opt-toolbar {
            display: flex;
            gap: 12px;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .opt-search {
            flex: 1;
        }
        
        .accordion {
            border: 1px solid var(--admin-border);
            border-radius: var(--admin-radius-md);
            overflow: hidden;
            background: var(--admin-surface);
        }
        
        .acc-item {
            border-bottom: 1px solid var(--admin-border);
        }
        
        .acc-head {
            padding: 14px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            font-weight: 900;
        }
        
        .acc-body {
            display: none;
            padding: 12px;
            background: var(--admin-bg);
        }
        
        .option-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--admin-surface);
            padding: 12px;
            margin-bottom: 8px;
            border-radius: var(--admin-radius-sm);
            border: 1px solid var(--admin-border);
            transition: all 0.2s;
            gap: 14px;
        }
        
        .option-item.active {
            border-color: var(--restaurant-primary);
            background: rgba(217, 119, 6, 0.1);
        }
        
        .option-main {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            min-width: 0;
        }
        
        .option-check {
            width: 22px;
            height: 22px;
            accent-color: var(--restaurant-primary);
            cursor: pointer;
        }
        
        .opt-meta {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .tag {
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(217, 119, 6, 0.1);
            color: var(--restaurant-primary);
            font-weight: 900;
            font-size: 0.8rem;
        }
        
        .option-inputs {
            display: flex;
            gap: 10px;
            opacity: 0.35;
            pointer-events: none;
            transition: all 0.2s;
        }
        
        .option-item.active .option-inputs {
            opacity: 1;
            pointer-events: auto;
        }
        
        .small-input {
            height: 40px !important;
            width: 140px;
            padding: 0 10px !important;
            font-weight: 900;
        }
        
        .image-upload-box {
            width: 100%;
            height: 250px;
            border: 3px dashed var(--admin-border);
            border-radius: var(--admin-radius-md);
            background: var(--admin-bg);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        
        .image-upload-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            position: absolute;
            inset: 0;
        }
        
        .btn-save {
            width: 100%;
            padding: 20px;
            background: var(--restaurant-success);
            color: #fff;
            border: none;
            border-radius: var(--admin-radius-md);
            font-size: 1.3rem;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(22, 163, 74, 0.4);
            margin-top: 20px;
            transition: all 0.2s;
        }
        
        .btn-save:hover {
            background: #15803d;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(22, 163, 74, 0.5);
        }
        
        @media (max-width: 1100px) {
            .product-grid {
                grid-template-columns: 1fr !important;
            }
            .size-main-grid {
                grid-template-columns: 1fr 1fr;
            }
            .size-macro-grid {
                grid-template-columns: 1fr 1fr;
            }
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
            <h1 style="margin:0;font-size:1.8rem;font-weight:800;color:#111827;">إضافة منتج جديد</h1>
            <p style="margin:5px 0 0;color:#6b7280;">إدخال وجبة جديدة للنظام</p>
        </div>
        <a href="manage_products.php" class="btn-back"><i class="fas fa-times"></i> إلغاء</a>
    </div>

    <form action="handle_product.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add">

        <div class="product-grid" style="display:grid;grid-template-columns:2.5fr 1fr;gap:30px;">

            <div class="right-col">

                <!-- Basic -->
                <div class="section-card">
                    <div class="card-header">
                        <i class="fas fa-info-circle" style="font-size:1.5rem;color:var(--primary);"></i>
                        <h3>البيانات الأساسية</h3>
                    </div>

                    <div class="form-group" style="margin-bottom:25px;">
                        <label>اسم الوجبة</label>
                        <input type="text" name="name" class="form-control" style="font-size:1.1rem;font-weight:bold;" placeholder="مثال: دجاج مشوي" required>
                    </div>

                    <div class="form-group" style="margin-bottom:25px;">
                        <label>التصنيف</label>
                        <div class="categories-grid">
                            <?php if(count($categories) > 0): ?>
                                <?php foreach($categories as $cat): ?>
                                    <input type="radio" name="category_id" value="<?= (int)$cat['id'] ?>" id="cat_<?= (int)$cat['id'] ?>" class="cat-option" required>
                                    <label for="cat_<?= (int)$cat['id'] ?>" class="cat-label"><?= htmlspecialchars($cat['name']) ?></label>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p style="color:red">يرجى إضافة تصنيفات أولاً</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:25px;">
                        <div>
                            <label>الباركود (SKU)</label>
                            <input type="text" name="barcode" class="form-control" placeholder="اختياري">
                        </div>

                        <div>
                            <label>المخزون المتوفر</label>
                            <div style="display:flex;gap:15px;align-items:center;background:#f9fafb;padding:10px;border-radius:12px;border:1px solid #e5e7eb;">
                                <div class="stock-wrapper" style="flex:1;">
                                    <input type="number" name="stock_qty" id="stockQty" class="form-control input-hero" style="height:50px!important;" value="100">
                                    <div id="stockOverlay" class="stock-overlay"><i class="fas fa-infinity"></i></div>
                                </div>
                                <div style="text-align:center;">
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="unlimitedStock" onchange="toggleStock()">
                                        <span class="slider"></span>
                                    </label>
                                    <div style="font-size:.8rem;font-weight:bold;color:#666;margin-top:5px;">غير محدود</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>وصف الوجبة (تفاصيل كاملة)</label>
                        <textarea name="description" class="form-control" placeholder="اكتب تفاصيل ومكونات الوجبة هنا..."></textarea>
                    </div>
                </div>

                <!-- Sizes -->
                <div class="section-card">
                    <div class="card-header">
                        <i class="fas fa-weight-hanging" style="font-size:1.5rem;color:var(--primary);"></i>
                        <h3>الأحجام والأسعار</h3>
                    </div>

                    <div id="sizes-container">
                        <div class="size-card" id="size-row-0">
                            <div class="size-main-grid">
                                <div>
                                    <span class="field-label">اسم الحجم</span>
                                    <input type="text" name="sizes[0][size_name]" class="form-control" placeholder="مثال: وسط">
                                </div>
                                <div>
                                    <span class="field-label">الوزن / الكمية</span>
                                    <input type="number" step="0.01" name="sizes[0][weight]" class="form-control input-hero" placeholder="0" required>
                                </div>
                                <div>
                                    <span class="field-label">الوحدة</span>
                                    <select name="sizes[0][unit]" class="form-control" style="height:60px;font-weight:bold;">
                                        <option value="gram">جرام (g)</option>
                                        <option value="ml">مل (ml)</option>
                                        <option value="piece">قطعة</option>
                                        <option value="kg">كيلو (kg)</option>
                                    </select>
                                </div>
                                <div>
                                    <span class="field-label">السعر (ر.س)</span>
                                    <input type="number" step="0.01" name="sizes[0][price]" class="form-control input-hero" placeholder="0.00" required>
                                </div>
                            </div>

                            <div style="margin-bottom:10px;font-weight:bold;color:#666;">القيم الغذائية:</div>
                            <div class="size-macro-grid">
                                <div class="macro-box"><span class="field-label">سعرات 🔥</span><input type="number" name="sizes[0][calories]" class="form-control" placeholder="0"></div>
                                <div class="macro-box"><span class="field-label">بروتين 🥩</span><input type="number" step="0.1" name="sizes[0][protein]" class="form-control" placeholder="0"></div>
                                <div class="macro-box"><span class="field-label">كارب 🥔</span><input type="number" step="0.1" name="sizes[0][carbs]" class="form-control" placeholder="0"></div>
                                <div class="macro-box"><span class="field-label">دهون 🥑</span><input type="number" step="0.1" name="sizes[0][fats]" class="form-control" placeholder="0"></div>
                            </div>
                        </div>
                    </div>

                    <button type="button" class="btn-add-size" onclick="addSizeRow()">
                        <i class="fas fa-plus-circle"></i> إضافة حجم جديد
                    </button>
                </div>

                <!-- OPTIONS -->
                <div class="section-card">
                    <div class="card-header">
                        <i class="fas fa-list-ul" style="font-size:1.5rem;color:var(--primary);"></i>
                        <h3>خيارات وإضافات (مصنّفة)</h3>
                    </div>

                    <?php if(empty($option_categories)): ?>
                        <div style="text-align:center;padding:20px;color:#ef4444;font-weight:800;">
                            لا توجد تصنيفات خيارات. اذهب إلى manage_options.php وأضف تصنيفات أولاً.
                        </div>
                    <?php else: ?>

                        <div class="opt-toolbar">
                            <input id="optSearch" type="text" class="form-control opt-search" placeholder="ابحث عن خيار: رز / صوص / عصير ...">
                        </div>

                        <div class="accordion" id="optAccordion">
                            <?php foreach($option_categories as $c):
                                $cid = (int)$c['id'];
                                $opts = $optionsByCat[$cid] ?? [];
                            ?>
                                <div class="acc-item">
                                    <div class="acc-head" onclick="toggleAcc(this)">
                                        <div style="display:flex;gap:10px;align-items:center;">
                                            <i class="fas fa-folder-open" style="color:var(--primary)"></i>
                                            <?= htmlspecialchars($c['name']) ?>
                                        </div>
                                        <div style="color:#6b7280;font-weight:900;">
                                            <?= count($opts) ?> خيار <i class="fas fa-chevron-down" style="margin-right:8px;"></i>
                                        </div>
                                    </div>

                                    <div class="acc-body">
                                        <?php if(empty($opts)): ?>
                                            <div style="color:#9ca3af;font-weight:800;text-align:center;padding:10px;">لا توجد خيارات ضمن هذا التصنيف.</div>
                                        <?php else: ?>
                                            <?php foreach($opts as $opt):
                                                $oid = (int)$opt['id'];
                                                $tiersCount = 0;
                                                $pc = json_decode($opt['pricing_config'] ?? '', true);
                                                if (is_array($pc) && isset($pc['tiers']) && is_array($pc['tiers'])) $tiersCount = count($pc['tiers']);
                                            ?>
                                                <div class="option-item" id="opt-row-<?= $oid ?>"
                                                     data-name="<?= htmlspecialchars(mb_strtolower($opt['name'])) ?>">

                                                    <div class="option-main">
                                                        <input type="checkbox"
                                                               name="linked_options[<?= $oid ?>][checked]"
                                                               value="1"
                                                               class="option-check"
                                                               onchange="toggleOption(<?= $oid ?>)">

                                                        <div style="min-width:0;">
                                                            <strong style="font-size:1rem;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                                                <?= htmlspecialchars($opt['name']); ?>
                                                            </strong>
                                                            <div class="opt-meta">
                                                                <span class="tag"><?= htmlspecialchars($opt['unit']); ?></span>
                                                                <span class="tag" style="background:#ffedd5;color:#9a3412;"><?= $tiersCount ?> شرائح</span>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- IMPORTANT: match handle_product.php -->
                                                    <div class="option-inputs">
                                                        <!-- نرسل qty=1 بشكل ثابت (بدون أي علاقة بالمخزون) -->
                                                        <input type="hidden" name="linked_options[<?= $oid ?>][qty]" value="1">

                                                        <div>
                                                            <span class="field-label">سعر خاص (اختياري)</span>
                                                            <input type="number" step="0.5"
                                                                   name="linked_options[<?= $oid ?>][price]"
                                                                   class="form-control small-input"
                                                                   placeholder="تلقائي">
                                                        </div>
                                                    </div>

                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div style="margin-top:12px;color:#6b7280;font-weight:800;font-size:.9rem;">
                            ✅ عند اختيار أي خيار: سيتم حفظه في <b>product_options</b> (quantity=1 + price اختياري).
                        </div>

                    <?php endif; ?>
                </div>

            </div>

            <!-- Left -->
            <div class="left-col">
                <div class="section-card">
                    <div class="card-header"><h3>الصورة</h3></div>
                    <label for="imgInput" class="image-upload-box">
                        <img id="previewImg" src="#" style="display:none;">
                        <div id="uploadText" style="text-align:center;">
                            <i class="fas fa-cloud-upload-alt fa-3x" style="color:#d1d5db;"></i><br>
                            <span style="font-weight:bold;color:#6b7280;">اضغط لرفع الصورة</span>
                        </div>
                    </label>
                    <input type="file" name="image" id="imgInput" accept="image/*" style="display:none;" onchange="previewImage(this)">
                </div>

                <button type="submit" class="btn-save">
                    <i class="fas fa-check-circle"></i> حفظ المنتج
                </button>
            </div>

        </div>
    </form>
</div>

<script>
/* Stock */
function toggleStock(){
    var stockInput=document.getElementById('stockQty');
    var overlay=document.getElementById('stockOverlay');
    var checkbox=document.getElementById('unlimitedStock');

    if(checkbox.checked){
        stockInput.value='-1';
        overlay.style.display='flex';
    }else{
        if(stockInput.value=='-1') stockInput.value='100';
        overlay.style.display='none';
    }
}

/* Sizes */
let sizeIndex=1;
function addSizeRow(){
    const container=document.getElementById('sizes-container');
    const html=`
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
                <select name="sizes[${sizeIndex}][unit]" class="form-control" style="height:60px;font-weight:bold;">
                    <option value="gram">جرام</option>
                    <option value="ml">مل</option>
                    <option value="piece">قطعة</option>
                    <option value="kg">كيلو</option>
                </select>
            </div>
            <div>
                <span class="field-label">السعر</span>
                <input type="number" step="0.01" name="sizes[${sizeIndex}][price]" class="form-control input-hero" placeholder="0.00" required>
            </div>
        </div>
        <div style="margin-bottom:10px;font-weight:bold;color:#666;">القيم الغذائية:</div>
        <div class="size-macro-grid">
            <div class="macro-box"><span class="field-label">سعرات</span><input type="number" name="sizes[${sizeIndex}][calories]" class="form-control" placeholder="0"></div>
            <div class="macro-box"><span class="field-label">بروتين</span><input type="number" step="0.1" name="sizes[${sizeIndex}][protein]" class="form-control" placeholder="0"></div>
            <div class="macro-box"><span class="field-label">كارب</span><input type="number" step="0.1" name="sizes[${sizeIndex}][carbs]" class="form-control" placeholder="0"></div>
            <div class="macro-box"><span class="field-label">دهون</span><input type="number" step="0.1" name="sizes[${sizeIndex}][fats]" class="form-control" placeholder="0"></div>
        </div>
    </div>`;
    container.insertAdjacentHTML('beforeend', html);
    sizeIndex++;
}
function removeSizeRow(index){
    const row=document.getElementById(`size-row-${index}`);
    if(row) row.remove();
}

/* Image Preview */
function previewImage(input){
    if(input.files && input.files[0]){
        var reader=new FileReader();
        reader.onload=function(e){
            document.getElementById('previewImg').src=e.target.result;
            document.getElementById('previewImg').style.display='block';
            if(document.getElementById('uploadText')) document.getElementById('uploadText').style.display='none';
        }
        reader.readAsDataURL(input.files[0]);
    }
}

/* Options UI */
function toggleOption(id){
    var row=document.getElementById('opt-row-'+id);
    var checkbox=row.querySelector('.option-check');
    if(checkbox.checked) row.classList.add('active');
    else row.classList.remove('active');
}

/* Accordion */
function toggleAcc(el){
    const body=el.parentElement.querySelector('.acc-body');
    const isOpen=(body.style.display==='block');
    body.style.display=isOpen?'none':'block';
}

/* Search */
document.getElementById('optSearch')?.addEventListener('input', function(){
    const q=this.value.trim().toLowerCase();
    document.querySelectorAll('.option-item').forEach(row=>{
        const name=row.getAttribute('data-name')||'';
        row.style.display=(!q || name.includes(q))?'flex':'none';
    });
});

/* Open first category */
document.addEventListener('DOMContentLoaded', ()=>{
    const first=document.querySelector('.acc-item .acc-body');
    if(first) first.style.display='block';
});
</script>

</body>
</html>