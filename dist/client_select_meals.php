<?php
/**
 * client_select_meals.php (FINAL - FIXED)
 * -----------------------------------------------------------------------------
 * ✅ Fix: selected_date used before defined
 * ✅ Fix: remove duplicated guard blocks
 * ✅ Fix: enforce grace lock in GET + POST (server-side)
 * ✅ Fix: grace days from system_settings (fallback 30)
 * ✅ Keep: same UI/CSS/JS + footer include untouched
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once 'db_connect.php';

if (session_status() == PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$client_id = (int)$_SESSION['user_id'];
date_default_timezone_set('Asia/Riyadh');

/* ===========================
   Helpers
=========================== */
function safeFloat($v): float {
    if ($v === null || $v === '' || !is_numeric($v)) return 0.0;
    return (float)$v;
}
function safeInt($v): int {
    if ($v === null || $v === '' || !is_numeric($v)) return 0;
    return (int)$v;
}
function fmtNum($n, $dec=1): string {
    $n = (float)$n;
    if (abs($n - round($n)) < 0.00001) return (string)(int)round($n);
    return rtrim(rtrim(number_format($n, $dec, '.', ''), '0'), '.');
}
function tableExists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$table]);
    return $st->rowCount() > 0;
}
function columnExists(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?
    ");
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}

function unitShort(string $u): string {
    $u = strtolower(trim($u));
    if ($u === 'gram' || $u === 'g' || $u === 'gm' || $u === 'جرام' || $u === 'غرام') return 'g';
    if ($u === 'ml' || $u === 'مل') return 'ml';
    if ($u === 'kg' || $u === 'كيلو' || $u === 'كجم') return 'kg';
    if ($u === 'liter' || $u === 'l' || $u === 'لتر') return 'l';
    if ($u === 'piece' || $u === 'pcs' || $u === 'قطعة' || $u === 'حبة') return 'pc';
    return $u ?: '';
}

function getUnitType($unit) {
    if (empty($unit)) return 'count';
    $u = strtolower(trim($unit));
    $solids  = ['g','gm','gram','kg','كيلو','كجم','جرام','غرام'];
    $liquids = ['ml','l','liter','مل','ملي','لتر'];
    if (in_array($u, $solids, true)) return 'solid';
    if (in_array($u, $liquids, true)) return 'liquid';
    return 'count';
}

/**
 * اختيار Tier مناسب حسب وزن الوجبة allowed_weight
 */
function pickTierForMealWeight(string $jsonConfig, float $mealWeight): ?array {
    $data = json_decode($jsonConfig ?? '', true);
    if (!is_array($data)) return null;
    $tiers = $data['tiers'] ?? null;
    if (!is_array($tiers) || empty($tiers)) return null;

    usort($tiers, function($a, $b) {
        return safeFloat($a['threshold'] ?? 0) <=> safeFloat($b['threshold'] ?? 0);
    });

    foreach ($tiers as $tier) {
        $th = safeFloat($tier['threshold'] ?? 0);
        if ($mealWeight < $th) return $tier;
    }
    return end($tiers) ?: null;
}

/**
 * جلب قيم الوجبة حسب وزن الباقة من product_sizes
 * لو ما وجد weight مطابق: نأخذ الأقرب
 */
