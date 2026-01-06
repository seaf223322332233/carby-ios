<?php
// chef_menu_orders.php - (إصلاح خطأ 500 + توارث الأوزان) + إشعارات عند تجهيز الطلب
// =======================================================

// 1) إعدادات الصفحة
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Content-Type: text/html; charset=utf-8");

// إخفاء الأخطاء البرمجية عن المستخدم لمنع تشوه الصفحة (مهم جداً للإنتاج)
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'auth_chef.php';
require_once 'db_connect.php';

$today = date('Y-m-d');

// =======================================================
// 🔔 نظام الإشعارات (مُركّب بدون تغيير الواجهة)
// =======================================================

/**
 * يتحقق من وجود جدول في قاعدة البيانات
 */
function tableExists(PDO $pdo, $table) {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * جلب أعمدة جدول
 */
function getTableColumns(PDO $pdo, $table) {
    $cols = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = $row['Field'];
        }
    } catch (Exception $e) {}
    return $cols;
}

/**
 * إدراج إشعار بشكل مرن حسب أعمدة جدول notifications الموجودة فعلياً
 */
function insertNotificationFlexible(PDO $pdo, array $data) {
    // data keys المحتملة: user_id, title, message, link, type, created_at, is_read
    if (!tableExists($pdo, 'notifications')) return false;

    $cols = getTableColumns($pdo, 'notifications');
    if (empty($cols)) return false;

    // خرائط أعمدة شائعة
    $map = [
        'user_id'    => ['user_id', 'client_id', 'customer_id', 'uid'],
        'title'      => ['title', 'notif_title', 'subject'],
        'message'    => ['message', 'body', 'content', 'notif_message', 'text'],
        'link'       => ['link', 'url', 'target_url'],
        'type'       => ['type', 'category', 'notif_type'],
        'is_read'    => ['is_read', 'read_flag', 'seen', 'is_seen'],
        'created_at' => ['created_at', 'created', 'createdOn', 'created_time', 'date_created'],
    ];

    $insertCols = [];
    $params = [];
    $values = [];

    foreach ($map as $key => $candidates) {
        $foundCol = null;
        foreach ($candidates as $cand) {
            if (in_array($cand, $cols, true)) { $foundCol = $cand; break; }
        }
        if ($foundCol !== null && array_key_exists($key, $data)) {
            $insertCols[] = "`$foundCol`";
            $params[] = $data[$key];
            $values[] = "?";
        }
    }

    // لو مافيش ولا عمود مناسب، نوقف
    if (count($insertCols) < 2) return false;

    $sql = "INSERT INTO `notifications` (" . implode(',', $insertCols) . ")
            VALUES (" . implode(',', $values) . ")";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * إنشاء إشعار "طلبك جاهز" للعميل عند تجهيز الطلب
 */
function notifyOrderPrepared(PDO $pdo, int $orderId) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM individual_orders WHERE id = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $ord = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ord) return;

        // محاولة معرفة عمود العميل داخل individual_orders
        $clientId = 0;
        foreach (['client_id', 'customer_id', 'user_id', 'client', 'customer'] as $k) {
            if (isset($ord[$k]) && is_numeric($ord[$k]) && (int)$ord[$k] > 0) {
                $clientId = (int)$ord[$k];
                break;
            }
        }

        // إذا ما عندنا client_id داخل الطلب، نوقف بصمت (بدون كسر الصفحة)
        if ($clientId <= 0) return;

        $customerName = trim((string)($ord['customer_name'] ?? 'عميلنا'));
        $title = "✅ طلبك جاهز";
        $message = "مرحباً {$customerName}، تم تجهيز طلبك رقم #{$orderId} وهو الآن جاهز.";
        // ضع هنا صفحة تتبع/عرض الطلب للعميل إن كانت موجودة
        $link = "client_orders.php?order_id=" . $orderId;

        insertNotificationFlexible($pdo, [
            'user_id'    => $clientId,
            'title'      => $title,
            'message'    => $message,
            'link'       => $link,
            'type'       => 'order_prepared',
            'is_read'    => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Exception $e) {
        // تجاهل صامت
    }
}

