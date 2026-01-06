<?php
// edit_package.php - (نسخة معدلة لتقرأ الوزن الرقمي الصحيح)
// =============================================================

header('Content-Type: text/html; charset=utf-8');
require_once 'auth_admin.php'; 
require_once 'db_connect.php'; 

// 1. Check ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: view_packages.php"); exit;
}

$id = (int)$_GET['id'];

// 2. Fetch Package Data
$stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ?");
$stmt->execute([$id]);
$pkg = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pkg) { die("Package not found"); }

// 3. Fetch Current Category Limits
$stmt_limits = $pdo->prepare("
    SELECT l.*, c.name as cat_name 
    FROM package_category_limits l 
    JOIN categories c ON l.category_id = c.id 
    WHERE l.package_id = ?
");
$stmt_limits->execute([$id]);
$current_limits = $stmt_limits->fetchAll(PDO::FETCH_ASSOC);

// 4. Fetch All Categories
$all_categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();

// 4.5. Fetch Current Option Category Limits
$current_option_cat_limits = [];
try {
    $stmt_opt_cat = $pdo->prepare("
        SELECT l.*, c.name as cat_name 
        FROM package_option_category_limits l 
        JOIN option_categories c ON l.option_category_id = c.id 
        WHERE l.package_id = ?
    ");
    $stmt_opt_cat->execute([$id]);
    $current_option_cat_limits = $stmt_opt_cat->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // جدول غير موجود، نتركه فارغاً
}

// 4.6. Fetch All Option Categories
$all_option_categories = [];
try {
    $checkTable = $pdo->query("SHOW TABLES LIKE 'option_categories'");
    if ($checkTable->rowCount() > 0) {
        $all_option_categories = $pdo->query("SELECT * FROM option_categories WHERE is_active=1 ORDER BY sort_order ASC, name ASC")->fetchAll();
    }
} catch (PDOException $e) {
    // جدول غير موجود
}

// 5. Week Days
$week_days = [
    'Saturday'  => 'السبت', 'Sunday'    => 'الأحد', 'Monday'    => 'الاثنين',
    'Tuesday'   => 'الثلاثاء', 'Wednesday' => 'الأربعاء', 'Thursday'  => 'الخميس', 'Friday'    => 'الجمعة'
];

// Process off days for pre-checking
$current_off_days = !empty($pkg['off_days']) ? explode(',', $pkg['off_days']) : [];

// Error Handling from Redirect
$error_msg = isset($_GET['error']) ? $_GET['error'] : '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تعديل الباقة: <?php echo htmlspecialchars($pkg['name']); ?></title>
    <link rel="stylesheet" href="admin-unified-style-v2.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        :root { --primary: #6c5ce7; --primary-dark: #5b4cc4; --bg: #f8f9fa; --surface: #ffffff; }
        body { background: var(--bg); font-family: 'Tajawal', sans-serif; color: #2d3436; }

        /* Modern Form Container */
        .form-card {
            background: var(--surface); border-radius: 20px; padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 1px solid rgba(0,0,0,0.03);
            margin-bottom: 40px; position: relative; overflow: hidden;
        }
        .form-card::before {
            content:''; position: absolute; top:0; left:0; width:100%; height:6px;
            background: linear-gradient(90deg, #f1c40f, #f39c12); /* Yellow/Orange for Edit Mode */
        }

        .section-title {
            font-size: 1.2rem; font-weight: 800; color: #2d3436; margin-bottom: 20px;
            display: flex; align-items: center; gap: 10px;
        }
        .section-title i { color: #f39c12; background: #fffcf5; padding: 10px; border-radius: 12px; }

        /* Inputs */
        .form-control {
            width: 100%; padding: 12px 15px; border: 2px solid #f1f2f6; border-radius: 12px;
            font-family: inherit; font-size: 0.95rem; transition: 0.3s;
        }
        .form-control:focus { border-color: #f39c12; outline: none; background: #fff; }
        label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 0.9rem; color: #636e72; }

        /* Toggle Switch */
        .toggle-switch { position: relative; display: inline-block; width: 50px; height: 26px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #27ae60; }
        input:checked + .slider:before { transform: translateX(24px); }

        /* Weight Toggle Cards */
        .weight-options { display: flex; gap: 15px; }
        .w-option {
            flex: 1; border: 2px solid #f1f2f6; border-radius: 12px; padding: 15px;
            cursor: pointer; transition: 0.3s; text-align: center;
        }
        .w-option:hover { border-color: #d1d8e0; }
        .w-option input { display: none; }
        .w-option.active { border-color: #f39c12; background: #fffcf5; color: #d35400; }
        .w-option i { font-size: 1.5rem; margin-bottom: 8px; display: block; }

        /* Category Builder */
        .cat-builder { background: #f8f9fa; padding: 20px; border-radius: 15px; border: 1px dashed #ced6e0; }
        .cat-input-group { display: flex; gap: 10px; margin-bottom: 15px; }
        .cat-select { flex: 2; padding: 10px; border-radius: 10px; border: 1px solid #ddd; }
        .cat-count { flex: 1; padding: 10px; border-radius: 10px; border: 1px solid #ddd; text-align: center; }
        .btn-add-cat { 
            background: #2d3436; color: white; border: none; padding: 0 20px; 
            border-radius: 10px; cursor: pointer; font-weight: bold; transition: 0.2s;
        }
        .btn-add-cat:hover { background: #000; }

        .added-limits { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; }
        .limit-chip {
            background: white; border: 1px solid #e0e0e0; padding: 8px 15px; border-radius: 50px;
            font-size: 0.9rem; display: flex; align-items: center; gap: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.03);
            animation: popIn 0.3s ease;
        }
        @keyframes popIn { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .limit-chip b { color: #d35400; }
        .limit-chip i { cursor: pointer; color: #e74c3c; font-size: 0.9rem; transition: 0.2s; }
        .limit-chip i:hover { transform: scale(1.2); }

        /* Day Selection */
        .days-grid { display: flex; gap: 10px; flex-wrap: wrap; }
        .day-check { display: none; }
        .day-label {
            background: white; border: 1px solid #e0e0e0; padding: 8px 15px; border-radius: 8px;
            cursor: pointer; transition: 0.2s; font-size: 0.9rem; user-select: none;
        }
        .day-check:checked + .day-label { background: #e74c3c; color: white; border-color: #e74c3c; }

        /* Submit Button */
        .btn-submit {
            background: #f39c12; color: white; width: 100%; padding: 15px; border: none; 
            border-radius: 12px; font-size: 1.1rem; font-weight: bold; cursor: pointer; 
            box-shadow: 0 5px 15px rgba(243, 156, 18, 0.3); transition: 0.3s; margin-top: 20px;
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(243, 156, 18, 0.4); }
    </style>
</head>
<body>

    <div class="sidebar"><?php include 'sidebar.php'; ?></div>

    <div class="main-content">
        <header class="top-bar">
            <div class="user-info">تعديل الباقة</div>
            <a href="view_packages.php" class="logout-link" style="background:#7f8c8d;">عودة للقائمة <i class="fas fa-arrow-left"></i></a>
        </header>

        <main class="content-wrapper">
            <?php if (!empty($error_msg)): ?>
                <div class="alert-message alert-danger">خطأ: <?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['success'])): ?>
                <div class="alert-message alert-success" style="background:#d4edda; color:#155724; padding:15px; border-radius:10px; margin-bottom:20px;">
                    <i class="fas fa-check-circle"></i> تم حفظ التعديلات بنجاح!
                </div>
            <?php endif; ?>

            <div class="form-card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <div class="section-title" style="margin:0;"><i class="fas fa-edit"></i> تعديل بيانات الباقة</div>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span style="font-size:0.9rem; font-weight:bold; color:#555;">حالة الباقة:</span>
                        <label class="toggle-switch">
                            <input type="checkbox" name="is_active" form="editForm" <?php echo $pkg['is_active'] ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
                
                <form action="handle_edit_package.php" method="POST" id="editForm" enctype="multipart/form-data">
                    <input type="hidden" name="id" value="<?php echo $pkg['id']; ?>">
                    
                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div>
                            <label><i class="fas fa-tag" style="color:#6c5ce7; margin-left:5px;"></i> اسم الباقة</label>
                            <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($pkg['name']); ?>" required placeholder="أدخل اسم الباقة">
                        </div>
                        <div>
                            <label><i class="fas fa-money-bill-wave" style="color:#27ae60; margin-left:5px;"></i> السعر بالريال السعودي</label>
                            <input type="number" step="0.01" name="price" class="form-control" value="<?php echo $pkg['price']; ?>" required style="font-weight:bold; color:#27ae60;" placeholder="0.00">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div>
                            <label><i class="fas fa-calendar-alt" style="color:#3498db; margin-left:5px;"></i> مدة الاشتراك (بالأيام)</label>
                            <input type="number" name="duration_days" value="<?php echo $pkg['duration_days']; ?>" class="form-control" required placeholder="مثال: 30 يوم" min="1">
                            <small style="color:#999; font-size:0.85rem; margin-top:5px; display:block;"><i class="fas fa-info-circle"></i> عدد الأيام التي يستمر فيها الاشتراك</small>
                        </div>
                        <div>
                            <label><i class="fas fa-utensils" style="color:#e67e22; margin-left:5px;"></i> عدد الوجبات المسموحة يومياً</label>
                            <select name="meals_per_day" class="form-control" required>
                                <?php for($i=1; $i<=5; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo ($pkg['meals_per_day'] == $i) ? 'selected' : ''; ?>><?php echo $i; ?> وجبة/وجبات</option>
                                <?php endfor; ?>
                            </select>
                            <small style="color:#999; font-size:0.85rem; margin-top:5px; display:block;"><i class="fas fa-info-circle"></i> الحد الأقصى من الوجبات في اليوم الواحد</small>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 25px;">
                        <label><i class="fas fa-weight" style="color:#9b59b6; margin-left:5px;"></i> نظام الوزن المسموح للوجبات</label>
                        <small style="color:#999; font-size:0.85rem; margin-bottom:10px; display:block;"><i class="fas fa-info-circle"></i> اختر ما إذا كانت الباقة تسمح بأي وزن (مفتوح) أو وزن محدد بالجرام</small>
                        <div class="weight-options">
                            <label class="w-option <?php echo ($pkg['allowed_weight'] <= 0) ? 'active' : ''; ?>" onclick="setWeightType('open', this)">
                                <input type="radio" name="weight_type" value="open" <?php echo ($pkg['allowed_weight'] <= 0) ? 'checked' : ''; ?>>
                                <i class="fas fa-balance-scale"></i>
                                <div>وزن مفتوح</div>
                            </label>
                            <label class="w-option <?php echo ($pkg['allowed_weight'] > 0) ? 'active' : ''; ?>" onclick="setWeightType('fixed', this)">
                                <input type="radio" name="weight_type" value="fixed" <?php echo ($pkg['allowed_weight'] > 0) ? 'checked' : ''; ?>>
                                <i class="fas fa-lock"></i>
                                <div>وزن ثابت</div>
                            </label>
                        </div>
                        <div id="fixedWeightInput" style="display:<?php echo ($pkg['allowed_weight'] > 0) ? 'block' : 'none'; ?>; margin-top:10px;">
                            <input type="number" name="fixed_weight_value" class="form-control" value="<?php echo ($pkg['allowed_weight'] > 0) ? $pkg['allowed_weight'] : ''; ?>" placeholder="أدخل الوزن (مثال: 150)" style="width:200px;">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 25px;">
                        <label><i class="fas fa-list-ul" style="color:#16a085; margin-left:5px;"></i> تخصيص عدد الوجبات لكل تصنيف (Category Limits)</label>
                        <small style="color:#999; font-size:0.85rem; margin-bottom:10px; display:block;"><i class="fas fa-info-circle"></i> حدد عدد الوجبات المسموحة لكل تصنيف. إذا تركتها فارغة، الباقة ستكون مفتوحة لجميع التصنيفات</small>
                        <div class="cat-builder">
                            <div class="cat-input-group">
                                <select id="catSelect" class="cat-select">
                                    <option value="">-- اختر التصنيف لإضافته --</option>
                                    <?php foreach($all_categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" id="catCount" class="cat-count" placeholder="العدد" min="1">
                                <button type="button" onclick="addCategoryLimit()" class="btn-add-cat"><i class="fas fa-plus"></i> إضافة</button>
                            </div>
                            
                            <div id="limitsContainer" class="added-limits">
                                <?php if(empty($current_limits)): ?>
                                    <span style="color:#999; font-size:0.9rem;" id="emptyMsg">لم يتم تحديد قيود (الباقة مفتوحة)</span>
                                <?php else: ?>
                                    <?php foreach($current_limits as $lim): ?>
                                        <div class="limit-chip" id="limit_row_<?php echo $lim['category_id']; ?>">
                                            <span><?php echo htmlspecialchars($lim['cat_name']); ?>: <b><?php echo $lim['allowed_count']; ?> وجبات</b></span>
                                            <input type="hidden" name="cat_limits[<?php echo $lim['category_id']; ?>]" value="<?php echo $lim['allowed_count']; ?>">
                                            <i class="fas fa-times-circle" onclick="removeLimit(<?php echo $lim['category_id']; ?>)"></i>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 25px;">
                        <label><i class="fas fa-cubes" style="color:#9b59b6; margin-left:5px;"></i> إدارة تصنيفات الخيارات (Option Categories)</label>
                        <small style="color:#999; font-size:0.85rem; margin-bottom:10px; display:block;"><i class="fas fa-info-circle"></i> حدد تصنيفات الخيارات التي تظهر للباقة وعدد الخيارات التي يمكن للعميل اختيارها من كل تصنيف مع كل وجبة</small>
                        <div class="cat-builder" style="background:#f8f9fa; padding:20px; border-radius:15px; border:1px dashed #ced6e0;">
                            <div class="cat-input-group">
                                <select id="optCatSelect" class="cat-select">
                                    <option value="">-- اختر تصنيف الخيارات --</option>
                                    <?php foreach($all_option_categories as $optCat): ?>
                                        <option value="<?php echo $optCat['id']; ?>"><?php echo htmlspecialchars($optCat['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" id="optCatCount" class="cat-count" placeholder="عدد الخيارات" min="1" value="1">
                                <button type="button" onclick="addOptionCategoryLimit()" class="btn-add-cat"><i class="fas fa-plus"></i> إضافة</button>
                            </div>
                            
                            <div id="optCatLimitsContainer" class="added-limits">
                                <?php if(empty($current_option_cat_limits)): ?>
                                    <span style="color:#999; font-size:0.9rem;" id="optCatEmptyMsg">لم يتم تحديد تصنيفات خيارات (جميع الخيارات متاحة)</span>
                                <?php else: ?>
                                    <?php foreach($current_option_cat_limits as $optLim): ?>
                                        <div class="limit-chip" id="optcat_limit_row_<?php echo $optLim['option_category_id']; ?>">
                                            <span><?php echo htmlspecialchars($optLim['cat_name']); ?>: <b><?php echo $optLim['allowed_count']; ?> خيار</b></span>
                                            <input type="hidden" name="optcat_limits[<?php echo $optLim['option_category_id']; ?>]" value="<?php echo $optLim['allowed_count']; ?>">
                                            <i class="fas fa-times-circle" onclick="removeOptionCatLimit(<?php echo $optLim['option_category_id']; ?>)"></i>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 25px;">
                        <label><i class="fas fa-calendar-times" style="color:#e74c3c; margin-left:5px;"></i> أيام التوقف (إجازة أسبوعية)</label>
                        <small style="color:#999; font-size:0.85rem; margin-bottom:10px; display:block;"><i class="fas fa-info-circle"></i> اختر الأيام التي لن يتم فيها التوصيل (مثل: عطلة نهاية الأسبوع)</small>
                        <div class="days-grid">
                            <?php foreach($week_days as $en => $ar): ?>
                                <label>
                                    <input type="checkbox" name="off_days[]" value="<?php echo $en; ?>" class="day-check" <?php echo in_array($en, $current_off_days) ? 'checked' : ''; ?>>
                                    <span class="day-label"><?php echo $ar; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div style="margin-bottom: 20px; display:flex; gap:20px; align-items:flex-start;">
                        <div style="flex:1;">
                            <label><i class="fas fa-align-right" style="color:#34495e; margin-left:5px;"></i> الوصف التسويقي للباقة</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="اكتب وصفاً جذاباً للباقة يوضح مميزاتها..."><?php echo htmlspecialchars($pkg['description']); ?></textarea>
                            <small style="color:#999; font-size:0.85rem; margin-top:5px; display:block;"><i class="fas fa-info-circle"></i> هذا الوصف سيظهر للعملاء عند تصفح الباقات</small>
                        </div>
                        <div style="width:150px; text-align:center;">
                            <label><i class="fas fa-image" style="color:#e67e22; margin-left:5px;"></i> صورة الباقة</label>
                            <img src="<?php echo !empty($pkg['image_url']) ? $pkg['image_url'] : 'uploads/packages/default.png'; ?>" style="width:120px; height:120px; border-radius:12px; object-fit:cover; margin-bottom:8px; border:2px solid #ddd; box-shadow:0 2px 8px rgba(0,0,0,0.1);">
                            <input type="file" name="package_image" accept="image/*" style="font-size:0.75rem; width:100%; padding:6px; border-radius:8px; border:1px solid #ddd;">
                            <small style="color:#999; font-size:0.75rem; margin-top:5px; display:block;">الصورة الحالية</small>
                        </div>
                    </div>

                    <button type="submit" name="update_package" class="btn-submit">
                        <i class="fas fa-save"></i> حفظ التعديلات
                    </button>
                </form>
            </div>
        </main>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        function setWeightType(type, element) {
            $('.w-option').removeClass('active');
            $(element).addClass('active');
            if (type === 'fixed') {
                $('#fixedWeightInput').slideDown();
            } else {
                $('#fixedWeightInput').slideUp();
            }
        }

        function addCategoryLimit() {
            let catId = $('#catSelect').val();
            let catName = $('#catSelect option:selected').text();
            let count = $('#catCount').val();

            if (!catId || !count || count <= 0) {
                alert('الرجاء اختيار تصنيف وتحديد عدد صحيح.');
                return;
            }

            if ($(`#limit_row_${catId}`).length > 0) {
                $(`#limit_row_${catId}`).remove();
            }

            $('#emptyMsg').hide();

            let html = `
                <div class="limit-chip" id="limit_row_${catId}">
                    <span>${catName}: <b>${count} وجبات</b></span>
                    <input type="hidden" name="cat_limits[${catId}]" value="${count}">
                    <i class="fas fa-times-circle" onclick="removeLimit(${catId})" style="color:#e74c3c; cursor:pointer;"></i>
                </div>
            `;
            $('#limitsContainer').append(html);
            $('#catSelect').val('');
            $('#catCount').val('');
        }

        function removeLimit(id) {
            $(`#limit_row_${id}`).remove();
            if ($('#limitsContainer').children().length === 0) {
                $('#limitsContainer').html('<span style="color:#999; font-size:0.9rem;" id="emptyMsg">لم يتم تحديد قيود (الباقة مفتوحة)</span>');
            }
        }

        function addOptionCategoryLimit() {
            let catId = $('#optCatSelect').val();
            let catName = $('#optCatSelect option:selected').text();
            let count = $('#optCatCount').val();

            if (!catId || !count || count <= 0) {
                alert('الرجاء اختيار تصنيف خيارات وتحديد عدد صحيح.');
                return;
            }

            if ($(`#optcat_limit_row_${catId}`).length > 0) {
                $(`#optcat_limit_row_${catId}`).remove();
            }

            $('#optCatEmptyMsg').hide();

            let html = `
                <div class="limit-chip" id="optcat_limit_row_${catId}">
                    <span>${catName}: <b>${count} خيار</b></span>
                    <input type="hidden" name="optcat_limits[${catId}]" value="${count}">
                    <i class="fas fa-times-circle" onclick="removeOptionCatLimit(${catId})" style="color:#e74c3c; cursor:pointer;"></i>
                </div>
            `;
            $('#optCatLimitsContainer').append(html);
            $('#optCatSelect').val('');
            $('#optCatCount').val('1');
        }

        function removeOptionCatLimit(id) {
            $(`#optcat_limit_row_${id}`).remove();
            if ($('#optCatLimitsContainer').children().length === 0) {
                $('#optCatLimitsContainer').html('<span style="color:#999; font-size:0.9rem;" id="optCatEmptyMsg">لم يتم تحديد تصنيفات خيارات (جميع الخيارات متاحة)</span>');
            }
        }
    </script>
</body>
</html>