<?php
// view_packages.php - (تم الإصلاح: حفظ الوزن في العمود الصحيح allowed_weight)
// =============================================================

header('Content-Type: text/html; charset=utf-8');
require_once 'auth_admin.php'; 
require_once 'db_connect.php'; 

// Fetch Categories
$all_categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();

// Fetch Option Categories
$all_option_categories = [];
try {
    $checkTable = $pdo->query("SHOW TABLES LIKE 'option_categories'");
    if ($checkTable->rowCount() > 0) {
        $all_option_categories = $pdo->query("SELECT * FROM option_categories WHERE is_active=1 ORDER BY sort_order ASC, name ASC")->fetchAll();
    }
} catch (PDOException $e) {
    // جدول غير موجود
}

// Week Days
$week_days = [
    'Saturday'  => 'السبت', 'Sunday'    => 'الأحد', 'Monday'    => 'الاثنين',
    'Tuesday'   => 'الثلاثاء', 'Wednesday' => 'الأربعاء', 'Thursday'  => 'الخميس', 'Friday'    => 'الجمعة'
];

// --- 1. Add New Package Logic ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_package'])) {
    
    try {
        $pdo->beginTransaction();

        // Basic Data
        $name = trim($_POST['name']);
        $price = (float)$_POST['price'];
        $meals_per_day = (int)$_POST['meals_per_day'];
        $description = trim($_POST['description']);
        $duration_days = (int)$_POST['duration_days'];
        
        // Weight Constraint Logic (تصحيح هنا)
        $allowed_weight = 0; // القيمة الافتراضية للوزن المفتوح
        $weight_label = 'مفتوح';

        if (isset($_POST['weight_type']) && $_POST['weight_type'] == 'fixed') {
            // إذا كان وزناً ثابتاً، نحفظ الرقم في allowed_weight
            $allowed_weight = (float)$_POST['fixed_weight_value'];
            $weight_label = $allowed_weight . 'g';
        }

        // Off Days
        $off_days_str = "";
        if (isset($_POST['off_days']) && is_array($_POST['off_days'])) {
            $off_days_str = implode(',', $_POST['off_days']);
        }

        // Image Upload Logic
        $image_url = 'uploads/packages/default.png'; 
        if (isset($_FILES['package_image']) && $_FILES['package_image']['error'] == 0) {
            $target_dir = "uploads/packages/";
            if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }
            
            $file_ext = strtolower(pathinfo($_FILES['package_image']['name'], PATHINFO_EXTENSION));
            $filename = time() . "_" . uniqid() . "." . $file_ext;
            
            if (move_uploaded_file($_FILES['package_image']['tmp_name'], $target_dir . $filename)) {
                $image_url = $target_dir . $filename;
            }
        }
        
        // Insert Package (تم إضافة allowed_weight)
        // ملاحظة: تأكد أن عمود allowed_weight موجود في قاعدة البيانات
        $sql = "INSERT INTO packages (name, description, price, meals_per_day, allowed_weight, fixed_weight_label, duration_days, off_days, image_url, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $description, $price, $meals_per_day, $allowed_weight, $weight_label, $duration_days, $off_days_str, $image_url]);
        $package_id = $pdo->lastInsertId();

        // Insert Category Limits
        if (isset($_POST['cat_limits']) && is_array($_POST['cat_limits'])) {
            $stmt_limit = $pdo->prepare("INSERT INTO package_category_limits (package_id, category_id, allowed_count) VALUES (?, ?, ?)");
            foreach ($_POST['cat_limits'] as $cat_id => $limit) {
                $limit = (int)$limit;
                if ($limit > 0) { 
                    $stmt_limit->execute([$package_id, $cat_id, $limit]);
                }
            }
        }

        // Insert Option Category Limits
        try {
            $checkTable = $pdo->query("SHOW TABLES LIKE 'package_option_category_limits'");
            if ($checkTable->rowCount() > 0) {
                if (isset($_POST['optcat_limits']) && is_array($_POST['optcat_limits'])) {
                    $stmt_optcat = $pdo->prepare("INSERT INTO package_option_category_limits (package_id, option_category_id, allowed_count) VALUES (?, ?, ?)");
                    foreach ($_POST['optcat_limits'] as $optcat_id => $limit) {
                        $limit = (int)$limit;
                        if ($limit > 0) { 
                            $stmt_optcat->execute([$package_id, $optcat_id, $limit]);
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            // جدول غير موجود، نتجاهل
        }

        $pdo->commit();
        header("Location: view_packages.php?success=add");
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        die("<h3 style='color:red; text-align:center; margin-top:50px;'>System Error: " . $e->getMessage() . "</h3>");
    }
}

// --- 2. Delete Package ---
if (isset($_GET['delete_id'])) {
    $pid = (int)$_GET['delete_id'];
    $pdo->prepare("DELETE FROM package_category_limits WHERE package_id=?")->execute([$pid]);
    try {
        $checkTable = $pdo->query("SHOW TABLES LIKE 'package_option_category_limits'");
        if ($checkTable->rowCount() > 0) {
            $pdo->prepare("DELETE FROM package_option_category_limits WHERE package_id=?")->execute([$pid]);
        }
    } catch (PDOException $e) {
        // جدول غير موجود
    }
    $pdo->prepare("DELETE FROM packages WHERE id=?")->execute([$pid]);
    header("Location: view_packages.php?success=delete"); exit;
}

// --- 3. Fetch Packages ---
$packages = $pdo->query("SELECT * FROM packages ORDER BY id DESC")->fetchAll();

// Helper functions
function get_package_limits_html($pdo, $pkg_id) {
    $stmt = $pdo->prepare("SELECT c.name, l.allowed_count FROM package_category_limits l JOIN categories c ON l.category_id = c.id WHERE l.package_id = ?");
    $stmt->execute([$pkg_id]);
    $limits = $stmt->fetchAll();
    
    if (empty($limits)) return '<span class="limit-tag open">🌐 مفتوح للكل</span>';
    
    $html = '';
    foreach ($limits as $l) {
        $html .= '<span class="limit-tag">' . htmlspecialchars($l['name']) . ': <b>' . $l['allowed_count'] . '</b></span> ';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إدارة الباقات الذكية</title>
    <link rel="stylesheet" href="admin_colors.php">
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
            background: linear-gradient(90deg, var(--primary), #a29bfe);
        }

        .section-title {
            font-size: 1.2rem; font-weight: 800; color: #2d3436; margin-bottom: 20px;
            display: flex; align-items: center; gap: 10px;
        }
        .section-title i { color: var(--primary); background: #f0f0ff; padding: 10px; border-radius: 12px; }

        /* Inputs */
        .form-control {
            width: 100%; padding: 12px 15px; border: 2px solid #f1f2f6; border-radius: 12px;
            font-family: inherit; font-size: 0.95rem; transition: 0.3s;
        }
        .form-control:focus { border-color: var(--primary); outline: none; background: #fff; }
        label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 0.9rem; color: #636e72; }

        /* Weight Toggle Cards */
        .weight-options { display: flex; gap: 15px; }
        .w-option {
            flex: 1; border: 2px solid #f1f2f6; border-radius: 12px; padding: 15px;
            cursor: pointer; transition: 0.3s; text-align: center;
        }
        .w-option:hover { border-color: #d1d8e0; }
        .w-option input { display: none; }
        .w-option.active { border-color: var(--primary); background: #f0f0ff; color: var(--primary); }
        .w-option i { font-size: 1.5rem; margin-bottom: 8px; display: block; }

        /* Category Builder */
        .cat-builder { background: #f8f9fa; padding: 20px; border-radius: 15px; border: 1px dashed #ced6e0; }
        .cat-input-group { display: flex; gap: 10px; margin-bottom: 15px; }
        .cat-select { flex: 2; padding: 10px; border-radius: 10px; border: 1px solid #ddd; }
        .cat-count { flex: 1; padding: 10px; border-radius: 10px; border: 1px solid #ddd; text-align: center; }
        .btn-add-cat { 
            background: var(--primary); color: white; border: none; padding: 0 20px; 
            border-radius: 10px; cursor: pointer; font-weight: bold; transition: 0.2s;
        }
        .btn-add-cat:hover { background: var(--primary-dark); }

        .added-limits { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; }
        .limit-chip {
            background: white; border: 1px solid #e0e0e0; padding: 8px 15px; border-radius: 50px;
            font-size: 0.9rem; display: flex; align-items: center; gap: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.03);
            animation: popIn 0.3s ease;
        }
        @keyframes popIn { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .limit-chip b { color: var(--primary); }
        .limit-chip i { cursor: pointer; color: #e74c3c; font-size: 0.9rem; transition: 0.2s; }
        .limit-chip i:hover { transform: scale(1.2); }

        /* Day Selection */
        .days-grid { display: flex; gap: 10px; flex-wrap: wrap; }
        .day-check { display: none; }
        .day-label {
            background: white; border: 1px solid #e0e0e0; padding: 8px 15px; border-radius: 8px;
            cursor: pointer; transition: 0.2s; font-size: 0.9rem; user-select: none;
        }
        .day-check:checked + .day-label { background: #ff7675; color: white; border-color: #ff7675; }

        /* Submit Button */
        .btn-submit {
            background: linear-gradient(135deg, var(--primary), #a29bfe); color: white; width: 100%;
            padding: 15px; border: none; border-radius: 12px; font-size: 1.1rem; font-weight: bold;
            cursor: pointer; box-shadow: 0 5px 15px rgba(108, 92, 231, 0.3); transition: 0.3s;
            margin-top: 20px;
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(108, 92, 231, 0.4); }

        /* Table Badges */
        .limit-tag { background: #eef2f7; padding: 3px 10px; border-radius: 6px; font-size: 0.8rem; margin: 2px; display: inline-block; color: #555; }
        .limit-tag.open { background: #e3fcf3; color: #219653; }
        
        .meal-image { width: 50px; height: 50px; object-fit: cover; border-radius: 10px; }
        .badge { padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: bold; }
        .badge-fixed { background: #ffeaa7; color: #d35400; }
        .badge-open { background: #dfe6e9; color: #636e72; }
        
        .action-btn { 
            padding: 6px 12px; border-radius: 8px; color: white; text-decoration: none; 
            font-size: 0.85rem; margin-left: 5px; display: inline-block; transition: 0.2s;
        }
        .btn-edit { background: #3498db; }
        .btn-edit:hover { background: #2980b9; }
        .btn-delete { background: #e74c3c; }
        .btn-delete:hover { background: #c0392b; }
    </style>
</head>
<body>

    <div class="sidebar"><?php include 'sidebar.php'; ?></div>

    <div class="main-content">
        <header class="top-bar">
            <div class="user-info">📦 إدارة الباقات والاشتراكات</div>
            <a href="logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> خروج</a>
        </header>

        <main class="content-wrapper">
            <?php if (isset($_GET['success'])): ?>
                <div class="alert-message alert-success" style="margin-bottom:20px; padding:15px; background:#d4edda; color:#155724; border-radius:10px; display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-check-circle" style="font-size:1.2rem;"></i> تم تنفيذ العملية بنجاح!
                </div>
            <?php endif; ?>

            <div class="form-card">
                <div class="section-title"><i class="fas fa-magic"></i> تصميم باقة جديدة</div>
                
                <form method="POST" enctype="multipart/form-data">
                    
                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div>
                            <label>اسم الباقة المميز</label>
                            <input type="text" name="name" class="form-control" required placeholder="مثال: باقة التنشيف الاحترافية">
                        </div>
                        <div>
                            <label>السعر (ر.س)</label>
                            <input type="number" step="0.01" name="price" class="form-control" required style="font-weight:bold; color:#27ae60;">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div>
                            <label>مدة الاشتراك (يوم)</label>
                            <input type="number" name="duration_days" value="28" class="form-control" required>
                        </div>
                        <div>
                            <label>عدد الوجبات يومياً</label>
                            <select name="meals_per_day" class="form-control" required>
                                <option value="1">وجبة واحدة</option>
                                <option value="2">وجبتين</option>
                                <option value="3">3 وجبات</option>
                                <option value="4">4 وجبات</option>
                                <option value="5">5 وجبات</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 25px;">
                        <label>نظام الوزن (Allowed Weight)</label>
                        <div class="weight-options">
                            <label class="w-option active" onclick="setWeightType('open', this)">
                                <input type="radio" name="weight_type" value="open" checked>
                                <i class="fas fa-balance-scale"></i>
                                <div>وزن مفتوح</div>
                                <small style="color:#888">العميل يختار الوزن بنفسه</small>
                            </label>
                            <label class="w-option" onclick="setWeightType('fixed', this)">
                                <input type="radio" name="weight_type" value="fixed">
                                <i class="fas fa-lock"></i>
                                <div>وزن ثابت</div>
                                <small style="color:#888">إجبار العميل على وزن محدد</small>
                            </label>
                        </div>
                        <div id="fixedWeightInput" style="display:none; margin-top:10px; animation:fadeIn 0.3s;">
                            <input type="number" name="fixed_weight_value" class="form-control" placeholder="أدخل الوزن (مثال: 150)" style="width:200px;">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 25px;">
                        <label>تخصيص الوجبات (Category Limits)</label>
                        <div class="cat-builder">
                            <div class="cat-input-group">
                                <select id="catSelect" class="cat-select">
                                    <option value="">-- اختر التصنيف --</option>
                                    <?php foreach($all_categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" id="catCount" class="cat-count" placeholder="العدد" min="1">
                                <button type="button" onclick="addCategoryLimit()" class="btn-add-cat"><i class="fas fa-plus"></i> إضافة</button>
                            </div>
                            
                            <div id="limitsContainer" class="added-limits">
                                <span style="color:#999; font-size:0.9rem;" id="emptyMsg">لم يتم تحديد قيود (الباقة مفتوحة لكل التصنيفات)</span>
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
                                <span style="color:#999; font-size:0.9rem;" id="optCatEmptyMsg">لم يتم تحديد تصنيفات خيارات (جميع الخيارات متاحة)</span>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 25px;">
                        <label>أيام التوقف (إجازة أسبوعية)</label>
                        <div class="days-grid">
                            <?php foreach($week_days as $en => $ar): ?>
                                <label>
                                    <input type="checkbox" name="off_days[]" value="<?php echo $en; ?>" class="day-check">
                                    <span class="day-label"><?php echo $ar; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label>صورة الباقة</label>
                        <input type="file" name="package_image" class="form-control" accept="image/*">
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label>وصف تسويقي</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="اكتب وصفاً جذاباً للباقة..."></textarea>
                    </div>

                    <button type="submit" name="add_package" class="btn-submit">
                        <i class="fas fa-check-circle"></i> حفظ وإطلاق الباقة
                    </button>
                </form>
            </div>

            <div class="form-card">
                <div class="section-title"><i class="fas fa-list-alt"></i> الباقات النشطة</div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>الصورة</th><th>الباقة</th><th>الوزن (Allowed)</th><th>توزيع الوجبات</th><th>السعر</th><th>تحكم</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($packages as $pkg): ?>
                        <tr>
                            <td><img src="<?php echo !empty($pkg['image_url']) ? htmlspecialchars($pkg['image_url']) : 'uploads/packages/default.png'; ?>" class="meal-image"></td>
                            <td>
                                <strong><?php echo htmlspecialchars($pkg['name']); ?></strong><br>
                                <span style="font-size:0.8rem; color:#777;"><?php echo $pkg['duration_days']; ?> يوم</span>
                            </td>
                            <td>
                                <?php if(isset($pkg['allowed_weight']) && $pkg['allowed_weight'] > 0): ?>
                                    <span class="badge badge-fixed"><i class="fas fa-lock"></i> <?php echo $pkg['allowed_weight']; ?>g</span>
                                <?php else: ?>
                                    <span class="badge badge-open">مفتوح</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $pkg['meals_per_day']; ?> وجبات/يوم</td>
                            <td><strong style="color:#27ae60; font-size:1.1rem;"><?php echo number_format($pkg['price'], 2); ?></strong></td>
                            <td class="action-buttons">
                                <a href="edit_package.php?id=<?php echo $pkg['id']; ?>" class="action-btn btn-edit"><i class="fas fa-edit"></i></a>
                                <a href="view_packages.php?delete_id=<?php echo $pkg['id']; ?>" class="action-btn btn-delete" onclick="return confirm('تأكيد الحذف؟')"><i class="fas fa-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
                alert('هذا التصنيف مضاف مسبقاً، احذفه لإضافته مجدداً.');
                return;
            }

            $('#emptyMsg').hide();

            let html = `
                <div class="limit-chip" id="limit_row_${catId}">
                    <span>${catName}: <b>${count} وجبات</b></span>
                    <input type="hidden" name="cat_limits[${catId}]" value="${count}">
                    <i class="fas fa-times-circle" onclick="removeLimit(${catId})"></i>
                </div>
            `;
            $('#limitsContainer').append(html);
            
            $('#catSelect').val('');
            $('#catCount').val('');
        }

        function removeLimit(id) {
            $(`#limit_row_${id}`).remove();
            if ($('#limitsContainer').children().length <= 1) { 
                $('#emptyMsg').show();
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
                alert('هذا التصنيف مضاف مسبقاً، احذفه لإضافته مجدداً.');
                return;
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
            if ($('#optCatLimitsContainer').children().length === 0 || $('#optCatLimitsContainer').children().length === 1) { 
                $('#optCatEmptyMsg').show();
            }
        }
    </script>
</body>
</html>