// =======================================================
// 🛠️ دوال المعالجة
// =======================================================

// استخراج الرقم من النص (مثال: "200 جرام" -> 200)
function parseWeightToGrams($text) {
    if ($text === null) return 0;
    $s = strtolower(trim((string)$text));
    if ($s === '') return 0;

    // استخراج الرقم (صحيح أو عشري)
    if (!preg_match('/(\d+(\.\d+)?)/', $s, $m)) return 0;
    $num = (float)$m[1];

    // التحويل للكيلو
    if (strpos($s, 'kg') !== false || strpos($s, 'كجم') !== false || strpos($s, 'كيلو') !== false) {
        return $num * 1000;
    }
    return $num; // الافتراضي جرام
}

// تحويل وحدات القاعدة
function toGrams($weight, $unit) {
    $w = (float)$weight;
    $u = strtolower(trim((string)$unit));
    if (in_array($u, ['kg', 'كيلو', 'كجم', 'كغ'])) return $w * 1000;
    return $w;
}

// دالة التجميع للملخص
function addToSummary(&$totals, $name, $qty, $weight_g) {
    $key = trim($name);
    // تنظيف: تجاهل الأسماء الفارغة أو التي هي عبارة عن أرقام فقط
    if ($key === '' || is_numeric($key)) return;

    if (!isset($totals[$key])) {
        $totals[$key] = ['qty' => 0, 'weight' => 0];
    }
    $totals[$key]['qty'] += (int)$qty;
    $totals[$key]['weight'] += (float)$weight_g;
}

// تنسيق العرض
function formatGramsSmart($grams) {
    if ($grams <= 0) return ""; // لا تعرض الصفر
    if ($grams >= 1000) return number_format($grams / 1000, 2) . " <small>كجم</small>";
    return number_format($grams, 0) . " <small>جم</small>";
}

// ====================================================
// 2) معالجة الإجراءات
// ====================================================
$msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    if (!empty($_POST['order_ids']) && is_array($_POST['order_ids'])) {
        $ids = array_map('intval', $_POST['order_ids']);
        $ids = array_values(array_filter($ids, fn($v) => $v > 0));

        if (!empty($ids)) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE individual_orders SET status = 'prepared' WHERE id IN ($in)");
            $stmt->execute($ids);

            // 🔔 إشعارات لكل طلب تم تجهيزه
            foreach ($ids as $oid) {
                notifyOrderPrepared($pdo, (int)$oid);
            }

            $msg = "تم تحويل " . count($ids) . " طلب إلى (جاهز).";
        }
    }
}

if (isset($_GET['done_id'])) {
    $id = (int)$_GET['done_id'];
    $pdo->prepare("UPDATE individual_orders SET status = 'prepared' WHERE id = ?")->execute([$id]);

    // 🔔 إشعار عند تجهيز طلب واحد
    notifyOrderPrepared($pdo, $id);

    header("Location: chef_menu_orders.php");
    exit;
}

// ====================================================
// 3) المنطق الأساسي: التجميع والحساب
// ====================================================

$menu_totals = [];

