<?php
// edit_option.php - تعديل خيار (تصنيف + شرائح + auto-serving + قيم غذائية لكل شريحة + رسالة نجاح)
// ===========================================================================================

header('Content-Type: text/html; charset=utf-8');

if (file_exists('auth_admin.php')) require_once 'auth_admin.php';
require_once 'db_connect.php';

// 1) التحقق من الـ ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: manage_options.php");
    exit;
}
$id = (int)$_GET['id'];

$saved = (isset($_GET['saved']) && (int)$_GET['saved'] === 1);

// 2) جلب بيانات الخيار
$stmt = $pdo->prepare("SELECT * FROM global_options WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$option = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$option) die("الخيار غير موجود");

// 3) جلب التصنيفات
$categories = [];
try {
    $checkCat = $pdo->query("SHOW TABLES LIKE 'option_categories'");
    if ($checkCat->rowCount() > 0) {
        $categories = $pdo->query("
            SELECT id, name, is_active, sort_order
            FROM option_categories
            ORDER BY sort_order ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $categories = [];
}

// 4) تحليل JSON
$pricing = json_decode($option['pricing_config'] ?? '', true);
$tiers = $pricing['tiers'] ?? [];
if (!is_array($tiers)) $tiers = [];

// Helper لقراءة قيم التغذية من tier سواء كانت داخل nutrition أو بشكل مباشر
function tierNutVal($tier, $key) {
    if (isset($tier['nutrition']) && is_array($tier['nutrition']) && array_key_exists($key, $tier['nutrition'])) {
        return $tier['nutrition'][$key];
    }
    return $tier[$key] ?? '';
}

// لو مافي tiers نضيف واحدة افتراضية للواجهة
if (count($tiers) === 0) {
    $tiers[] = [
        'threshold' => 200,
        'serving'   => 1,
        'price'     => 0,
        'nutrition' => ['calories'=>0,'protein'=>0,'carbs'=>0,'fat'=>0]
    ];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تعديل: <?php echo htmlspecialchars($option['name']); ?></title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin-unified-style-v2.css">

    <style>
        :root { --primary:#6366f1; --bg:#f3f4f6; --text:#1f2937; --card-bg:#fff; --border:#e5e7eb; }
        body { background-color:var(--bg); font-family:'Tajawal',sans-serif; margin:0; padding:0; direction:rtl; color:var(--text); }

        .page-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; gap:10px; flex-wrap:wrap; }
        .btn-back { background:#e5e7eb; color:#374151; padding:10px 18px; border-radius:50px; text-decoration:none; font-weight:800; display:inline-flex; gap:8px; align-items:center; }
        .btn-back:hover { opacity:.9; }

        .toast {
            display: <?php echo $saved ? 'flex' : 'none'; ?>;
            align-items:center; justify-content:space-between; gap:12px;
            background:#dcfce7; color:#166534; border:1px solid #86efac;
            padding:14px 16px; border-radius:14px; font-weight:900;
            box-shadow:0 8px 20px rgba(0,0,0,.06);
            margin-bottom:16px;
        }
        .toast .x { background:transparent; border:none; cursor:pointer; font-size:18px; color:#166534; }

        .card { background:var(--card-bg); padding:30px; border-radius:20px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom:30px; border:1px solid var(--border); }

        label { font-weight:800; font-size:0.9rem; color:#4b5563; margin-bottom:8px; display:block; }
        .form-control { width:100%; padding:12px; border:2px solid var(--border); border-radius:12px; font-size:1rem; box-sizing:border-box; font-family:'Tajawal',sans-serif; transition:0.2s; }
        .form-control:focus { border-color:var(--primary); outline:none; box-shadow:0 0 0 4px rgba(99,102,241,.12); }

        .form-row { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:16px; }

        .btn-save { background:#10b981; color:white; border:none; padding:16px 22px; border-radius:14px; cursor:pointer; font-weight:900; width:100%; font-size:1.1rem; transition:0.2s; box-shadow:0 6px 18px rgba(16,185,129,0.35); }
        .btn-save:hover { background:#059669; transform:translateY(-1px); }

        /* tiers */
        .tier-row {
            background:#fff; padding:16px; border-radius:14px; border:1px solid var(--border);
            margin-bottom:12px; box-shadow:0 2px 8px rgba(0,0,0,0.03);
        }
        .tier-grid-top{
            display:grid;
            grid-template-columns: 1.2fr 1fr 0.8fr 44px;
            gap:10px;
            align-items:end;
        }
        @media (max-width: 820px) {
            .tier-grid-top { grid-template-columns: 1fr 1fr; }
            .btn-del-row { grid-column: span 2; }
        }

        .tier-label { font-size:0.78rem; color:#6b7280; font-weight:900; display:block; margin-bottom:6px; white-space:nowrap; }
        .btn-del-row { color:#ef4444; background:#fee2e2; width:44px; height:44px; border-radius:12px; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.15s; }
        .btn-del-row:hover { background:#fecaca; }

        .auto-weight-badge {
            display:none;
            background:#dcfce7; color:#166534;
            padding:12px; border-radius:12px; font-size:0.85rem; font-weight:900;
            text-align:center; border:1px dashed #22c55e;
        }

        .tier-nutrition{
            margin-top:12px;
            padding:12px;
            border-radius:14px;
            border:1px dashed #e5e7eb;
            background:#f9fafb;
        }
        .tier-nutrition-title{
            display:flex; align-items:center; justify-content:space-between; gap:10px;
            font-weight:900; color:#374151; margin-bottom:10px;
        }
        .muted{color:#6b7280; font-weight:800; font-size:.85rem;}
        .nut-grid{
            display:grid;
            grid-template-columns: repeat(4, minmax(140px, 1fr));
            gap:10px;
        }
        @media (max-width: 820px){
            .nut-grid{grid-template-columns: 1fr 1fr;}
        }

        input[type="number"] { font-family:'Segoe UI',sans-serif; direction:ltr; text-align:center; font-weight:900; }
    </style>
</head>
<body>

<div class="sidebar">
    <?php if(file_exists('sidebar.php')) include 'sidebar.php'; ?>
</div>

<div class="main-content">

    <div class="page-header">
        <div>
            <h2 style="color:var(--primary); margin:0; font-weight:900;">تعديل الخيار</h2>
            <small style="color:#6b7280; font-weight:900;">تعديل بيانات: <?php echo htmlspecialchars($option['name']); ?></small>
        </div>
        <a href="manage_options.php" class="btn-back"><i class="fas fa-arrow-right"></i> خروج</a>
    </div>

    <div class="toast" id="toastSaved">
        <div style="display:flex; align-items:center; gap:10px;">
            <i class="fas fa-check-circle"></i>
            <span>تم حفظ التعديل بنجاح ✅</span>
        </div>
        <button class="x" type="button" onclick="hideToast()"><i class="fas fa-times"></i></button>
    </div>

    <div class="card">
        <h3 style="margin-top:0; padding-bottom:15px; border-bottom:1px dashed var(--border); color:#374151; font-weight:900;">
            <i class="fas fa-edit" style="color:var(--primary);"></i> البيانات الأساسية
        </h3>

        <form action="handle_options.php" method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?php echo (int)$option['id']; ?>">

            <div class="form-row">
                <div>
                    <label>اسم الخيار <span style="color:red">*</span></label>
                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($option['name']); ?>" required>
                </div>

                <div>
                    <label>التصنيف <span style="color:red">*</span></label>
                    <select name="category_id" class="form-control" required>
                        <option value="">-- اختر التصنيف --</option>
                        <?php
                        $currentCat = (int)($option['category_id'] ?? 0);
                        if (!empty($categories)):
                            foreach($categories as $cat):
                                $catId = (int)$cat['id'];
                                $isActive = (int)$cat['is_active'] === 1;
                                if (!$isActive && $catId !== $currentCat) continue;
                        ?>
                            <option value="<?php echo $catId; ?>" <?php if($catId === $currentCat) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?><?php echo $isActive ? '' : ' (غير نشط)'; ?>
                            </option>
                        <?php endforeach; endif; ?>
                    </select>
                </div>

                <div>
                    <label>وحدة القياس <span style="color:red">*</span></label>
                    <select name="unit" id="mainUnitSelect" class="form-control" style="font-weight:900; color:var(--primary);" onchange="updateUnitLabels()">
                        <?php
                        $units = [
                            'gram'  => ['label' => 'جرام (g)',     'data' => 'وزن'],
                            'ml'    => ['label' => 'مل (ml)',      'data' => 'حجم'],
                            'piece' => ['label' => 'قطعة (Piece)', 'data' => 'عدد'],
                            'kg'    => ['label' => 'كيلو (kg)',    'data' => 'وزن'],
                            'liter' => ['label' => 'لتر (Liter)',  'data' => 'حجم']
                        ];
                        foreach($units as $val => $info):
                        ?>
                            <option value="<?php echo $val; ?>"
                                    data-label="<?php echo $info['data']; ?>"
                                    <?php if(($option['unit'] ?? '') === $val) echo 'selected'; ?>>
                                <?php echo $info['label']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <h4 style="color:var(--primary); margin:18px 0 10px 0; border-bottom:2px solid #f3f4f6; padding-bottom:10px; font-weight:900;">
                <i class="fas fa-sliders-h"></i> شرائح التسعير حسب وزن الوجبة + القيم الغذائية لكل شريحة
            </h4>

            <div style="display:block; background:#fffbe6; padding:16px; border-radius:14px; border:1px solid #fef3c7;">
                <div style="display:grid; grid-template-columns:1.2fr 1fr 0.8fr 44px; gap:10px; margin-bottom:10px; padding:0 8px; font-size:0.82rem; color:#92400e; font-weight:900;">
                    <div>شرط الوجبة (إذا الوزن &lt;)</div>
                    <div><i class="fas fa-utensils"></i> الكمية (<span class="unit-display">جرام</span>)</div>
                    <div>السعر للعميل</div>
                    <div></div>
                </div>

                <div id="tiers-container">
                    <?php foreach($tiers as $tier):
                        $threshold = $tier['threshold'] ?? '';
                        $price = $tier['price'] ?? '';
                        $serving_val = $tier['serving'] ?? 0;

                        $cal = tierNutVal($tier, 'calories');
                        $pro = tierNutVal($tier, 'protein');
                        $car = tierNutVal($tier, 'carbs');
                        $fat = tierNutVal($tier, 'fat');
                    ?>
                        <div class="tier-row">
                            <div class="tier-grid-top">
                                <div>
                                    <span class="tier-label">شرط: وجبة أصغر من (جم)</span>
                                    <input type="number" name="tiers_weight[]" class="form-control" value="<?php echo htmlspecialchars((string)$threshold); ?>" required>
                                </div>

                                <div>
                                    <span class="tier-label" style="color:var(--primary)">الكمية الفعلية للخيار</span>
                                    <input type="number" step="0.01" name="tiers_serving[]" class="form-control serving-input"
                                           value="<?php echo htmlspecialchars((string)$serving_val); ?>"
                                           placeholder="الكمية" style="border-color:var(--primary); background:#eff6ff;">
                                    <div class="auto-weight-badge">
                                        <i class="fas fa-magic"></i> تلقائي (نفس الوجبة)
                                    </div>
                                </div>

                                <div>
                                    <span class="tier-label">السعر (ر.س)</span>
                                    <input type="number" step="0.5" name="tiers_price[]" class="form-control" value="<?php echo htmlspecialchars((string)$price); ?>" required>
                                </div>

                                <button type="button" class="btn-del-row" onclick="this.closest('.tier-row').remove()">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>

                            <div class="tier-nutrition">
                                <div class="tier-nutrition-title">
                                    <div><i class="fas fa-chart-pie" style="color:#0ea5e9;"></i> القيم الغذائية لهذه الشريحة</div>
                                    <div class="muted">تُحسب على كمية الشريحة (Serving) أعلاه</div>
                                </div>

                                <div class="nut-grid">
                                    <div>
                                        <label style="font-size:.82rem;">🔥 سعرات</label>
                                        <input type="number" name="tiers_calories[]" class="form-control" value="<?php echo htmlspecialchars((string)$cal); ?>" placeholder="0">
                                    </div>
                                    <div>
                                        <label style="font-size:.82rem;">🥩 بروتين</label>
                                        <input type="number" step="0.1" name="tiers_protein[]" class="form-control" value="<?php echo htmlspecialchars((string)$pro); ?>" placeholder="0">
                                    </div>
                                    <div>
                                        <label style="font-size:.82rem;">🥔 كارب</label>
                                        <input type="number" step="0.1" name="tiers_carbs[]" class="form-control" value="<?php echo htmlspecialchars((string)$car); ?>" placeholder="0">
                                    </div>
                                    <div>
                                        <label style="font-size:.82rem;">🥑 دهون</label>
                                        <input type="number" step="0.1" name="tiers_fat[]" class="form-control" value="<?php echo htmlspecialchars((string)$fat); ?>" placeholder="0">
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" onclick="addTierRow()"
                        style="background:white; border:2px dashed #d97706; color:#d97706; padding:12px 18px; border-radius:12px; cursor:pointer; font-weight:900; width:100%; margin-top:10px;">
                    <i class="fas fa-plus"></i> إضافة شريحة جديدة
                </button>
            </div>

            <div style="margin-top:14px; background:#f3f4f6; border:1px solid var(--border); padding:14px; border-radius:14px; color:#374151; font-weight:900;">
                ملاحظة: القيم الغذائية “القديمة” العامة (calories/protein...) يمكن إبقاؤها في قاعدة البيانات للرجوع أو للتوافق،
                لكن العرض والاحتساب في صفحات المنتج سيكون الأفضل من <b>قيم كل شريحة</b>.
            </div>

            <button type="submit" class="btn-save" style="margin-top:18px;">
                <i class="fas fa-save"></i> حفظ التعديلات
            </button>
        </form>
    </div>
</div>

<script>
function hideToast(){
    const t = document.getElementById('toastSaved');
    if (t) t.style.display = 'none';
}

function updateUnitLabels() {
    const select = document.getElementById('mainUnitSelect');
    const unit = select.value;
    const selectedOption = select.options[select.selectedIndex];
    const unitText = selectedOption.text;
    const labelType = selectedOption.getAttribute('data-label');

    document.querySelectorAll('.unit-display').forEach(el => el.textContent = unitText);

    const servingInputs = document.querySelectorAll('.serving-input');
    const servingBadges = document.querySelectorAll('.auto-weight-badge');

    if (unit === 'gram') {
        servingInputs.forEach(input => { input.style.display = 'none'; });
        servingBadges.forEach(badge => badge.style.display = 'block');
    } else {
        servingInputs.forEach(input => {
            input.style.display = 'block';
            input.placeholder = `ال${labelType} (${unitText})`;
        });
        servingBadges.forEach(badge => badge.style.display = 'none');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updateUnitLabels();

    // اخفاء رسالة النجاح تلقائياً بعد 3 ثواني
    const toast = document.getElementById('toastSaved');
    if (toast && toast.style.display !== 'none') {
        setTimeout(() => hideToast(), 3000);
    }
});

function addTierRow() {
    const container = document.getElementById('tiers-container');
    const div = document.createElement('div');
    div.className = 'tier-row';

    const unit = document.getElementById('mainUnitSelect').value;
    const inputStyle = (unit === 'gram') ? 'display:none;' : 'display:block;';
    const badgeStyle = (unit === 'gram') ? 'display:block;' : 'display:none;';

    div.innerHTML = `
        <div class="tier-grid-top">
            <div>
                <span class="tier-label">شرط: وجبة أصغر من (جم)</span>
                <input type="number" name="tiers_weight[]" class="form-control" placeholder="200" required>
            </div>

            <div>
                <span class="tier-label" style="color:var(--primary)">الكمية الفعلية للخيار</span>
                <input type="number" step="0.01" name="tiers_serving[]" class="form-control serving-input"
                       placeholder="الكمية" style="border-color:var(--primary); background:#eff6ff; ${inputStyle}">
                <div class="auto-weight-badge" style="${badgeStyle}">
                    <i class="fas fa-magic"></i> تلقائي (نفس الوجبة)
                </div>
            </div>

            <div>
                <span class="tier-label">السعر (ر.س)</span>
                <input type="number" step="0.5" name="tiers_price[]" class="form-control" placeholder="0" required>
            </div>

            <button type="button" class="btn-del-row" onclick="this.closest('.tier-row').remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="tier-nutrition">
            <div class="tier-nutrition-title">
                <div><i class="fas fa-chart-pie" style="color:#0ea5e9;"></i> القيم الغذائية لهذه الشريحة</div>
                <div class="muted">تُحسب على كمية الشريحة (Serving)</div>
            </div>

            <div class="nut-grid">
                <div>
                    <label style="font-size:.82rem;">🔥 سعرات</label>
                    <input type="number" name="tiers_calories[]" class="form-control" placeholder="0">
                </div>
                <div>
                    <label style="font-size:.82rem;">🥩 بروتين</label>
                    <input type="number" step="0.1" name="tiers_protein[]" class="form-control" placeholder="0">
                </div>
                <div>
                    <label style="font-size:.82rem;">🥔 كارب</label>
                    <input type="number" step="0.1" name="tiers_carbs[]" class="form-control" placeholder="0">
                </div>
                <div>
                    <label style="font-size:.82rem;">🥑 دهون</label>
                    <input type="number" step="0.1" name="tiers_fat[]" class="form-control" placeholder="0">
                </div>
            </div>
        </div>
    `;

    container.appendChild(div);
    updateUnitLabels();
}
</script>

</body>
</html>