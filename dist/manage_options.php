<?php
// manage_options.php - (تصنيفات + خيارات + Tiered Pricing + Nutrition per Tier)
// ======================================================================================

header('Content-Type: text/html; charset=utf-8');

if (file_exists('auth_admin.php')) require_once 'auth_admin.php';
require_once 'db_connect.php';

// =========================
// جلب التصنيفات + الخيارات
// =========================
$categories = [];
$options = [];

try {
    // 1) التصنيفات
    $checkCat = $pdo->query("SHOW TABLES LIKE 'option_categories'");
    if ($checkCat->rowCount() > 0) {
        $categories = $pdo->query("
            SELECT id, name, sort_order, is_active
            FROM option_categories
            ORDER BY sort_order ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    // 2) الخيارات
    $checkOpt = $pdo->query("SHOW TABLES LIKE 'global_options'");
    if ($checkOpt->rowCount() > 0) {
        $options = $pdo->query("
            SELECT o.*, c.name AS category_name, c.sort_order AS cat_sort
            FROM global_options o
            LEFT JOIN option_categories c ON c.id = o.category_id
            ORDER BY (c.sort_order IS NULL), c.sort_order ASC, o.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    die("خطأ في قاعدة البيانات: " . $e->getMessage());
}

// تصنيفات نشطة فقط لاستخدامها في إضافة خيار
$activeCategories = array_values(array_filter($categories, fn($c) => (int)$c['is_active'] === 1));

function countTiersSafe($pricing_config): int {
    $pricing = json_decode($pricing_config ?? '', true);
    if (!is_array($pricing)) return 0;
    $tiers = $pricing['tiers'] ?? [];
    return is_array($tiers) ? count($tiers) : 0;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الخيارات والتصنيفات</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin-unified-style-v2.css">

    <style>
        :root { --primary:#6366f1; --bg:#f3f4f6; --text:#1f2937; --card-bg:#fff; --border:#e5e7eb; }
        body { background:var(--bg); font-family:'Tajawal',sans-serif; margin:0; color:var(--text); direction:rtl; }

        .page-header{display:flex; justify-content:space-between; align-items:center; margin-bottom:30px; gap:12px; flex-wrap:wrap;}
        .page-header h2{margin:0; font-weight:800; color:var(--primary);}
        .btn-back{background:#e5e7eb; color:#374151; padding:10px 20px; border-radius:50px; text-decoration:none; font-weight:bold; display:inline-flex; gap:8px; align-items:center;}

        .card{background:var(--card-bg); padding:30px; border-radius:20px; box-shadow:0 4px 6px -1px rgba(0,0,0,.05); margin-bottom:30px; border:1px solid var(--border);}
        label{font-weight:800; font-size:.9rem; color:#4b5563; margin-bottom:8px; display:block;}
        .form-control{width:100%; padding:12px; border:2px solid var(--border); border-radius:12px; font-size:1rem; box-sizing:border-box; font-family:'Tajawal',sans-serif; transition:.2s;}
        .form-control:focus{border-color:var(--primary); outline:none; box-shadow:0 0 0 4px rgba(99,102,241,.12);}
        .form-row{display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:20px; margin-bottom:20px;}

        .btn-add{background:var(--primary); color:#fff; border:none; padding:15px 30px; border-radius:14px; cursor:pointer; font-weight:900; width:100%; font-size:1.1rem; transition:.2s; box-shadow:0 6px 18px rgba(99,102,241,.35);}
        .btn-add:hover{transform:translateY(-2px);}

        /* Tier UI */
        .tiers-wrap{background:#fffbe6; padding:18px; border-radius:14px; border:1px solid #fef3c7;}
        .tier-row{
            display:grid;
            grid-template-columns:1.1fr .9fr .8fr 1.6fr 44px;
            gap:10px;
            background:#fff; padding:14px; border-radius:14px; border:1px solid var(--border);
            margin-bottom:10px; align-items:end; box-shadow:0 2px 8px rgba(0,0,0,.03);
        }
        @media(max-width:1100px){
            .tier-row{grid-template-columns:1fr 1fr}
            .tier-actions{grid-column:span 2}
        }
        .tier-label{font-size:.75rem; color:#6b7280; font-weight:900; display:block; margin-bottom:6px; white-space:nowrap;}
        .btn-del-row{color:#ef4444; background:#fee2e2; width:44px; height:44px; border-radius:12px; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:.15s;}
        .btn-del-row:hover{background:#fecaca;}

        .auto-weight-badge{display:none; background:#dcfce7; color:#166534; padding:11px; border-radius:12px; font-size:.85rem; font-weight:900; text-align:center; border:1px dashed #22c55e;}

        .nutri-box{
            display:grid;
            grid-template-columns:repeat(4, 1fr);
            gap:8px;
            background:#f9fafb;
            border:1px dashed #e5e7eb;
            border-radius:14px;
            padding:10px;
        }
        @media(max-width:768px){ .nutri-box{grid-template-columns:1fr 1fr} }
        .nutri-box .form-control{padding:10px; border-radius:12px;}
        .nutri-mini{font-size:.75rem; color:#6b7280; font-weight:900; margin-bottom:6px; display:block;}

        table{width:100%; border-collapse:separate; border-spacing:0 10px; margin-top:10px;}
        th{color:#6b7280; font-weight:900; padding:10px 20px; text-align:right;}
        td{background:#fff; padding:15px 20px; border-top:1px solid var(--border); border-bottom:1px solid var(--border);}
        td:first-child{border-right:1px solid var(--border); border-top-right-radius:12px; border-bottom-right-radius:12px;}
        td:last-child{border-left:1px solid var(--border); border-top-left-radius:12px; border-bottom-left-radius:12px;}

        .badge{padding:5px 12px; border-radius:999px; font-size:.8rem; font-weight:900; display:inline-block;}
        .badge-tiered{background:#ffedd5; color:#9a3412;}
        .badge-cat{background:#eef2ff; color:#3730a3;}
        .badge-off{background:#f3f4f6; color:#6b7280;}
        .badge-on{background:#dcfce7; color:#166534;}

        .btn-action{padding:8px 12px; border-radius:10px; margin-left:6px; text-decoration:none; font-size:.9rem; transition:.2s; display:inline-flex; align-items:center; justify-content:center; border:none; cursor:pointer;}
        .btn-edit{background:#e0e7ff; color:var(--primary);}
        .btn-del{background:#fee2e2; color:#ef4444;}
        .btn-toggle{background:#ecfeff; color:#0e7490;}

        input[type="number"]{font-family:'Segoe UI',sans-serif; direction:ltr; text-align:center; font-weight:900;}

        /* Modal */
        .modal-backdrop{position:fixed; inset:0; background:rgba(0,0,0,.35); display:none; align-items:center; justify-content:center; z-index:2000;}
        .modal{width:min(520px,92vw); background:#fff; border-radius:16px; padding:18px; border:1px solid var(--border); box-shadow:0 10px 30px rgba(0,0,0,.12);}
        .modal-header{display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;}
        .modal-title{font-weight:900; color:#111827;}
        .modal-close{background:#f3f4f6; border:1px solid var(--border); border-radius:12px; width:40px; height:40px; cursor:pointer;}
        .hint{color:#6b7280; font-weight:800; font-size:.85rem;}
        .warn{background:#fff7ed; border:1px dashed #fb923c; padding:12px; border-radius:12px; color:#9a3412; font-weight:900;}
        .note{background:#f0f9ff; border:1px dashed #38bdf8; padding:12px; border-radius:12px; color:#075985; font-weight:900; margin-top:12px;}
    </style>
</head>
<body>

<div class="sidebar">
    <?php if(file_exists('sidebar.php')) include 'sidebar.php'; ?>
</div>

<div class="main-content">

    <div class="page-header">
        <div>
            <h2>📦 مكتبة الخيارات + التصنيفات</h2>
            <small class="hint">الآن القيم الغذائية أصبحت داخل كل شريحة (Tier) — لكل وزن قيمه الخاصة ✅</small>
        </div>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <a href="manage_products.php" class="btn-back"><i class="fas fa-arrow-right"></i> عودة للمنتجات</a>
        </div>
    </div>

    <!-- =======================
         Card: Categories
    ======================== -->
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
            <h3 style="margin:0; color:#374151;"><i class="fas fa-layer-group"></i> تصنيفات الخيارات</h3>
            <button class="btn-action btn-edit" type="button" onclick="openCategoryModal()">
                <i class="fas fa-plus"></i> إضافة تصنيف
            </button>
        </div>

        <?php if(count($categories) > 0): ?>
            <div style="overflow-x:auto; margin-top:15px;">
                <table>
                    <thead>
                    <tr>
                        <th>التصنيف</th>
                        <th>الترتيب</th>
                        <th>الحالة</th>
                        <th>تحكم</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach($categories as $c):
                        $catActive = ((int)$c['is_active'] === 1);
                    ?>
                        <tr style="<?= $catActive ? '' : 'opacity:0.55;' ?>">
                            <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                            <td><?= (int)$c['sort_order'] ?></td>
                            <td>
                                <?= $catActive
                                    ? '<span class="badge badge-on">نشط</span>'
                                    : '<span class="badge badge-off">مخفي</span>' ?>
                            </td>
                            <td>
                                <form action="handle_categories.php" method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_category">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button class="btn-action btn-toggle" type="submit" title="تبديل الحالة">
                                        <i class="fas fa-toggle-on"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="text-align:center; padding:25px; color:#9ca3af;">لا توجد تصنيفات. أضف أول تصنيف الآن.</div>
        <?php endif; ?>
    </div>

    <!-- =======================
         Card: Add Option
    ======================== -->
    <div class="card">
        <h3 style="margin-top:0; padding-bottom:15px; border-bottom:1px dashed var(--border); color:#374151;">
            <i class="fas fa-plus-circle" style="color:var(--primary);"></i> إضافة خيار جديد
        </h3>

        <?php if(count($activeCategories) === 0): ?>
            <div class="warn">
                لازم تضيف تصنيف واحد على الأقل قبل إضافة الخيارات.
            </div>
        <?php endif; ?>

        <form action="handle_options.php" method="POST" <?= (count($activeCategories) === 0 ? 'onsubmit="return false;"' : '') ?>>
            <input type="hidden" name="action" value="add">

            <div class="form-row">
                <div>
                    <label>اسم الخيار <span style="color:red">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="مثال: رز أبيض / صوص ثوم / عصير" required>
                </div>

                <div>
                    <label>التصنيف <span style="color:red">*</span></label>
                    <select name="category_id" class="form-control" required <?= (count($activeCategories) === 0 ? 'disabled' : '') ?>>
                        <option value="">-- اختر التصنيف --</option>
                        <?php foreach($activeCategories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>وحدة القياس <span style="color:red">*</span></label>
                    <select name="unit" id="mainUnitSelect" class="form-control" style="font-weight:900; color:var(--primary);" onchange="updateUnitLabels()">
                        <option value="gram" data-label="وزن">جرام (g)</option>
                        <option value="ml" data-label="حجم">مل (ml)</option>
                        <option value="piece" data-label="عدد">قطعة (Piece)</option>
                        <option value="kg" data-label="وزن">كيلو (kg)</option>
                        <option value="liter" data-label="حجم">لتر (Liter)</option>
                    </select>
                </div>
            </div>

            <h4 style="color:var(--primary); margin:20px 0 10px 0; border-bottom:2px solid #f3f4f6; padding-bottom:10px;">
                <i class="fas fa-sliders-h"></i> شرائح التسعير + القيم الغذائية (لكل شريحة حسب وزن الوجبة)
            </h4>

            <div class="tiers-wrap">
                <div style="display:grid; grid-template-columns:1.1fr .9fr .8fr 1.6fr 44px; gap:10px; margin-bottom:10px; padding:0 8px; font-size:0.78rem; color:#92400e; font-weight:900;">
                    <div>شرط الوجبة (إذا الوزن &lt;)</div>
                    <div><i class="fas fa-utensils"></i> كمية الخيار (<span class="unit-display">جرام</span>)</div>
                    <div>السعر</div>
                    <div>📊 القيم الغذائية لهذه الشريحة</div>
                    <div></div>
                </div>

                <div id="tiers-container"></div>

                <button type="button" onclick="addTierRow()" style="background:white; border:2px dashed #d97706; color:#d97706; padding:12px 20px; border-radius:12px; cursor:pointer; font-weight:900; width:100%; margin-top:10px;">
                    <i class="fas fa-plus"></i> إضافة شريحة جديدة
                </button>

                <div class="note">
                    ✅ الآن القيم الغذائية يتم إدخالها داخل كل شريحة Tier (يعني لكل وزن قيمه الخاصة).
                </div>
            </div>

            <button type="submit" class="btn-add" style="margin-top:18px;" <?= (count($activeCategories) === 0 ? 'disabled' : '') ?>>
                <i class="fas fa-check-circle"></i> حفظ الخيار
            </button>
        </form>
    </div>

    <!-- =======================
         Card: Options List
    ======================== -->
    <div class="card">
        <h3 style="margin-top:0; color:#374151; font-size:1.1rem; margin-bottom:15px;">
            <i class="fas fa-list"></i> الخيارات المتاحة (<?= count($options); ?>)
        </h3>

        <?php if(count($options) > 0): ?>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                    <tr>
                        <th>الاسم</th>
                        <th>التصنيف</th>
                        <th>الوحدة</th>
                        <th>عدد الشرائح</th>
                        <th>الحالة</th>
                        <th>تحكم</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach($options as $opt):
                        $countTiers = countTiersSafe($opt['pricing_config'] ?? '');
                        $active = (int)($opt['is_active'] ?? 1) === 1;
                    ?>
                        <tr style="<?= $active ? '' : 'opacity:0.55;' ?>">
                            <td><strong style="font-size:1rem; color:#111827;"><?= htmlspecialchars($opt['name']); ?></strong></td>
                            <td><span class="badge badge-cat"><?= htmlspecialchars($opt['category_name'] ?? 'بدون تصنيف'); ?></span></td>
                            <td><span style="background:#f3f4f6; padding:5px 10px; border-radius:8px; font-weight:900; color:#555;"><?= htmlspecialchars($opt['unit']); ?></span></td>
                            <td><span class="badge badge-tiered"><?= (int)$countTiers; ?> مستويات</span></td>
                            <td><?= $active ? '<span class="badge badge-on">نشط</span>' : '<span class="badge badge-off">مخفي</span>' ?></td>
                            <td>
                                <a href="edit_option.php?id=<?= (int)$opt['id']; ?>" class="btn-action btn-edit" title="تعديل">
                                    <i class="fas fa-pen"></i>
                                </a>

                                <form action="handle_options.php" method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$opt['id']; ?>">
                                    <button type="submit" class="btn-action btn-toggle" title="تبديل الحالة">
                                        <i class="fas fa-toggle-on"></i>
                                    </button>
                                </form>

                                <a href="handle_options.php?action=delete&id=<?= (int)$opt['id']; ?>"
                                   class="btn-action btn-del"
                                   onclick="return confirm('لن يتم حذف الخيار، سيتم إخفاؤه فقط. متابعة؟')"
                                   title="إخفاء">
                                    <i class="fas fa-eye-slash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="text-align:center; padding:40px; color:#9ca3af;">لا توجد خيارات مضافة.</div>
        <?php endif; ?>
    </div>

</div>

<!-- =======================
     Modal: Add Category
======================= -->
<div class="modal-backdrop" id="catModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-plus"></i> إضافة تصنيف</div>
            <button class="modal-close" type="button" onclick="closeCategoryModal()"><i class="fas fa-times"></i></button>
        </div>
        <form action="handle_categories.php" method="POST">
            <input type="hidden" name="action" value="add_category">
            <label>اسم التصنيف</label>
            <input type="text" name="name" class="form-control" placeholder="مثال: خيارات الكارب" required>
            <div style="height:10px;"></div>
            <label>الترتيب (اختياري)</label>
            <input type="number" name="sort_order" class="form-control" value="0">
            <div style="height:15px;"></div>
            <button class="btn-add" type="submit"><i class="fas fa-check"></i> حفظ التصنيف</button>
        </form>
    </div>
</div>

<script>
function openCategoryModal(){ document.getElementById('catModal').style.display='flex'; }
function closeCategoryModal(){ document.getElementById('catModal').style.display='none'; }

// Gram => auto (إخفاء دون تصفير)
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
        servingBadges.forEach(b => b.style.display = 'block');
    } else {
        servingInputs.forEach(input => {
            input.style.display = 'block';
            input.placeholder = `ال${labelType} (${unitText})`;
        });
        servingBadges.forEach(b => b.style.display = 'none');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('tiers-container').children.length === 0) addTierRow();
    updateUnitLabels();
});

function addTierRow() {
    const container = document.getElementById('tiers-container');
    const unit = document.getElementById('mainUnitSelect').value;

    const inputStyle = (unit === 'gram') ? 'display:none;' : 'display:block;';
    const badgeStyle = (unit === 'gram') ? 'display:block;' : 'display:none;';

    const div = document.createElement('div');
    div.className = 'tier-row';

    div.innerHTML = `
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

        <div>
            <span class="tier-label">القيم الغذائية لهذه الشريحة</span>
            <div class="nutri-box">
                <div>
                    <span class="nutri-mini">🔥 سعرات</span>
                    <input type="number" name="tiers_calories[]" class="form-control" placeholder="0">
                </div>
                <div>
                    <span class="nutri-mini">🥩 بروتين</span>
                    <input type="number" step="0.1" name="tiers_protein[]" class="form-control" placeholder="0">
                </div>
                <div>
                    <span class="nutri-mini">🥔 كارب</span>
                    <input type="number" step="0.1" name="tiers_carbs[]" class="form-control" placeholder="0">
                </div>
                <div>
                    <span class="nutri-mini">🥑 دهون</span>
                    <input type="number" step="0.1" name="tiers_fat[]" class="form-control" placeholder="0">
                </div>
            </div>
        </div>

        <div class="tier-actions">
            <button type="button" class="btn-del-row" onclick="this.closest('.tier-row').remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;

    container.appendChild(div);
    updateUnitLabels();
}
</script>

</body>
</html>