// أ) جلب العناصر وتجميعها (Mise en place)
try {
    $sql_items = "SELECT i.quantity, i.selected_weight, i.options_json,
                          p.name as meal_name, p.weight as base_weight, p.weight_unit
                  FROM individual_order_items i
                  JOIN individual_orders io ON i.order_id = io.id
                  JOIN products p ON i.meal_id = p.id
                  WHERE io.status = 'pending'";

    $stmt_items = $pdo->query($sql_items);
    $all_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_items as $item) {
        $mealName = (string)$item['meal_name'];
        $qty = (int)$item['quantity'];

        // 1️⃣ حساب وزن الوجبة الأساسية
        $currentMealWeight = 0;

        // أ) هل يوجد وزن مختار (من الأحجام)؟
        if (!empty($item['selected_weight'])) {
            $currentMealWeight = parseWeightToGrams($item['selected_weight']);
        }

        // ب) إذا لم يوجد، نستخدم الوزن الأساسي للمنتج
        if ($currentMealWeight == 0) {
            $currentMealWeight = toGrams($item['base_weight'], $item['weight_unit']);
        }

        // إضافة الوجبة للملخص
        $totalMealWeight = $currentMealWeight * $qty;
        addToSummary($menu_totals, $mealName, $qty, $totalMealWeight);

        // 2️⃣ معالجة الخيارات (الإضافات)
        if (!empty($item['options_json'])) {
            $opts = json_decode($item['options_json'], true);
            if (is_array($opts)) {
                foreach ($opts as $opt) {
                    $optName = '';
                    $optQtyRaw = '';

                    // استخراج الاسم
                    if (is_array($opt)) {
                        $optName = isset($opt['name']) ? trim((string)$opt['name']) : '';
                        $optQtyRaw = isset($opt['quantity']) ? trim((string)$opt['quantity']) : '';
                    } elseif (is_string($opt)) {
                        $optName = trim($opt);
                    }

                    if ($optName === '' || is_numeric($optName)) continue;

                    // --- 🔥 منطق حساب وزن الخيار 🔥 ---
                    $optWeightPerUnit = 0;

                    // أ) هل يوجد وزن محدد في اسم الخيار؟ (مثل: رز 200جم)
                    $w_explicit = parseWeightToGrams($optQtyRaw);
                    if ($w_explicit > 0) {
                        $optWeightPerUnit = $w_explicit;
                    }
                    // ب) القاعدة الذهبية: إذا لم يوجد وزن، خذ نفس وزن الوجبة الأساسية
                    else {
                        $optWeightPerUnit = $currentMealWeight;
                    }

                    // إضافة للملخص
                    addToSummary($menu_totals, $optName . " (إضافة)", $qty, $optWeightPerUnit * $qty);
                }
            }
        }
    }
} catch (Exception $e) {
    // تجاهل الأخطاء الصامتة
}