function getMealBaseMacros(PDO $pdo, int $product_id, float $allowed_weight): array {
    $st = $pdo->prepare("
        SELECT weight, unit, calories, protein, carbs, fats
        FROM product_sizes
        WHERE product_id=? AND is_active=1
        ORDER BY ABS(weight - ?) ASC
        LIMIT 1
    ");
    $st->execute([$product_id, $allowed_weight]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [
            'weight' => $allowed_weight,
            'unit' => 'gram',
            'calories' => 0,
            'protein' => 0,
            'carbs' => 0,
            'fat' => 0,
        ];
    }

    return [
        'weight' => safeFloat($row['weight'] ?? $allowed_weight),
        'unit' => $row['unit'] ?? 'gram',
        'calories' => safeFloat($row['calories'] ?? 0),
        'protein' => safeFloat($row['protein'] ?? 0),
        'carbs' => safeFloat($row['carbs'] ?? 0),
        'fat' => safeFloat($row['fats'] ?? 0),
    ];
}

/**
 * حساب مساهمة خيار (إضافة)
 * ✅ pricing_config tiers nutrition
 * ✅ fallback columns
 */
function computeOptionContribution(array $optRow, float $allowed_weight): array {
    $unit = (string)($optRow['unit'] ?? 'gram');
    $pc   = (string)($optRow['pricing_config'] ?? '');

    $tier = pickTierForMealWeight($pc, $allowed_weight);

    $serving = 0.0;
    if (is_array($tier)) {
        $serving = safeFloat($tier['serving'] ?? ($tier['qty'] ?? 0));
        if ($serving <= 0 && isset($tier['auto_serving']) && $tier['auto_serving']) {
            $t = getUnitType($unit);
            $serving = ($t === 'solid') ? $allowed_weight : 1;
        }
    }
    if ($serving <= 0) {
        $t = getUnitType($unit);
        $serving = ($t === 'solid') ? $allowed_weight : 1;
    }

    $nCal = 0.0; $nPro = 0.0; $nCar = 0.0; $nFat = 0.0;

    if (is_array($tier)) {
        if (isset($tier['nutrition']) && is_array($tier['nutrition'])) {
            $nCal = safeFloat($tier['nutrition']['calories'] ?? 0);
            $nPro = safeFloat($tier['nutrition']['protein'] ?? 0);
            $nCar = safeFloat($tier['nutrition']['carbs'] ?? 0);
            $nFat = safeFloat($tier['nutrition']['fat'] ?? 0);
        } else {
            $nCal = safeFloat($tier['calories'] ?? 0);
            $nPro = safeFloat($tier['protein'] ?? 0);
            $nCar = safeFloat($tier['carbs'] ?? 0);
            $nFat = safeFloat($tier['fat'] ?? 0);
        }
    }

    if (($nCal + $nPro + $nCar + $nFat) <= 0) {
        $nCal = safeFloat($optRow['calories'] ?? 0);
        $nPro = safeFloat($optRow['protein'] ?? 0);
        $nCar = safeFloat($optRow['carbs'] ?? 0);
        $nFat = safeFloat($optRow['fat'] ?? 0);
    }

    return [
        'serving' => $serving,
        'unit' => $unit,
        'calories' => $nCal,
        'protein'  => $nPro,
        'carbs'    => $nCar,
        'fat'      => $nFat,
    ];
}

function sumMacros(array $base, array $add): array {
    return [
        'calories' => safeFloat($base['calories'] ?? 0) + safeFloat($add['calories'] ?? 0),
        'protein'  => safeFloat($base['protein'] ?? 0)  + safeFloat($add['protein'] ?? 0),
        'carbs'    => safeFloat($base['carbs'] ?? 0)    + safeFloat($add['carbs'] ?? 0),
        'fat'      => safeFloat($base['fat'] ?? 0)      + safeFloat($add['fat'] ?? 0),
    ];
}

/* ===========================
   Sync (limits) - ✅ تطبيق التعديلات الخاصة بالعميل
=========================== */
function computeSync(PDO $pdo, int $client_id, array $client_data, int $cat_id, string $date): array {
    $pkg_id = intval($client_data['pkg_id']);
    
    // 1. جلب الحدود الأساسية من الباقة
    $pkgCats = [];
    $stmt_pkg = $pdo->prepare("SELECT category_id, allowed_count FROM package_category_limits WHERE package_id=?");
    $stmt_pkg->execute([$pkg_id]);
    foreach ($stmt_pkg->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pkgCats[(int)$r['category_id']] = (int)$r['allowed_count'];
    }
    
    // 2. تطبيق التعديلات الخاصة بالعميل (client_category_limits)
    $clientCats = [];
    $stmt_client = $pdo->prepare("SELECT category_id, allowed_count FROM client_category_limits WHERE user_id=?");
    $stmt_client->execute([$client_id]);
    foreach ($stmt_client->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $clientCats[(int)$r['category_id']] = (int)$r['allowed_count'];
    }
    
    // دمج: التعديلات الخاصة بالعميل تحل محل أو تضيف على الباقة
    foreach ($clientCats as $cid => $cnt) {
        $pkgCats[$cid] = $cnt;
    }
    
    // 3. حساب الإجمالي
    $total_all_allowed = 0;
    foreach ($pkgCats as $cnt) {
        $total_all_allowed += max(0, (int)$cnt);
    }
    
    // 4. إضافة meals_credit_extra
    $meals_credit_extra = intval($client_data['meals_credit_extra'] ?? 0);
    $total_all_allowed += max(0, $meals_credit_extra);

    $stmt_sum_used = $pdo->prepare("SELECT COUNT(*) FROM daily_selections WHERE client_id=? AND delivery_date >= ?");
    $stmt_sum_used->execute([$client_id, $client_data['subscription_start_date']]);
    $used_all = intval($stmt_sum_used->fetchColumn());

    $grand_remaining = max(0, $total_all_allowed - $used_all);

    $stmt_day_used = $pdo->prepare("SELECT COUNT(*) FROM daily_selections WHERE client_id=? AND delivery_date=?");
    $stmt_day_used->execute([$client_id, $date]);
    $day_used = intval($stmt_day_used->fetchColumn());

    $cat_remaining = null;
    if ($cat_id > 0) {
        // استخدام الحد الفعال للتصنيف (مع التعديلات الخاصة بالعميل)
        $allowed_in_cat = $pkgCats[$cat_id] ?? 0;

        $stmt_cat_used = $pdo->prepare("
            SELECT COUNT(*)
            FROM daily_selections
            WHERE client_id=? AND category_id=? AND delivery_date >= ?
        ");
        $stmt_cat_used->execute([$client_id, $cat_id, $client_data['subscription_start_date']]);
        $used_in_cat = intval($stmt_cat_used->fetchColumn());

        $cat_remaining = max(0, $allowed_in_cat - $used_in_cat);
    }

    // حساب عدد الوجبات اليومية الفعال (مع التعديلات)
    $effective_meals_per_day = (!empty($client_data['meals_per_day_override']) && (int)$client_data['meals_per_day_override'] > 0) 
        ? (int)$client_data['meals_per_day_override'] 
        : (int)($client_data['meals_per_day'] ?? 0);
    
    return [
        'grand_remaining' => $grand_remaining,
        'cat_remaining'   => $cat_remaining,
        'day_used'        => $day_used,
        'day_limit'       => $effective_meals_per_day
    ];
}

/* ===========================
   AJAX Engine (POST)
=========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    ini_set('display_errors', 0);

    try {
        $action = $_POST['ajax_action'] ?? '';
        $action_date = $_POST['date'] ?? '';
        if (!$action_date) throw new Exception("تاريخ العملية مفقود.");

        $stmt_client = $pdo->prepare("
            SELECT cd.*, p.meals_per_day, p.id AS pkg_id, p.allowed_weight, p.duration_days, 
                   cd.meals_credit_extra, cd.meals_per_day_override
            FROM client_details cd
            JOIN packages p ON cd.package_id = p.id
            WHERE cd.user_id = ?
        ");
        $stmt_client->execute([$client_id]);
        $client_data = $stmt_client->fetch(PDO::FETCH_ASSOC);
        if (!$client_data) throw new Exception("لم يتم العثور على اشتراك نشط لهذا الحساب.");
        
        // حساب عدد الوجبات اليومية الفعال (مع التعديلات)
        $effective_meals_per_day = (!empty($client_data['meals_per_day_override']) && (int)$client_data['meals_per_day_override'] > 0) 
            ? (int)$client_data['meals_per_day_override'] 
            : (int)($client_data['meals_per_day'] ?? 0);
        $client_data['meals_per_day'] = $effective_meals_per_day;

        $allowed_weight = safeFloat($client_data['allowed_weight'] ?? 0);

        // Grace Days from settings (fallback 30)
        $graceDays = 30;
        try {
            $graceVal = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='grace_period_days'")->fetchColumn();
            if (is_numeric($graceVal)) $graceDays = max(0, (int)$graceVal);
        } catch (Exception $e) {}

        // Subscription dates
        $subscription_start_dt = $client_data['subscription_start_date'] ?? null;
        if (empty($subscription_start_dt)) throw new Exception("تاريخ بداية الاشتراك غير موجود لهذا العميل.");

        $package_days = (int)($client_data['duration_days'] ?? 0);
        if ($package_days <= 0) $package_days = 30;

        $original_end_dt = (new DateTime($subscription_start_dt))->modify('+' . $package_days . ' days');
        $grace_end_dt = (clone $original_end_dt)->modify('+' . $graceDays . ' days');

        $requested_dt = new DateTime($action_date);
        if ($requested_dt > $grace_end_dt) {
            throw new Exception("تجاوزت فترة الإمهال ({$graceDays} يوم بعد نهاية الباقة). الرجاء تجديد الاشتراك.");
        }

        // Kitchen lock
        $stmt_lock = $pdo->prepare("SELECT status FROM delivery_log WHERE client_id=? AND delivery_date=?");
        $stmt_lock->execute([$client_id, $action_date]);
        $kitchen_status = $stmt_lock->fetchColumn();
        $LOCKED_STATUSES = ['prepared', 'out_for_delivery', 'delivered'];
        if (in_array($kitchen_status, $LOCKED_STATUSES, true)) {
            throw new Exception("لا يمكن التعديل: الطلب دخل مرحلة التنفيذ في المطبخ.");
        }

        $hasIsActive = columnExists($pdo, 'global_options', 'is_active');

        if ($action === 'add_meal') {
            $meal_id = intval($_POST['meal_id'] ?? 0);
            $cat_id  = intval($_POST['cat_id'] ?? 0);
            if ($meal_id <= 0 || $cat_id <= 0) throw new Exception("بيانات غير صحيحة: رقم الوجبة/القسم.");

            $stmt_exists = $pdo->prepare("SELECT COUNT(*) FROM daily_selections WHERE client_id=? AND delivery_date=? AND meal_id=?");
            $stmt_exists->execute([$client_id, $action_date, $meal_id]);
            $already_same = intval($stmt_exists->fetchColumn()) > 0;

            $stmt_day_count = $pdo->prepare("SELECT COUNT(*) FROM daily_selections WHERE client_id=? AND delivery_date=?");
            $stmt_day_count->execute([$client_id, $action_date]);
            $day_count = intval($stmt_day_count->fetchColumn());
            if (!$already_same && $day_count >= intval($client_data['meals_per_day'])) {
                throw new Exception("وصلت للحد اليومي المسموح (" . intval($client_data['meals_per_day']) . " وجبات).");
            }

            // ✅ جلب الحد الفعال (مع التعديلات الخاصة بالعميل)
            $stmt_pkg_cat = $pdo->prepare("SELECT COALESCE(allowed_count,0) FROM package_category_limits WHERE package_id=? AND category_id=?");
            $stmt_pkg_cat->execute([intval($client_data['pkg_id']), $cat_id]);
            $allowed_in_cat = intval($stmt_pkg_cat->fetchColumn());
            
            // التحقق من التعديلات الخاصة بالعميل
            $stmt_client_cat = $pdo->prepare("SELECT allowed_count FROM client_category_limits WHERE user_id=? AND category_id=?");
            $stmt_client_cat->execute([$client_id, $cat_id]);
            $client_override = $stmt_client_cat->fetchColumn();
            if ($client_override !== false) {
                $allowed_in_cat = intval($client_override);
            }
            
            if ($allowed_in_cat <= 0) throw new Exception("هذا القسم غير متاح ضمن باقتك.");

            $stmt_cat_used = $pdo->prepare("
                SELECT COUNT(*)
                FROM daily_selections
                WHERE client_id=? AND category_id=? AND delivery_date >= ?
            ");
            $stmt_cat_used->execute([$client_id, $cat_id, $client_data['subscription_start_date']]);
            $used_in_cat = intval($stmt_cat_used->fetchColumn());
            $remaining_in_cat = $allowed_in_cat - $used_in_cat;

            if (!$already_same && $remaining_in_cat <= 0) {
                throw new Exception("رصيد هذا القسم انتهى. اختر من قسم آخر.");
            }

            // Option
            $option_id = intval($_POST['option_id'] ?? 0);
            $option_name = "بدون إضافات";
            $optServingLabel = '';
            $optMacros = ['calories'=>0,'protein'=>0,'carbs'=>0,'fat'=>0,'serving'=>0,'unit'=>''];

            if ($option_id > 0) {
                $q = "SELECT * FROM global_options WHERE id=?";
                if ($hasIsActive) $q .= " AND is_active=1";
                $stmt_o = $pdo->prepare($q);
                $stmt_o->execute([$option_id]);
                $optRow = $stmt_o->fetch(PDO::FETCH_ASSOC);

                if ($optRow) {
                    $option_name = trim((string)($optRow['name'] ?? 'بدون إضافات'));
                    $contr = computeOptionContribution($optRow, $allowed_weight);

                    $optMacros = [
                        'calories' => $contr['calories'],
                        'protein'  => $contr['protein'],
                        'carbs'    => $contr['carbs'],
                        'fat'      => $contr['fat'],
                        'serving'  => $contr['serving'],
                        'unit'     => $contr['unit'],
                    ];

                    if ($contr['serving'] > 0) {
                        $optServingLabel = ' [' . fmtNum($contr['serving'], 2) . unitShort($contr['unit'] ?? '') . ']';
                    }
                } else {
                    $option_id = 0;
                    $option_name = "بدون إضافات";
                }
            }

            $receive_type = ($_POST['receive_type'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery';
            $branch_name  = trim($_POST['branch_name'] ?? '');
            if ($receive_type === 'delivery') $branch_name = '';

            $saveOptionText = $option_name;
            if ($option_id > 0) $saveOptionText .= $optServingLabel;

            $stmt_replace = $pdo->prepare("
                REPLACE INTO daily_selections
                (client_id, delivery_date, meal_id, category_id, selected_weight, selected_option, receive_type, branch_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ok = $stmt_replace->execute([
                $client_id,
                $action_date,
                $meal_id,
                $cat_id,
                (string)$allowed_weight,
                $saveOptionText,
                $receive_type,
                $branch_name
            ]);
            if (!$ok) throw new Exception("تعذر حفظ الاختيار. حاول مرة أخرى.");

            $base = getMealBaseMacros($pdo, $meal_id, $allowed_weight);
            $total = sumMacros($base, $optMacros);

            $sync = computeSync($pdo, $client_id, $client_data, $cat_id, $action_date);

            echo json_encode([
                'status' => 'success',
                'message' => 'تم الحفظ بنجاح.',
                'sync' => array_merge($sync, [
                    'selected' => true,
                    'meal_id' => $meal_id,
                    'cat_id'  => $cat_id,
                    'selected_option' => $option_name,
                    'opt_serving' => $optMacros['serving'],
                    'opt_unit' => $optMacros['unit'],
                    'receive_type' => $receive_type,
                    'branch_name' => $branch_name,
                    'base' => $base,
                    'total' => $total
                ])
            ]);
            exit;
        }

        if ($action === 'remove_meal') {
            $meal_id = intval($_POST['meal_id'] ?? 0);
            $cat_id  = intval($_POST['cat_id'] ?? 0);
            if ($meal_id <= 0) throw new Exception("رقم الوجبة غير صحيح.");

            $stmt_del = $pdo->prepare("
                DELETE FROM daily_selections
                WHERE client_id=? AND delivery_date=? AND meal_id=?
            ");
            $stmt_del->execute([$client_id, $action_date, $meal_id]);
            if ($stmt_del->rowCount() <= 0) throw new Exception("الوجبة غير موجودة أو تم حذفها مسبقاً.");

            $sync = computeSync($pdo, $client_id, $client_data, $cat_id, $action_date);

            $allowed_weight = safeFloat($client_data['allowed_weight'] ?? 0);
            $base = getMealBaseMacros($pdo, $meal_id, $allowed_weight);

            echo json_encode([
                'status' => 'success',
                'message' => 'تم الحذف بنجاح.',
                'sync' => array_merge($sync, [
                    'selected' => false,
                    'meal_id' => $meal_id,
                    'cat_id'  => $cat_id,
                    'base' => $base
                ])
            ]);
            exit;
        }

        throw new Exception("طلب غير معروف.");

    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        exit;
    }
}

/* ===========================
   UI Prep (GET)
=========================== */

// settings map (for grace label + days)
$settings_map = [];
try {
    $settings_map = $pdo->query("SELECT setting_key, setting_value FROM system_settings")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { $settings_map = []; }

$cutoff_config_val = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'daily_cutoff_time'")
    ->fetchColumn() ?: '20:00';

$graceDays = 30;
if (!empty($settings_map['grace_period_days']) && is_numeric($settings_map['grace_period_days'])) {
    $graceDays = max(0, (int)$settings_map['grace_period_days']);
}

// package + start date + client overrides
$stmt_pkg = $pdo->prepare("
    SELECT p.*, cd.subscription_start_date, cd.subscription_end_date, 
           cd.meals_credit_extra, cd.duration_days_override, cd.meals_per_day_override
    FROM client_details cd
    JOIN packages p ON cd.package_id = p.id
    WHERE cd.user_id = ?
");
$stmt_pkg->execute([$client_id]);
$current_pkg_ui = $stmt_pkg->fetch(PDO::FETCH_ASSOC);
if (!$current_pkg_ui) { die("لا يوجد اشتراك نشط."); }

// حساب عدد الوجبات اليومية الفعال (مع التعديلات)
$effective_meals_per_day_ui = (!empty($current_pkg_ui['meals_per_day_override']) && (int)$current_pkg_ui['meals_per_day_override'] > 0) 
    ? (int)$current_pkg_ui['meals_per_day_override'] 
    : (int)($current_pkg_ui['meals_per_day'] ?? 0);
$current_pkg_ui['meals_per_day'] = $effective_meals_per_day_ui;

$allowed_weight_ui = safeFloat($current_pkg_ui['allowed_weight'] ?? 0);

// Date strip logic (7 days), respects cutoff
$hour_now = date('H:i');
$day_start_offset = ($hour_now >= $cutoff_config_val) ? 1 : 0;

$ui_navigation_dates = [];
for ($i=0; $i<7; $i++) {
    $offsetDays = $day_start_offset + $i;
    $dt = date('Y-m-d', strtotime("+{$offsetDays} day"));
    $ui_navigation_dates[] = [
        'full' => $dt,
        'day'  => date('l', strtotime($dt)),
        'num'  => date('d', strtotime($dt))
    ];
}

// ✅ IMPORTANT FIX: define selected_date NOW (before guard)
$selected_date = $_GET['date'] ?? $ui_navigation_dates[0]['full'];

// ---- Subscription + Grace (for UI label)
$sub_ui = [
  'status' => 'inactive',
  'label'  => 'غير نشط',
  'target_label' => 'متبقي على نهاية الاشتراك',
  'days' => 0,
  'end_date' => null,
  'grace_end_date' => null,
];

$subStart = $current_pkg_ui['subscription_start_date'] ?? null;
$pkgDays  = (int)($current_pkg_ui['duration_days'] ?? 0);
if ($pkgDays <= 0) $pkgDays = 30;

$endDt = null;
$graceEndDt = null;

try {
    if ($subStart) {
        $now = new DateTime();

        if (!empty($current_pkg_ui['subscription_end_date'])) {
            $endDt = new DateTime($current_pkg_ui['subscription_end_date']);
        } else {
            $endDt = (new DateTime($subStart))->modify("+{$pkgDays} days");
        }

        $graceEndDt = (clone $endDt)->modify("+{$graceDays} days");

        $sub_ui['end_date'] = $endDt->format('Y-m-d');
        $sub_ui['grace_end_date'] = $graceEndDt->format('Y-m-d');

        if ($now <= $endDt) {
            $sub_ui['status'] = 'active';
            $sub_ui['label']  = 'نشط';
            $sub_ui['target_label'] = 'متبقي على نهاية الاشتراك';
            $sub_ui['days'] = max(0, (int)$now->diff($endDt)->days);
        } elseif ($now <= $graceEndDt) {
            $sub_ui['status'] = 'grace';
            $sub_ui['label']  = 'إمهال';
            $sub_ui['target_label'] = 'متبقي على نهاية الإمهال';
            $sub_ui['days'] = max(0, (int)$now->diff($graceEndDt)->days);
        } else {
            $sub_ui['status'] = 'expired';
            $sub_ui['label']  = 'منتهي';
            $sub_ui['target_label'] = 'الاشتراك منتهي';
            $sub_ui['days'] = 0;
        }
    }
} catch (Exception $e) {}

// ---- branches
try {
    $active_branches_ui = $pdo->query("SELECT id, name FROM branches")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $active_branches_ui = [['id'=>0,'name'=>'الفرع الرئيسي - الرياض']];
}

// ---- picks (selected date)
$stmt_picks = $pdo->prepare("SELECT * FROM daily_selections WHERE client_id=? AND delivery_date=?");
$stmt_picks->execute([$client_id, $selected_date]);
$picks_rows = $stmt_picks->fetchAll(PDO::FETCH_ASSOC);

$current_picks_map_ui = [];
$picked_cat_today = [];
foreach ($picks_rows as $r) {
    $mid = (int)($r['meal_id'] ?? 0);
    $cid = (int)($r['category_id'] ?? 0);
    if ($mid > 0) $current_picks_map_ui[$mid] = $r;
    if ($cid > 0) $picked_cat_today[$cid] = true;
}

// ---- Locking
$is_view_locked_ui = false;
$lock_reason = ''; // 'kitchen' | 'grace_expired'

// kitchen lock (existing)
$stmt_lock_ui = $pdo->prepare("SELECT status FROM delivery_log WHERE client_id=? AND delivery_date=?");
$stmt_lock_ui->execute([$client_id, $selected_date]);
$isKitchenLocked = in_array($stmt_lock_ui->fetchColumn(), ['prepared','out_for_delivery','delivered'], true);
if ($isKitchenLocked) {
    $is_view_locked_ui = true;
    $lock_reason = 'kitchen';
}

// ✅ grace lock (view guard)
if (!$is_view_locked_ui && $subStart && $graceEndDt instanceof DateTime) {
    $selDt = new DateTime($selected_date);
    if ($selDt > $graceEndDt) {
        $is_view_locked_ui = true;
        $lock_reason = 'grace_expired';
    }
}

// ✅ Grand remaining - تطبيق التعديلات الخاصة بالعميل
// 1. جلب الحدود الأساسية من الباقة
$stmt_pkg_cats = $pdo->prepare("SELECT category_id, allowed_count FROM package_category_limits WHERE package_id=?");
$stmt_pkg_cats->execute([(int)$current_pkg_ui['id']]);
$pkg_cats_map = $stmt_pkg_cats->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

// 2. تطبيق التعديلات الخاصة بالعميل
$stmt_client_cats = $pdo->prepare("SELECT category_id, allowed_count FROM client_category_limits WHERE user_id=?");
$stmt_client_cats->execute([$client_id]);
$client_cats_map = $stmt_client_cats->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

// دمج: التعديلات الخاصة بالعميل تحل محل أو تضيف
foreach ($client_cats_map as $cid => $cnt) {
    $pkg_cats_map[$cid] = (int)$cnt;
}

// 3. حساب الإجمالي
$total_all_allowed_ui = 0;
foreach ($pkg_cats_map as $cnt) {
    $total_all_allowed_ui += max(0, (int)$cnt);
}

// 4. إضافة meals_credit_extra
$meals_credit_extra_ui = intval($current_pkg_ui['meals_credit_extra'] ?? 0);
$total_all_allowed_ui += max(0, $meals_credit_extra_ui);

$stmt_sum_used = $pdo->prepare("SELECT COUNT(*) FROM daily_selections WHERE client_id=? AND delivery_date >= ?");
$stmt_sum_used->execute([$client_id, $current_pkg_ui['subscription_start_date']]);
$grand_remaining_balance_ui = max(0, $total_all_allowed_ui - (int)$stmt_sum_used->fetchColumn());

// Category limits (الحدود الفعالة بعد التعديلات)
$cat_limits_map_ui = $pkg_cats_map;

/* Options categories for sheet tabs */
$has_option_categories = tableExists($pdo, 'option_categories');
$has_go_cat_id = columnExists($pdo, 'global_options', 'category_id');
$has_go_active = columnExists($pdo, 'global_options', 'is_active');

$optionCats = []; // [id=>name]
if ($has_option_categories) {
    $q = "SELECT id, name FROM option_categories WHERE is_active=1 ORDER BY sort_order ASC, id ASC";
    $rows = $pdo->query($q)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $c) $optionCats[(int)$c['id']] = $c['name'];
} elseif ($has_go_cat_id) {
    $q = "SELECT DISTINCT category_id FROM global_options WHERE category_id IS NOT NULL AND category_id > 0";
    if ($has_go_active) $q .= " AND is_active=1";
    $rows = $pdo->query($q)->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $cid) $optionCats[(int)$cid] = "تصنيف " . (int)$cid;
}

// categories list (meals)
$final_rendered_menu = [];
foreach ($cat_limits_map_ui as $cid_key => $allowed_count) {
    $cid_key = (int)$cid_key;
    $allowed_count = (int)$allowed_count;

    $stmt_used = $pdo->prepare("
        SELECT COUNT(*) FROM daily_selections
        WHERE client_id=? AND category_id=? AND delivery_date >= ?
    ");
    $stmt_used->execute([$client_id, $cid_key, $current_pkg_ui['subscription_start_date']]);
    $consumed = (int)$stmt_used->fetchColumn();
    $remaining = $allowed_count - $consumed;

    if ($remaining > 0 || isset($picked_cat_today[$cid_key])) {

        $stmt_cn = $pdo->prepare("SELECT name FROM categories WHERE id=?");
        $stmt_cn->execute([$cid_key]);
        $cat_name = $stmt_cn->fetchColumn() ?: 'قسم';

        $stmt_products = $pdo->prepare("SELECT * FROM products WHERE category_id=?");
        $stmt_products->execute([$cid_key]);
        $products = $stmt_products->fetchAll(PDO::FETCH_ASSOC);

        foreach ($products as &$prod) {
            $pid = (int)$prod['id'];

            $base = getMealBaseMacros($pdo, $pid, $allowed_weight_ui);
            $prod['_base_macros'] = $base;

            $stmt_opts = $pdo->prepare("
                SELECT go.*
                FROM product_options po
                JOIN global_options go ON po.global_option_id = go.id
                WHERE po.product_id = ?
            ");
            $stmt_opts->execute([$pid]);
            $opts = $stmt_opts->fetchAll(PDO::FETCH_ASSOC);

            $optPayload = [];
            foreach ($opts as $o) {
                if ($has_go_active && isset($o['is_active']) && (int)$o['is_active'] !== 1) continue;

                $contr = computeOptionContribution($o, $allowed_weight_ui);

                $optPayload[] = [
                    'id' => (int)$o['id'],
                    'name' => (string)($o['name'] ?? ''),
                    'unit' => unitShort((string)($o['unit'] ?? '')),
                    'category_id' => $has_go_cat_id ? (int)($o['category_id'] ?? 0) : 0,
                    'serving' => (float)($contr['serving'] ?? 0),
                    'contr' => [
                        'calories' => (float)($contr['calories'] ?? 0),
                        'protein'  => (float)($contr['protein'] ?? 0),
                        'carbs'    => (float)($contr['carbs'] ?? 0),
                        'fat'      => (float)($contr['fat'] ?? 0),
                    ]
                ];
            }

            $prod['_options_payload'] = $optPayload;

            $tot = $base;
            $pick = $current_picks_map_ui[$pid] ?? null;
            if ($pick) {
                $selected_opt_name = trim((string)($pick['selected_option'] ?? ''));
                if ($selected_opt_name && $selected_opt_name !== 'بدون إضافات') {
                    foreach ($optPayload as $op) {
                        if ($op['id'] > 0 && $op['name'] && mb_stripos($selected_opt_name, $op['name']) !== false) {
                            $tot = sumMacros($base, [
                                'calories'=>$op['contr']['calories'],
                                'protein'=>$op['contr']['protein'],
                                'carbs'=>$op['contr']['carbs'],
                                'fat'=>$op['contr']['fat'],
                            ]);
                            break;
                        }
                    }
                }
            }

            $prod['_total_macros'] = [
                'calories' => safeFloat($tot['calories'] ?? 0),
                'protein' => safeFloat($tot['protein'] ?? 0),
                'carbs' => safeFloat($tot['carbs'] ?? 0),
                'fat' => safeFloat($tot['fat'] ?? 0),
            ];
        }

        $final_rendered_menu[$cid_key] = [
            'name'  => $cat_name,
            'items' => $products,
            'rem'   => max(0, $remaining)
        ];
    }
}

$arabic_days = [
    'Saturday'=>'السبت','Sunday'=>'الأحد','Monday'=>'الاثنين',
    'Tuesday'=>'الثلاثاء','Wednesday'=>'الأربعاء','Thursday'=>'الخميس','Friday'=>'الجمعة'
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
  <title>اختيار الوجبات</title>

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <script>
    (function(){
      const saved = (localStorage.getItem('theme') || 'light').toLowerCase();
      const theme = (saved === 'dark') ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme', theme);
      document.addEventListener('DOMContentLoaded', () => {
        document.body.setAttribute('data-theme', theme);
      });
    })();
  </script>

  <style>
    /* === CSS unchanged (your exact CSS) === */
    :root{
      --primary:#6c5ce7;
      --primary2:#4b3cff;
      --bg:#f6f7ff;
      --surface:#ffffff;
      --text:#0f172a;
      --muted:#64748b;
      --stroke: rgba(15,23,42,.08);
      --soft: rgba(15,23,42,.04);
      --shadow: 0 14px 40px rgba(15,23,42,.08);

      --ok:#10b981;
      --danger:#ef4444;
    }
    body[data-theme="dark"]{
      --bg:#0b1220;
      --surface:#101a2e;
      --text:#f8fafc;
      --muted:#9aa4b2;
      --stroke: rgba(148,163,184,.14);
      --soft: rgba(148,163,184,.06);
      --shadow: 0 18px 50px rgba(0,0,0,.35);
    }
    *{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
    body{
      margin:0;
      background:var(--bg);
      font-family:'Tajawal',sans-serif;
      color:var(--text);
      padding-bottom: 118px;
      overflow-x:hidden;
    }
    .swal2-container{ z-index: 250000 !important; }
    .topbar{
      position: sticky; top:0; z-index: 7000;
      padding: 16px 16px 12px;
      background: linear-gradient(180deg, var(--bg), rgba(0,0,0,0));
      backdrop-filter: blur(10px);
    }
    .cutoffCard{
      background: var(--surface);
      border: 1px solid var(--stroke);
      border-radius: 18px;
      box-shadow: var(--shadow);
      padding: 12px 12px;
      display:flex; align-items:center; justify-content:space-between; gap:10px;
    }
    .cutoffLeft{ display:flex; align-items:center; gap:10px; font-weight: 900; }
    .timer{
      font-family: ui-monospace, Menlo, Consolas, monospace;
      font-weight: 900;
      background: rgba(108,92,231,.14);
      color: var(--primary);
      padding: 8px 12px;
      border-radius: 14px;
      border: 1px solid rgba(108,92,231,.20);
      min-width: 108px;
      text-align:center;
    }
    .hero{
      margin-top: 12px;
      background: var(--surface);
      border: 1px solid var(--stroke);
      border-radius: 24px;
      box-shadow: var(--shadow);
      padding: 14px 14px;
      display:flex; align-items:center; justify-content:space-between; gap:10px;
      overflow:hidden;
      position:relative;
    }
    .hero:before{
      content:"";
      position:absolute; inset:-2px;
      background: radial-gradient(650px 220px at 100% -10%, rgba(108,92,231,.22), transparent 55%);
      pointer-events:none;
    }
    .hero small{ color: var(--muted); font-weight: 900; display:block; margin-bottom:6px; }
    .hero b{ font-size: 1.6rem; font-weight: 900; color: var(--primary); }
    .hero b span{ font-size: .95rem; color: var(--muted); font-weight: 900; }
    .heroIcon{
      width:46px;height:46px;border-radius:16px;
      background: rgba(108,92,231,.12);
      border:1px solid rgba(108,92,231,.18);
      display:flex;align-items:center;justify-content:center;
      color:var(--primary);
      position:relative; z-index:1;
    }
    .dates{
      display:flex; gap:10px;
      overflow-x:auto;
      padding: 12px 2px 2px;
      scrollbar-width:none;
    }
    .dates::-webkit-scrollbar{ display:none; }
    .datePill{
      min-width: 78px;
      padding: 11px 10px;
      border-radius: 18px;
      border: 1px solid var(--stroke);
      background: var(--surface);
      box-shadow: var(--shadow);
      text-decoration:none;
      color: var(--muted);
      text-align:center;
      transition:.2s;
    }
    .datePill b{ display:block; color: var(--text); font-weight: 900; font-size: 1.02rem; }
    .datePill small{ font-weight: 900; font-size: .78rem; }
    .datePill.active{
      background: linear-gradient(135deg, var(--primary), var(--primary2));
      color:#fff;
      border-color: rgba(255,255,255,.10);
      transform: translateY(-1px);
    }
    .datePill.active b{ color:#fff; }
    .catHead{
      margin: 18px 16px 10px;
      display:flex; justify-content:space-between; align-items:center; gap:10px;
    }
    .catTitle{
      display:flex; align-items:center; gap:10px;
      font-weight: 900;
      font-size: 1.03rem;
    }
    .catRem{
      padding: 7px 12px;
      border-radius: 999px;
      border: 1px solid rgba(108,92,231,.18);
      background: rgba(108,92,231,.12);
      color: var(--primary);
      font-weight: 900;
      font-size: .9rem;
      white-space:nowrap;
    }
    .mealCard{
      margin: 12px 16px;
      background: var(--surface);
      border: 1px solid var(--stroke);
      border-radius: 26px;
      box-shadow: var(--shadow);
      overflow:hidden;
      position:relative;
    }
    .mealCard:before{
      content:"";
      position:absolute; inset:0;
      background:
        radial-gradient(700px 240px at 100% -10%, rgba(108,92,231,.18), transparent 55%),
        radial-gradient(600px 220px at 0% 110%, rgba(16,185,129,.10), transparent 55%);
      pointer-events:none;
    }
    .mealTop{
      position:relative; z-index:1;
      display:flex; gap:12px;
      padding: 14px 14px 10px;
      align-items:center;
    }
    .mImg{
      width: 74px; height: 74px;
      border-radius: 18px;
      object-fit:cover;
      flex:0 0 auto;
      box-shadow: 0 14px 26px rgba(0,0,0,.18);
      border: 1px solid rgba(255,255,255,.10);
      background: var(--soft);
    }
    .mInfo{ flex:1; min-width:0; }
    .mName{
      margin:0 0 6px 0;
      font-weight: 900;
      font-size: 1.03rem;
      white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    }
    .mHint{
      color: var(--muted);
      font-weight: 900;
      font-size: .86rem;
      white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    }
    .mAction{
      width: 46px; height: 46px;
      border-radius: 16px;
      border: 1px solid var(--stroke);
      cursor:pointer;
      display:flex; align-items:center; justify-content:center;
      font-size: 1.15rem;
      transition:.2s;
      position:relative; z-index:2;
      background: var(--soft);
      color: var(--text);
    }
    .mAction:hover{ transform: scale(1.03); }
    .btnAdd{
      background: rgba(108,92,231,.12);
      border-color: rgba(108,92,231,.22);
      color: var(--primary);
    }
    .btnRemove{
      background: rgba(16,185,129,.14);
      border-color: rgba(16,185,129,.22);
      color: var(--ok);
    }
    .nutriStrip{
      position:relative; z-index:1;
      margin: 0 14px 14px;
      border-radius: 18px;
      border: 1px solid var(--stroke);
      background: linear-gradient(135deg, rgba(108,92,231,.08), rgba(15,23,42,0));
      padding: 10px 10px;
      display:grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap:10px;
    }
    .nutriItem{
      border-radius: 14px;
      border: 1px solid var(--stroke);
      background: var(--surface);
      padding: 10px 10px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      min-width:0;
    }
    .nutriKey{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:44px;
      padding: 7px 10px;
      border-radius: 999px;
      border: 1px solid rgba(108,92,231,.20);
      background: rgba(108,92,231,.10);
      color: var(--primary);
      font-weight: 1000;
      letter-spacing: .8px;
      font-size: .78rem;
      flex:0 0 auto;
    }
    .nutriVal{
      text-align:left;
      font-weight: 1000;
      font-size: .98rem;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
      color: var(--text);
      direction:ltr;
    }
    .ribbon{
      position:relative; z-index:1;
      margin: 0 14px 14px;
      border-radius: 18px;
      padding: 10px 12px;
      border: 1px solid rgba(108,92,231,.20);
      background: linear-gradient(135deg, rgba(108,92,231,.16), rgba(16,185,129,.08));
      display:flex; align-items:center; justify-content:space-between; gap:10px;
      overflow:hidden;
    }
    .ribLeft{ display:flex; align-items:center; gap:10px; font-weight: 900; min-width:0; }
    .ribBadge{
      padding: 6px 10px;
      border-radius: 999px;
      background: rgba(255,255,255,.70);
      border: 1px solid rgba(255,255,255,.30);
      font-size: .82rem;
      white-space:nowrap;
    }
    body[data-theme="dark"] .ribBadge{
      background: rgba(15,23,42,.35);
      border-color: rgba(148,163,184,.18);
    }
    .ribText{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color: var(--text); font-size: .92rem; }
    .pickedBar{
      position:relative; z-index:1;
      margin: 0 14px 14px;
      border-radius: 18px;
      border: 1px solid rgba(16,185,129,.20);
      background: linear-gradient(135deg, rgba(16,185,129,.14), rgba(108,92,231,.08));
      padding: 12px 12px;
    }
    .pickedTitle{
      display:flex; align-items:center; gap:8px;
      font-weight: 900;
      color: var(--ok);
      margin-bottom: 10px;
      font-size: .95rem;
    }
    .pickedChips{ display:flex; flex-wrap:wrap; gap:10px; }
    .chip{
      display:inline-flex; align-items:center; gap:8px;
      padding: 9px 12px;
      border-radius: 999px;
      background: var(--surface);
      border: 1px solid var(--stroke);
      box-shadow: 0 12px 20px rgba(0,0,0,.06);
      font-weight: 900;
      font-size: .88rem;
      color: var(--text);
    }
    .lockedBox{
      margin: 50px 16px;
      background: var(--surface);
      box-shadow: var(--shadow);
      border-radius: 26px;
      border: 1px solid rgba(239,68,68,.18);
      padding: 26px 18px;
      text-align:center;
    }
    .lockedBox i{ color: var(--danger); font-size: 3rem; margin-bottom:12px; }
    .lockedBox h2{ margin: 0 0 8px; font-weight: 900; }
    .lockedBox p{ margin:0; color: var(--muted); font-weight: 900; line-height: 1.7; }
    .sheetOverlay{
      position: fixed; inset:0;
      background: rgba(0,0,0,.45);
      display:none;
      align-items:flex-end;
      justify-content:center;
      z-index: 12000;
      backdrop-filter: blur(10px);
    }
    .sheet{
      width:100%;
      max-width: 820px;
      background: var(--surface);
      border-radius: 28px 28px 0 0;
      padding: 14px 14px 16px;
      box-shadow: 0 -26px 60px rgba(0,0,0,.30);
      max-height: 92vh;
      overflow:auto;
      border: 1px solid var(--stroke);
      border-bottom:none;
    }
    .sheetTop{
      display:flex; align-items:center; justify-content:space-between; gap:10px;
      margin-bottom: 10px;
    }
    .sheetTitle{
      font-weight: 900;
      font-size: 1.05rem;
      white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
      max-width: 85%;
    }
    .sheetClose{
      width: 42px; height: 42px;
      border-radius: 14px;
      border: 1px solid var(--stroke);
      background: var(--soft);
      color: var(--text);
      cursor:pointer;
      display:flex; align-items:center; justify-content:center;
    }
    .secTitle{
      margin: 10px 2px 8px;
      font-weight: 900;
      color: var(--muted);
      font-size: .9rem;
      display:flex; align-items:center; gap:8px;
    }
    .tabs{
      display:flex; gap:10px;
      background: var(--soft);
      border: 1px solid var(--stroke);
      padding: 6px;
      border-radius: 18px;
    }
    .tab{
      flex:1;
      text-align:center;
      padding: 12px 10px;
      border-radius: 14px;
      font-weight: 900;
      cursor:pointer;
      color: var(--muted);
      user-select:none;
    }
    .tab.active{
      background: var(--surface);
      color: var(--primary);
      border: 1px solid rgba(108,92,231,.18);
      box-shadow: 0 12px 18px rgba(0,0,0,.06);
    }
    .branchBox{ display:none; margin-top: 8px; }
    .branchBox select{
      width: 100%;
      padding: 14px 12px;
      border-radius: 16px;
      border: 1px solid var(--stroke);
      font-weight: 900;
      outline:none;
      font-family:'Tajawal';
      background: var(--surface);
      color: var(--text);
    }
    .optCats{
      display:flex; gap:10px;
      overflow-x:auto;
      padding: 2px 2px 4px;
      scrollbar-width:none;
    }
    .optCats::-webkit-scrollbar{ display:none; }
    .optCat{
      padding: 10px 12px;
      border-radius: 999px;
      border: 1px solid var(--stroke);
      background: var(--surface);
      color: var(--muted);
      font-weight: 900;
      white-space:nowrap;
      cursor:pointer;
      transition:.15s;
    }
    .optCat.active{
      background: linear-gradient(135deg, rgba(108,92,231,.20), rgba(108,92,231,.08));
      border-color: rgba(108,92,231,.28);
      color: var(--primary);
    }
    .opts{
      display:flex;
      flex-direction:column;
      gap:10px;
      max-height: 340px;
      overflow:auto;
      padding-right: 4px;
    }
    .optRow{
      display:flex; align-items:center; gap:12px;
      padding: 12px;
      border-radius: 18px;
      border: 1px solid var(--stroke);
      background: var(--surface);
      cursor:pointer;
      transition:.15s;
    }
    .optRow:hover{ transform: translateY(-1px); box-shadow: 0 14px 20px rgba(0,0,0,.06); }
    .optRow.active{ border-color: rgba(108,92,231,.35); background: rgba(108,92,231,.06); }
    .optLeft{ flex:1; min-width:0; }
    .optLeft b{ display:block; font-weight:900; font-size: .98rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .optLeft small{ display:flex; gap:10px; flex-wrap:wrap; margin-top:6px; font-weight:900; color: var(--muted); }
    .pill{
      padding: 5px 10px;
      border-radius: 999px;
      border: 1px solid var(--stroke);
      background: var(--soft);
      font-size: .78rem;
      white-space:nowrap;
      direction:ltr;
    }
    .pill strong{ color: var(--primary); font-weight: 1000; }
    .optIcon{ color:#cbd5e1; font-size:1.15rem; }
    .optRow.active .optIcon{ color: var(--primary); }
    .liveBox{
      border-radius: 20px;
      border: 1px solid var(--stroke);
      background: linear-gradient(135deg, rgba(16,185,129,.10), rgba(108,92,231,.06));
      padding: 12px;
    }
    .liveTitle{
      display:flex; align-items:center; justify-content:space-between; gap:10px;
      font-weight: 900;
      margin-bottom:10px;
    }
    .liveTitle span{ color: var(--muted); font-size: .9rem; }
    .liveTitle b{ color: var(--primary); }
    .liveRow{
      display:grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 10px;
    }
    .liveMini{
      border-radius: 16px;
      border: 1px solid var(--stroke);
      background: var(--surface);
      padding: 10px;
      text-align:center;
      direction:ltr;
    }
    .liveMini small{ display:block; color: var(--muted); font-weight: 900; font-size:.72rem; }
    .liveMini b{ display:block; color: var(--text); font-weight: 1000; font-size: 1.0rem; margin-top:3px; }
    .confirmBtn{
      width: 100%;
      margin-top: 12px;
      padding: 14px 14px;
      border-radius: 18px;
      border:none;
      background: linear-gradient(135deg, var(--primary), var(--primary2));
      color:#fff;
      font-weight: 1000;
      font-size: 1.02rem;
      cursor:pointer;
      box-shadow: 0 18px 30px rgba(108,92,231,.25);
    }
    .cancelBtn{
      width: 100%;
      margin-top: 10px;
      padding: 13px 14px;
      border-radius: 18px;
      border: 1px solid var(--stroke);
      background: var(--soft);
      color: var(--text);
      font-weight: 1000;
      cursor:pointer;
    }
    @media (max-width:420px){
      .nutriStrip{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .liveRow{ grid-template-columns: repeat(2, minmax(0,1fr)); }
    }
  </style>
</head>

<body data-theme="light">

  <div class="topbar">
    <div class="cutoffCard">
      <div class="cutoffLeft">
        <i class="fas fa-clock"></i>
        <span>الإغلاق اليومي: <b><?= date("g:i A", strtotime($cutoff_config_val)) ?></b></span>
      </div>
      <div class="timer" id="uiCountdown">00:00:00</div>
    </div>

    <div style="font-weight:900;color:var(--muted);font-size:.86rem;white-space:nowrap;margin-top:10px;">
      <?= htmlspecialchars($sub_ui['target_label']) ?>:
      <b style="color:var(--text)"><?= (int)$sub_ui['days'] ?></b> يوم
    </div>

    <div class="hero">
      <div style="position:relative;z-index:1">
        <small>إجمالي رصيد وجباتك المتاح</small>
        <b id="uiGrandRemaining"><?= (int)$grand_remaining_balance_ui ?> <span>وجبة</span></b>
      </div>
      <div class="heroIcon"><i class="fas fa-bowl-food"></i></div>
    </div>

    <div class="dates">
      <?php foreach($ui_navigation_dates as $d):
        $active = ($d['full'] === $selected_date) ? 'active' : '';
      ?>
        <a class="datePill <?= $active ?>" href="?date=<?= $d['full'] ?>">
          <small><?= $arabic_days[$d['day']] ?? $d['day'] ?></small>
          <b><?= $d['num'] ?></b>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($is_view_locked_ui): ?>
    <div class="lockedBox">
      <i class="fas fa-lock"></i>

      <?php if ($lock_reason === 'grace_expired'): ?>
        <h2>انتهت فترة الإمهال</h2>
        <p>
          لا يمكن اختيار وجبات جديدة بعد نهاية الإمهال (<?= (int)$graceDays ?> يوم بعد نهاية الباقة).<br>
          الرجاء تجديد الاشتراك للمتابعة.
        </p>
        <div style="margin-top:14px;">
          <a href="client_browse_packages.php"
             style="display:inline-block;padding:12px 16px;border-radius:16px;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;font-weight:900;text-decoration:none;">
            تجديد الاشتراك
          </a>
        </div>
      <?php else: ?>
        <h2>تم قفل التعديل لهذا اليوم</h2>
        <p>الطلبات دخلت مرحلة التنفيذ، لا يمكن التعديل حالياً.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>

    <?php foreach ($final_rendered_menu as $cid => $cat): ?>
      <div class="catHead">
        <div class="catTitle">
          <i class="fas fa-utensils" style="color:var(--primary)"></i>
          <span><?= htmlspecialchars($cat['name']) ?></span>
        </div>
        <div class="catRem" data-cat-pill="<?= (int)$cid ?>">
          المتبقي: <span class="catRemValue"><?= (int)$cat['rem'] ?></span>
        </div>
      </div>

      <?php foreach ($cat['items'] as $prod):
        $mealId = (int)$prod['id'];
        $catId  = (int)$cid;
        $isSelected = isset($current_picks_map_ui[$mealId]);

        $base = $prod['_base_macros'] ?? ['calories'=>0,'protein'=>0,'carbs'=>0,'fat'=>0,'weight'=>$allowed_weight_ui,'unit'=>'gram'];
        $total = $prod['_total_macros'] ?? ['calories'=>0,'protein'=>0,'carbs'=>0,'fat'=>0];

        $optionsPayload = $prod['_options_payload'] ?? [];
        $optionsJson = json_encode($optionsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $baseJson = json_encode([
          'weight'=>$base['weight'] ?? $allowed_weight_ui,
          'unit'=>unitShort($base['unit'] ?? 'gram'),
          'calories'=>$base['calories'] ?? 0,
          'protein'=>$base['protein'] ?? 0,
          'carbs'=>$base['carbs'] ?? 0,
          'fat'=>$base['fat'] ?? 0,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $totalJson = json_encode($total, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $pick = $isSelected ? $current_picks_map_ui[$mealId] : null;
        $pickedOptText = trim((string)($pick['selected_option'] ?? 'بدون إضافات'));
        if ($pickedOptText === '') $pickedOptText = 'بدون إضافات';
        $pickedReceive = $pick['receive_type'] ?? 'delivery';
        $pickedBranch = trim((string)($pick['branch_name'] ?? ''));
      ?>
        <div class="mealCard"
             data-meal-id="<?= $mealId ?>"
             data-cat-id="<?= $catId ?>"
             data-meal-name="<?= htmlspecialchars($prod['name'], ENT_QUOTES, 'UTF-8') ?>"
             data-options='<?= htmlspecialchars($optionsJson, ENT_QUOTES, 'UTF-8') ?>'
             data-base='<?= htmlspecialchars($baseJson, ENT_QUOTES, 'UTF-8') ?>'
             data-total='<?= htmlspecialchars($totalJson, ENT_QUOTES, 'UTF-8') ?>'
             data-selected="<?= $isSelected ? '1' : '0' ?>'>

          <div class="mealTop">
            <img class="mImg"
                 src="uploads/<?= htmlspecialchars($prod['image'] ?? ($prod['image_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                 onerror="this.src='https://placehold.co/200x200'">

            <div class="mInfo">
              <div class="mName"><?= htmlspecialchars($prod['name']) ?></div>
              <div class="mHint">
                <?= $isSelected ? 'تم اختيار الوجبة لهذا اليوم' : 'اختر الوجبة ثم حدّد الإضافة والاستلام' ?>
              </div>
            </div>

            <?php if ($isSelected): ?>
              <button class="mAction btnRemove" onclick="handleOp(<?= $mealId ?>,'remove',<?= $catId ?>)" title="إزالة">
                <i class="fas fa-check"></i>
              </button>
            <?php else: ?>
              <button class="mAction btnAdd" onclick="openSheetFromCard(<?= $mealId ?>)" title="اختيار">
                <i class="fas fa-plus"></i>
              </button>
            <?php endif; ?>
          </div>

          <div class="nutriStrip" data-nutri>
            <?php
              $show = $isSelected ? $total : $base;
              $cal = safeFloat($show['calories'] ?? 0);
              $pro = safeFloat($show['protein'] ?? 0);
              $car = safeFloat($show['carbs'] ?? 0);
              $fat = safeFloat($show['fat'] ?? 0);
            ?>
            <div class="nutriItem">
              <span class="nutriKey">CAL</span>
              <div class="nutriVal"><span data-cal><?= fmtNum($cal, 0) ?></span></div>
            </div>
            <div class="nutriItem">
              <span class="nutriKey">P</span>
              <div class="nutriVal"><span data-pro><?= fmtNum($pro, 1) ?></span></div>
            </div>
            <div class="nutriItem">
              <span class="nutriKey">C</span>
              <div class="nutriVal"><span data-car><?= fmtNum($car, 1) ?></span></div>
            </div>
            <div class="nutriItem">
              <span class="nutriKey">F</span>
              <div class="nutriVal"><span data-fat><?= fmtNum($fat, 1) ?></span></div>
            </div>
          </div>

          <?php if ($isSelected): ?>
            <div class="pickedBar">
              <div class="pickedTitle"><i class="fas fa-circle-check"></i> تفاصيل اختيارك + الإجمالي النهائي</div>
              <div class="pickedChips">
                <span class="chip"><?= htmlspecialchars($pickedOptText) ?></span>
                <?php if ($pickedReceive === 'delivery'): ?>
                  <span class="chip">توصيل</span>
                <?php else: ?>
                  <span class="chip">استلام</span>
                  <?php if ($pickedBranch !== ''): ?>
                    <span class="chip"><?= htmlspecialchars($pickedBranch) ?></span>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          <?php else: ?>
            <div class="ribbon">
              <div class="ribLeft">
                <span class="ribBadge">وزن الباقة</span>
                <div class="ribText">
                  <?= fmtNum($base['weight'] ?? $allowed_weight_ui, 2) . unitShort($base['unit'] ?? 'gram') ?>
                </div>
              </div>
            </div>
          <?php endif; ?>

        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>

  <?php endif; ?>

  <!-- Bottom Sheet (unchanged) -->
  <div id="sheetOverlay" class="sheetOverlay" onclick="if(event.target===this)closeSheet()">
    <div class="sheet">
      <div class="sheetTop">
        <div class="sheetTitle" id="sheetTitle">اختيار</div>
        <div class="sheetClose" onclick="closeSheet()"><i class="fas fa-xmark"></i></div>
      </div>

      <div>
        <div class="secTitle"><i class="fas fa-truck-ramp-box"></i> طريقة الاستلام</div>
        <div class="tabs">
          <div class="tab active" id="tabDelivery" onclick="setReceive('delivery')">توصيل</div>
          <div class="tab" id="tabPickup" onclick="setReceive('pickup')">استلام</div>
        </div>

        <div class="branchBox" id="branchBox">
          <div class="secTitle"><i class="fas fa-location-dot"></i> اختر الفرع</div>
          <select id="branchSelect">
            <?php foreach($active_branches_ui as $b): ?>
              <option value="<?= htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="liveBox" style="margin-top:12px;">
        <div class="liveTitle">
          <div style="display:flex;align-items:center;gap:8px;">
            <i class="fas fa-chart-simple" style="color:var(--primary)"></i>
            <b>الإجمالي النهائي</b>
            <span id="liveHint">الوجبة الأساسية</span>
          </div>
          <span id="liveServing"></span>
        </div>
        <div class="liveRow">
          <div class="liveMini"><small>CAL</small><b id="liveCal">0</b></div>
          <div class="liveMini"><small>P</small><b id="livePro">0</b></div>
          <div class="liveMini"><small>C</small><b id="liveCar">0</b></div>
          <div class="liveMini"><small>F</small><b id="liveFat">0</b></div>
        </div>
      </div>

      <div style="margin-top:12px;">
        <div class="secTitle"><i class="fas fa-layer-group"></i> تصنيفات الإضافات</div>
        <div class="optCats" id="optCats"></div>

        <div class="secTitle"><i class="fas fa-list-check"></i> الإضافات</div>
        <div class="opts" id="optsBox"></div>

        <button class="confirmBtn" onclick="handleOp(0,'add')">تأكيد وحفظ</button>
        <button class="cancelBtn" onclick="closeSheet()">إلغاء</button>
      </div>

      <input type="hidden" id="hMeal">
      <input type="hidden" id="hCat">
      <input type="hidden" id="hReceive" value="delivery">
      <input type="hidden" id="hOpt" value="0">

      <input type="hidden" id="hBaseJson">
      <input type="hidden" id="hOptsJson">
      <input type="hidden" id="hOptCatsJson" value='<?= htmlspecialchars(json_encode($optionCats, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), ENT_QUOTES, "UTF-8") ?>'>
    </div>
  </div>

  <?php include 'client_footer_nav.php'; ?>

<script>
  const UI_DATE = "<?= $selected_date ?>";
  const UI_CUTOFF = "<?= $cutoff_config_val ?>";

  (function(){
    const saved = (localStorage.getItem('theme') || 'light').toLowerCase();
    document.body.setAttribute('data-theme', saved === 'dark' ? 'dark' : 'light');
  })();

  function escHtml(s){
    return String(s)
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'","&#039;");
  }
  function n(v){ v = parseFloat(v); return isNaN(v) ? 0 : v; }
  function fmt(v, dec=1){
    v = n(v);
    if (Math.abs(v - Math.round(v)) < 0.00001) return String(Math.round(v));
    return (v.toFixed(dec)).replace(/\.0+$/,'').replace(/(\.\d*[1-9])0+$/,'$1');
  }

  let SHEET_OPTS = [];
  let SHEET_BASE = {calories:0,protein:0,carbs:0,fat:0,weight:0,unit:''};
  let SHEET_CATS = {};
  let ACTIVE_CAT = 0;

  function openSheetFromCard(mealId){
    const card = document.querySelector(`.mealCard[data-meal-id="${mealId}"]`);
    if(!card) return;

    const mealName = card.getAttribute('data-meal-name') || '';
    const catId = card.getAttribute('data-cat-id') || '0';

    let opts = [];
    let base = {};
    try { opts = JSON.parse(card.getAttribute('data-options') || '[]'); } catch(e){ opts=[]; }
    try { base = JSON.parse(card.getAttribute('data-base') || '{}'); } catch(e){ base={}; }

    SHEET_OPTS = Array.isArray(opts) ? opts : [];
    SHEET_BASE = base || {};
    document.getElementById('hBaseJson').value = JSON.stringify(SHEET_BASE);
    document.getElementById('hOptsJson').value = JSON.stringify(SHEET_OPTS);

    try { SHEET_CATS = JSON.parse(document.getElementById('hOptCatsJson').value || '{}') || {}; } catch(e){ SHEET_CATS = {}; }

    document.getElementById('sheetTitle').innerText = mealName;
    document.getElementById('hMeal').value = String(mealId);
    document.getElementById('hCat').value = String(catId);

    setReceive('delivery');
    setOption('0');

    ACTIVE_CAT = 0;
    renderOptCats();
    renderOptionsByCat(ACTIVE_CAT);

    updateLiveTotals('0');

    $('#sheetOverlay').fadeIn(180).css('display','flex');
  }

  function closeSheet(){ $('#sheetOverlay').fadeOut(150); }

  function setReceive(mode){
    const m = (mode === 'pickup') ? 'pickup' : 'delivery';
    document.getElementById('hReceive').value = m;

    document.getElementById('tabDelivery').classList.remove('active');
    document.getElementById('tabPickup').classList.remove('active');

    if(m === 'delivery'){
      document.getElementById('tabDelivery').classList.add('active');
      $('#branchBox').slideUp(150);
    }else{
      document.getElementById('tabPickup').classList.add('active');
      $('#branchBox').slideDown(150);
    }
  }

  function renderOptCats(){
    const set = new Set();
    for(const o of SHEET_OPTS){
      const cid = parseInt(o.category_id || 0, 10) || 0;
      set.add(cid);
    }
    const catIds = Array.from(set).sort((a,b)=>a-b);

    let html = '';
    const realCats = catIds.filter(x => x > 0);
    const showAll = realCats.length > 1;

    if(showAll){
      html += `<div class="optCat ${ACTIVE_CAT===-1?'active':''}" onclick="setActiveCat(-1)">الكل</div>`;
    }

    for(const cid of realCats){
      const name = (SHEET_CATS && SHEET_CATS[cid]) ? SHEET_CATS[cid] : (`تصنيف ${cid}`);
      html += `<div class="optCat ${ACTIVE_CAT===cid?'active':''}" onclick="setActiveCat(${cid})">${escHtml(name)}</div>`;
    }

    if(!html){
      ACTIVE_CAT = 0;
      html = `<div class="optCat active" onclick="setActiveCat(0)">الإضافات</div>`;
    } else {
      if(ACTIVE_CAT === 0){
        ACTIVE_CAT = showAll ? -1 : (realCats[0] || 0);
      }
    }

    document.getElementById('optCats').innerHTML = html;
  }

  function setActiveCat(cid){
    ACTIVE_CAT = cid;
    document.querySelectorAll('#optCats .optCat').forEach(el=>el.classList.remove('active'));
    document.querySelectorAll('#optCats .optCat').forEach(el=>{
      const oc = el.getAttribute('onclick') || '';
      if (cid === -1 && el.textContent.trim() === 'الكل') el.classList.add('active');
      else if (oc === `setActiveCat(${cid})`) el.classList.add('active');
    });
    renderOptionsByCat(ACTIVE_CAT);
  }

  function renderOptionsByCat(catId){
    let html = '';
    html += `
      <div class="optRow active" data-opt="0" onclick="setOption('0')">
        <div class="optLeft"><b>بدون إضافات</b></div>
        <div class="optIcon"><i class="fas fa-check-circle"></i></div>
      </div>
    `;

    const list = [];
    for(const o of SHEET_OPTS){
      const cid = parseInt(o.category_id || 0, 10) || 0;
      if(catId === -1) list.push(o);
      else if(catId === 0) list.push(o);
      else if(cid === catId) list.push(o);
    }

    for(const o of list){
      const id = o.id ?? '';
      const name = o.name ?? '';
      const unit = o.unit ?? '';
      const serving = n(o.serving ?? 0);
      const contr = o.contr || {};
      const ccal = n(contr.calories);
      const cpro = n(contr.protein);
      const ccar = n(contr.carbs);
      const cfat = n(contr.fat);

      const servingTxt = serving > 0 ? `${fmt(serving,2)}${escHtml(unit)}` : '';

      html += `
        <div class="optRow" data-opt="${escHtml(id)}" onclick="setOption('${escHtml(id)}')">
          <div class="optLeft">
            <b>${escHtml(name)}</b>
            <small>
              ${servingTxt ? `<span class="pill"><strong>Serving</strong> ${servingTxt}</span>` : ``}
              <span class="pill"><strong>+CAL</strong> ${fmt(ccal,0)}</span>
              <span class="pill"><strong>+P</strong> ${fmt(cpro,1)}</span>
              <span class="pill"><strong>+C</strong> ${fmt(ccar,1)}</span>
              <span class="pill"><strong>+F</strong> ${fmt(cfat,1)}</span>
            </small>
          </div>
          <div class="optIcon"><i class="fas fa-check-circle"></i></div>
        </div>
      `;
    }

    document.getElementById('optsBox').innerHTML = html;
  }

  function setOption(optId){
    document.getElementById('hOpt').value = String(optId);
    document.querySelectorAll('.optRow').forEach(r => {
      r.classList.toggle('active', r.getAttribute('data-opt') === String(optId));
    });
    updateLiveTotals(optId);
  }

  function findOpt(optId){
    optId = String(optId);
    for(const o of SHEET_OPTS){
      if(String(o.id) === optId) return o;
    }
    return null;
  }

  function updateLiveTotals(optId){
    const base = SHEET_BASE || {};
    let cal = n(base.calories), pro = n(base.protein), car = n(base.carbs), fat = n(base.fat);

    let hint = 'الوجبة الأساسية';
    let servingText = '';

    if(String(optId) !== '0'){
      const o = findOpt(optId);
      if(o){
        const contr = o.contr || {};
        cal += n(contr.calories);
        pro += n(contr.protein);
        car += n(contr.carbs);
        fat += n(contr.fat);

        hint = 'الوجبة + الإضافة';
        const s = n(o.serving || 0);
        if(s > 0){
          servingText = `Serving: ${fmt(s,2)}${escHtml(o.unit || '')}`;
        }
      }
    }

    document.getElementById('liveHint').innerText = hint;
    document.getElementById('liveServing').innerText = servingText;

    document.getElementById('liveCal').innerText = fmt(cal,0);
    document.getElementById('livePro').innerText = fmt(pro,1);
    document.getElementById('liveCar').innerText = fmt(car,1);
    document.getElementById('liveFat').innerText = fmt(fat,1);
  }

  function updateCardNutrition(card, macros){
    if(!card || !macros) return;
    const cal = card.querySelector('[data-cal]');
    const pro = card.querySelector('[data-pro]');
    const car = card.querySelector('[data-car]');
    const fat = card.querySelector('[data-fat]');
    if(cal) cal.textContent = fmt(macros.calories,0);
    if(pro) pro.textContent = fmt(macros.protein,1);
    if(car) car.textContent = fmt(macros.carbs,1);
    if(fat) fat.textContent = fmt(macros.fat,1);
  }

  function renderPickedBar(opt, receive, branch){
    const chips = [];
    chips.push(`<span class="chip">${escHtml(opt || 'بدون إضافات')}</span>`);
    if(receive === 'pickup'){
      chips.push(`<span class="chip">استلام</span>`);
      if(branch) chips.push(`<span class="chip">${escHtml(branch)}</span>`);
    }else{
      chips.push(`<span class="chip">توصيل</span>`);
    }
    return `
      <div class="pickedBar">
        <div class="pickedTitle"><i class="fas fa-circle-check"></i> تفاصيل اختيارك + الإجمالي النهائي</div>
        <div class="pickedChips">${chips.join('')}</div>
      </div>
    `;
  }

  function handleOp(mealId, type, catForRemove=0){
    const isAdd = (type === 'add');

    const payload = {
      ajax_action: isAdd ? 'add_meal' : 'remove_meal',
      date: UI_DATE,
      meal_id: isAdd ? parseInt(document.getElementById('hMeal').value || '0',10) : mealId,
      cat_id: isAdd ? parseInt(document.getElementById('hCat').value || '0',10) : catForRemove,
      option_id: isAdd ? parseInt(document.getElementById('hOpt').value || '0',10) : 0,
      receive_type: isAdd ? (document.getElementById('hReceive').value || 'delivery') : '',
      branch_name: isAdd ? (document.getElementById('branchSelect').value || '') : ''
    };

    Swal.fire({
      title: 'جاري التحديث...',
      allowOutsideClick: false,
      didOpen: () => Swal.showLoading()
    });

    $.post('', payload, function(res){
      if(res && res.status === 'success'){
        applySync(res.sync);
        Swal.close();
        if(isAdd) closeSheet();
      }else{
        Swal.fire({
          icon:'error',
          title:'تنبيه',
          text: (res && res.msg) ? res.msg : 'حدث خطأ غير متوقع',
          confirmButtonText: 'حسنًا'
        });
      }
    }, 'json').fail(function(){
      Swal.fire({
        icon:'error',
        title:'فشل الاتصال',
        text:'تعذر تنفيذ الطلب. حاول مرة أخرى.',
        confirmButtonText:'حسنًا'
      });
    });
  }

  function applySync(sync){
    if(!sync) return;

    if(typeof sync.grand_remaining !== 'undefined'){
      document.getElementById('uiGrandRemaining').innerHTML =
        `${sync.grand_remaining} <span>وجبة</span>`;
    }
    if(sync.cat_id && sync.cat_remaining !== null && typeof sync.cat_remaining !== 'undefined'){
      const pill = document.querySelector(`[data-cat-pill="${sync.cat_id}"] .catRemValue`);
      if(pill) pill.textContent = String(sync.cat_remaining);
    }

    const card = document.querySelector(`.mealCard[data-meal-id="${sync.meal_id}"]`);
    if(!card) return;

    const btn = card.querySelector('button.mAction');
    const hint = card.querySelector('.mHint');

    if(sync.selected){
      card.setAttribute('data-selected','1');
      if(hint) hint.textContent = 'تم اختيار الوجبة لهذا اليوم';

      if(sync.total) card.setAttribute('data-total', JSON.stringify(sync.total));
      if(sync.base) card.setAttribute('data-base', JSON.stringify(sync.base));

      if(sync.total) updateCardNutrition(card, sync.total);

      const ribbon = card.querySelector('.ribbon');
      if(ribbon) ribbon.remove();

      const existingPicked = card.querySelector('.pickedBar');
      if(existingPicked) existingPicked.remove();
      card.insertAdjacentHTML('beforeend', renderPickedBar(sync.selected_option, sync.receive_type, sync.branch_name));

      btn.classList.remove('btnAdd');
      btn.classList.add('btnRemove');
      btn.innerHTML = `<i class="fas fa-check"></i>`;
      btn.setAttribute('onclick', `handleOp(${sync.meal_id},'remove',${sync.cat_id})`);
    } else {
      card.setAttribute('data-selected','0');
      if(hint) hint.textContent = 'اختر الوجبة ثم حدّد الإضافة والاستلام';

      const picked = card.querySelector('.pickedBar');
      if(picked) picked.remove();

      const hasRibbon = !!card.querySelector('.ribbon');
      if(!hasRibbon){
        let baseObj = {};
        try { baseObj = JSON.parse(card.getAttribute('data-base') || '{}'); } catch(e){ baseObj={}; }
        const w = baseObj.weight ?? '';
        const u = baseObj.unit ?? '';
        card.insertAdjacentHTML('beforeend', `
          <div class="ribbon">
            <div class="ribLeft">
              <span class="ribBadge">وزن الباقة</span>
              <div class="ribText">${escHtml(fmt(w,2))}${escHtml(u)}</div>
            </div>
          </div>
        `);
      }

      if(sync.base) updateCardNutrition(card, sync.base);

      btn.classList.remove('btnRemove');
      btn.classList.add('btnAdd');
      btn.innerHTML = `<i class="fas fa-plus"></i>`;
      btn.setAttribute('onclick', `openSheetFromCard(${sync.meal_id})`);
    }
  }

  setInterval(() => {
    const now = new Date();
    const target = new Date();
    const parts = String(UI_CUTOFF).split(':');
    const h = parseInt(parts[0] || '20', 10);
    const m = parseInt(parts[1] || '0', 10);

    target.setHours(h, m, 0, 0);
    if (now > target) target.setDate(target.getDate() + 1);

    const diff = target - now;
    const hh = Math.floor(diff / 3600000);
    const mm = Math.floor((diff % 3600000) / 60000);
    const ss = Math.floor((diff % 60000) / 1000);

    document.getElementById('uiCountdown').innerText =
      `${String(hh).padStart(2,'0')}:${String(mm).padStart(2,'0')}:${String(ss).padStart(2,'0')}`;
  }, 1000);
</script>

</body>
</html>