// ب) جلب التذاكر الفردية (للعرض فقط)
$orders_display = [];
try {
    $sql_orders = "SELECT * FROM individual_orders WHERE status = 'pending' ORDER BY created_at ASC";
    $raw_orders = $pdo->query($sql_orders)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($raw_orders as $ord) {
        $ord_id = (int)$ord['id'];

        $sql_items = "SELECT i.quantity, p.name as meal_name, i.selected_weight, i.options_json
                      FROM individual_order_items i
                      JOIN products p ON i.meal_id = p.id
                      WHERE i.order_id = ?";
        $stmt_items = $pdo->prepare($sql_items);
        $stmt_items->execute([$ord_id]);
        $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

        $items_html = '<ul style="margin:5px 0; padding-right:20px; list-style:none;">';
        foreach ($items as $it) {
            $qty_badge = '<span style="background:#8e44ad; color:white; padding:2px 8px; border-radius:4px; font-weight:bold; font-size:0.9rem;">x' . (int)$it['quantity'] . '</span>';

            $details = '';

            // حساب وزن الوجبة الحالية لهذا السطر
            $thisMealWeight = 0;
            if (!empty($it['selected_weight'])) {
                $thisMealWeight = parseWeightToGrams($it['selected_weight']);
                $details .= ' <span style="color:#d35400; font-weight:800; font-size:0.9rem;">[ ' . htmlspecialchars($it['selected_weight']) . ' ]</span>';
            }

            // عرض الخيارات
            if (!empty($it['options_json'])) {
                $opts = json_decode($it['options_json'], true);
                if (is_array($opts)) {
                    $opt_parts = [];
                    foreach ($opts as $op) {
                        $opt_txt = '';
                        if (is_array($op)) {
                            $opt_txt = isset($op['name']) ? (string)$op['name'] : '';
                        } elseif (is_string($op)) {
                            $opt_txt = $op;
                        }

                        if ($opt_txt !== '' && !is_numeric($opt_txt)) {
                            // عرض الوزن بجانب الخيار (للتأكيد للشيف)
                            $optW = ($thisMealWeight > 0) ? formatGramsSmart($thisMealWeight) : "";
                            $opt_parts[] = htmlspecialchars($opt_txt) . " <small style='color:#777'>($optW)</small>";
                        }
                    }
                    if(!empty($opt_parts)){
                        $details .= '<div style="font-size:0.85rem; color:#27ae60; margin-right:10px; margin-top:2px;">+ ' . implode('، ', $opt_parts) . '</div>';
                    }
                }
            }

            $items_html .= "<li style='margin-bottom:8px; border-bottom:1px dashed #eee; padding-bottom:5px;'>{$qty_badge} <strong style='font-size:1.1rem; color:#333;'>" . htmlspecialchars($it['meal_name']) . "</strong>{$details}</li>";
        }
        $items_html .= '</ul>';

        $orders_display[] = [
            'id' => $ord_id,
            'customer_name' => $ord['customer_name'],
            'created_at' => $ord['created_at'],
            'time' => date('H:i', strtotime($ord['created_at'])),
            'items_html' => $items_html
        ];
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta id="autoRefresh" http-equiv="refresh" content="30">
    <title>شاشة المطبخ (المنيو)</title>

    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">

    <style>
        body { background-color: #f4f6f9; font-family: 'Tajawal', sans-serif; }

        .chef-header-menu {
            background: #8e44ad; color: white; padding: 15px 20px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 4px 15px rgba(142, 68, 173, 0.3); margin-bottom: 25px;
        }

        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .summary-box {
            background: #fff; padding: 20px; border-radius: 12px; text-align: center;
            border-bottom: 5px solid #8e44ad; box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            position: relative; transition: transform 0.2s;
        }
        .summary-box:hover { transform: translateY(-3px); }
        .summary-box h4 { margin: 0 0 10px; font-size: 1.1rem; color: #444; }
        .summary-box .num { font-size: 2rem; font-weight: 800; color: #8e44ad; line-height:1; }
        .summary-box .weight { font-size: 1rem; color: #d35400; font-weight: 800; margin-top:8px; background:#fff3e0; display:inline-block; padding:2px 10px; border-radius:10px; }

        .summary-check {
            position:absolute; top:15px; right:15px; width:22px; height:22px; cursor:pointer; accent-color:#8e44ad;
        }

        .order-card-menu {
            background: #fff; border-left: 6px solid #8e44ad;
            padding: 20px; margin-bottom: 15px; border-radius: 12px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.03);
            display: flex; align-items: flex-start; justify-content: space-between;
        }
        .checkbox-wrapper { display: flex; gap: 15px; align-items: flex-start; flex: 1; }
        .big-check { width: 25px; height: 25px; cursor: pointer; accent-color: #8e44ad; margin-top: 5px; }
        .order-header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; padding-bottom:5px; border-bottom:1px solid #eee; }
        .order-time { font-size: 0.85rem; color: #555; background: #f3e5f5; padding: 3px 10px; border-radius: 5px; font-weight: bold; }

        .btn-refresh {
            background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.4);
            padding: 8px 15px; border-radius: 8px; text-decoration: none; font-size:0.9rem;
        }
        .btn-refresh:hover { background: rgba(255,255,255,0.3); }

        .btn-bulk {
            background: #8e44ad; color: white; border: none; padding: 12px 30px;
            border-radius: 8px; font-weight: bold; cursor: pointer; font-size:1rem;
            box-shadow: 0 4px 10px rgba(142, 68, 173, 0.3);
        }
        .btn-done {
            background: #27ae60; color: white; padding: 10px 20px; border-radius: 8px;
            text-decoration: none; font-weight: bold; font-size: 0.9rem; display: flex; align-items: center; gap: 5px;
            align-self: center; box-shadow: 0 4px 6px rgba(39, 174, 96, 0.2);
        }

        .print-hide {}

        @media print {
            body { background: white; font-family: Tahoma, Arial, sans-serif; }
            .print-hide, .chef-sidebar, .btn-done, .big-check { display: none !important; }
            .main-content { margin: 0; padding: 0; }
            .content-wrapper { padding: 0; }

            .summary-grid { display: block !important; }
            .summary-box {
                border: 2px solid #000; float: right; width: 45%; margin: 1%;
                box-shadow: none; page-break-inside: avoid;
            }
            .order-card-menu {
                border: 2px solid #000; border-left: 2px solid #000;
                margin-bottom: 20px; page-break-inside: avoid; display: block;
            }
            .order-details { width: 100%; }
        }
    </style>
</head>
<body>

<?php include 'chef_sidebar.php'; ?>

<div class="main-content">

    <div class="chef-header-menu">
        <div style="display:flex; align-items:center; gap:15px;">
            <h2 style="margin:0;"><i class="fas fa-fire-alt"></i> شاشة المطبخ (المنيو)</h2>
            <span style="background:rgba(0,0,0,0.2); padding:5px 10px; border-radius:5px; font-size:0.9rem;">
                تحديث تلقائي
            </span>
        </div>
        <div style="display:flex; gap:10px;">
            <a href="chef_menu_orders.php" class="btn-refresh"><i class="fas fa-sync-alt"></i> تحديث</a>
            <button type="button" class="btn-refresh" onclick="printSummarySelected()" style="cursor:pointer;">
                <i class="fas fa-print"></i> طباعة التجهيز (Mise en place)
            </button>
        </div>
    </div>

    <div class="content-wrapper" style="padding:0 20px;">

        <?php if(!empty($msg)): ?>
            <div style="background:#d4edda; color:#155724; padding:15px; border-radius:8px; margin-bottom:20px; border-right:5px solid #28a745;">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <h3 style="color:#555; margin-bottom:15px; border-bottom:2px solid #ddd; padding-bottom:10px;">
            📊 ملخص التجهيز (الكميات الكلية)
        </h3>

        <div class="print-hide" style="margin-bottom:15px; display:flex; align-items:center; gap:10px;">
            <input type="checkbox" id="selectAllSummary" onchange="toggleAllSummary(this)" style="width:18px; height:18px;">
            <label for="selectAllSummary" style="cursor:pointer; font-weight:bold;">تحديد الكل للطباعة</label>
        </div>

        <div class="summary-grid" id="summaryGrid">
            <?php if (empty($menu_totals)): ?>
                <div class="summary-box" style="grid-column:1/-1; border-color:#27ae60; background:#f0fff4;">
                    <h3 style="color:#27ae60; margin:0;">المطبخ جاهز! لا توجد طلبات معلقة ✅</h3>
                </div>
            <?php else: foreach($menu_totals as $item_name => $data): ?>
                <div class="summary-box"
                     data-summary="1"
                     style="<?php echo (strpos($item_name, '(إضافة)') !== false) ? 'border-bottom-color:#e67e22; background:#fffdf9;' : ''; ?>">
                    <input type="checkbox" class="summary-check print-hide" onchange="toggleSummaryCard(this)">

                    <h4><?php echo htmlspecialchars($item_name); ?></h4>
                    <div class="num"><?php echo (int)$data['qty']; ?> <span style="font-size:1rem; color:#888;">طلب</span></div>

                    <?php if(!empty($data['weight']) && $data['weight'] > 0): ?>
                        <div class="weight">
                            مطلوب: <?php echo formatGramsSmart($data['weight']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <h3 style="color:#555; margin-top:40px; margin-bottom:15px; border-bottom:2px solid #ddd; padding-bottom:10px;">
            📝 تذاكر الطلبات (Order Tickets)
        </h3>

        <?php if(!empty($orders_display)): ?>
        <form method="POST" id="menuForm">

            <div class="print-hide" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; background:#fff; padding:15px; border-radius:12px; border:1px solid #eee;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <input type="checkbox" id="selectAll" class="big-check">
                    <label for="selectAll" style="font-weight:bold; cursor:pointer; font-size:1.1rem;">تحديد الكل</label>
                </div>

                <button type="submit" name="bulk_action" class="btn-bulk">
                    <i class="fas fa-check-double"></i> إنهاء المحدد
                </button>
            </div>

            <?php foreach ($orders_display as $o): ?>
            <div class="order-card-menu">
                <div class="checkbox-wrapper">
                    <input type="checkbox" name="order_ids[]" value="<?php echo (int)$o['id']; ?>" class="big-check row-check">

                    <div class="order-details">
                        <div class="order-header">
                            <span style="font-weight:900; color:#8e44ad; font-size:1.3rem;">#<?php echo (int)$o['id']; ?></span>
                            <span class="order-time"><i class="far fa-clock"></i> <?php echo $o['time']; ?></span>
                            <span style="font-size:0.95rem; color:#666; margin-right:auto;">
                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($o['customer_name']); ?>
                            </span>
                        </div>

                        <?php echo $o['items_html']; ?>
                    </div>
                </div>

                <a href="?done_id=<?php echo (int)$o['id']; ?>" class="btn-done print-hide">
                    <i class="fas fa-check"></i> جاهز
                </a>
            </div>
            <?php endforeach; ?>

        </form>
        <?php else: ?>
            <div style="text-align:center; padding:50px; color:#aaa; border:2px dashed #ccc; border-radius:15px;">
                <h3>لا توجد طلبات جديدة</h3>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
    (function(){
        var selectAll = document.getElementById('selectAll');
        if (!selectAll) return;
        selectAll.addEventListener('change', function() {
            var checkboxes = document.querySelectorAll('.row-check');
            for (var i=0; i<checkboxes.length; i++) {
                checkboxes[i].checked = this.checked;
            }
        });
    })();

    function toggleSummaryCard(cb){
        var card = cb.closest('.summary-box');
        if(cb.checked) card.classList.add('selected-for-print');
        else card.classList.remove('selected-for-print');
    }

    function toggleAllSummary(source){
        var cbs = document.querySelectorAll('.summary-check');
        for(var i=0; i<cbs.length; i++){
            cbs[i].checked = source.checked;
            toggleSummaryCard(cbs[i]);
        }
    }

    function disableAutoRefresh() {
        var m = document.getElementById('autoRefresh');
        if (m) m.setAttribute('content', '0');
    }
    function enableAutoRefresh() {
        var m = document.getElementById('autoRefresh');
        if (m) m.setAttribute('content', '20');
    }

    function printSummarySelected() {
        var selected = document.querySelectorAll('.summary-box.selected-for-print');
        var allCards = document.querySelectorAll('.summary-box[data-summary="1"]');
        var cardsToPrint = (selected.length > 0) ? selected : allCards;

        if (!cardsToPrint.length) { alert('لا يوجد بيانات للطباعة'); return; }

        disableAutoRefresh();
        var now = (new Date()).toLocaleString('ar-SA');

        var html = '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>طباعة المطبخ</title>'
                 + '<style>body{font-family:Arial,sans-serif;padding:20px;direction:rtl}'
                 + '.head{text-align:center;border-bottom:2px solid #000;margin-bottom:20px;padding-bottom:10px}'
                 + '.card{border:2px solid #000;padding:10px;margin-bottom:10px;width:48%;float:right;margin-left:1%;box-sizing:border-box;page-break-inside:avoid;height:120px;position:relative}'
                 + '.name{font-size:16pt;font-weight:bold;margin-bottom:10px}'
                 + '.qty{font-size:24pt;font-weight:900;position:absolute;bottom:10px;left:10px}'
                 + '.weight{font-size:14pt;font-weight:bold;position:absolute;bottom:10px;right:10px;background:#eee;padding:2px 5px}'
                 + '</style></head><body>'
                 + '<div class="head"><h2>قائمة تحضير (Mise en place)</h2><small>'+now+'</small></div>';

        for (var i=0; i<cardsToPrint.length; i++) {
            var c = cardsToPrint[i];
            var name = c.querySelector('h4').innerText;
            var num = c.querySelector('.num').innerText;
            var wgt = c.querySelector('.weight') ? c.querySelector('.weight').innerText.replace('مطلوب: ','') : '';

            num = parseInt(num);

            html += '<div class="card">'
                  + '<div class="name">'+name+'</div>'
                  + '<div class="weight">'+wgt+'</div>'
                  + '<div class="qty">'+num+'</div>'
                  + '</div>';
        }

        html += '<script>window.onload=function(){window.print();window.onafterprint=function(){window.close();}}<\/script></body></html>';

        var w = window.open('','_blank','width=900,height=700');
        if(w){
            w.document.write(html);
            w.document.close();
        } else {
            alert('اسمح بالنوافذ المنبثقة');
            enableAutoRefresh();
        }
        setTimeout(enableAutoRefresh, 5000);
    }
</script>

</body>
</html>