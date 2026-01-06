<?php
// subscription_edit.php - Edit subscription per client (Standalone Page)
// =============================================================================
// يجب استدعاء lang_handler.php قبل أي header() أو output
require_once 'lang_handler.php';

header('Content-Type: text/html; charset=utf-8');
require_once 'auth_admin.php';
require_once 'db_connect.php';
require_once 'csrf.php';

ini_set('display_errors', 0);
error_reporting(E_ALL);

// ----------------------------
// Helpers
// ----------------------------
function safeInt($v): int { return (is_numeric($v) ? (int)$v : 0); }
function safeStr($v): string { return trim((string)$v); }

function getSetting(PDO $pdo, string $key, $default=null) {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $default : $v;
    } catch(Exception $e) {
        return $default;
    }
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?
        ");
        $st->execute([$table, $column]);
        return (int)$st->fetchColumn() > 0;
    } catch(Exception $e){
        return false;
    }
}

function computeEndDate(?string $startYmd, ?int $days): ?string {
    $startYmd = $startYmd ? trim($startYmd) : '';
    if ($startYmd === '' || !$days || $days <= 0) return null;
    try {
        $dt = new DateTime($startYmd);
        $dt->modify('+' . (int)$days . ' days');
        return $dt->format('Y-m-d');
    } catch(Exception $e){
        return null;
    }
}

function ensureTables(PDO $pdo){
    // option category overrides
    $pdo->exec("CREATE TABLE IF NOT EXISTS client_option_category_limits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        option_category_id INT NOT NULL,
        allowed_count INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_client_optcat (user_id, option_category_id),
        KEY idx_user (user_id),
        KEY idx_optcat (option_category_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_option_category_visibility (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        option_category_id INT NOT NULL,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_client_optcat_vis (user_id, option_category_id),
        KEY idx_user (user_id),
        KEY idx_optcat (option_category_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ✅ meal category limits per client (override)
    $pdo->exec("CREATE TABLE IF NOT EXISTS client_category_limits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        category_id INT NOT NULL,
        allowed_count INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_client_cat (user_id, category_id),
        KEY idx_user (user_id),
        KEY idx_cat (category_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // client_details columns (no hard fail)
    try {
        if (!columnExists($pdo, 'client_details', 'duration_days_override')) {
            $pdo->exec("ALTER TABLE client_details ADD COLUMN duration_days_override INT NULL DEFAULT NULL AFTER subscription_start_date");
        }
    } catch(Exception $e) {}
    try {
        if (!columnExists($pdo, 'client_details', 'subscription_end_date')) {
            $pdo->exec("ALTER TABLE client_details ADD COLUMN subscription_end_date DATE NULL DEFAULT NULL AFTER duration_days_override");
        }
    } catch(Exception $e) {}
    try {
        if (!columnExists($pdo, 'client_details', 'grace_period_days_override')) {
            $pdo->exec("ALTER TABLE client_details ADD COLUMN grace_period_days_override INT NULL DEFAULT NULL AFTER order_no");
        }
    } catch(Exception $e) {}
    // ✅ extra meal credits (top-up)
    try {
        if (!columnExists($pdo, 'client_details', 'meals_credit_extra')) {
            $pdo->exec("ALTER TABLE client_details ADD COLUMN meals_credit_extra INT NOT NULL DEFAULT 0 AFTER meals_per_day_extra");
        }
    } catch(Exception $e) {}
    // ✅ meals_per_day_override (override daily meals limit)
    try {
        if (!columnExists($pdo, 'client_details', 'meals_per_day_override')) {
            $pdo->exec("ALTER TABLE client_details ADD COLUMN meals_per_day_override INT NULL DEFAULT NULL AFTER meals_per_day_extra");
        }
    } catch(Exception $e) {}
}

ensureTables($pdo);

// ----------------------------
// Inputs
// ----------------------------
$clientId = safeInt($_GET['id'] ?? 0);
$qBack    = safeStr($_GET['q'] ?? '');

if ($clientId <= 0) {
    header("Location: subscriptions_center.php");
    exit;
}

// ----------------------------
// Load base data
// ----------------------------
$allPackages = $pdo->query("SELECT id, name, duration_days, meals_per_day, allowed_weight FROM packages ORDER BY id DESC")
    ->fetchAll(PDO::FETCH_ASSOC);

$optCats = [];
try {
    $optCats = $pdo->query("SELECT id, name, is_active FROM option_categories ORDER BY sort_order ASC, id ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){
    $optCats = [];
}

$graceDefault = safeInt(getSetting($pdo, 'grace_period_days', 30));

$stClient = $pdo->prepare("
    SELECT u.id, u.name, u.email, cd.*
    FROM users u
    LEFT JOIN client_details cd ON cd.user_id = u.id
    WHERE u.id=? AND u.role='client'
    LIMIT 1
");
$stClient->execute([$clientId]);
$client = $stClient->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    header("Location: subscriptions_center.php");
    exit;
}

// ensure row exists
$pdo->prepare("INSERT IGNORE INTO client_details (user_id) VALUES (?)")->execute([$clientId]);

$packageId = safeInt($client['package_id'] ?? 0);
$pkg = null;
foreach($allPackages as $p){ if((int)$p['id']===$packageId){ $pkg = $p; break; } }

$pkgName = $pkg ? ($pkg['name'] ?? '—') : '—';
$pkgMealsPerDay = $pkg ? safeInt($pkg['meals_per_day'] ?? 0) : 0;
$pkgDuration = $pkg ? safeInt($pkg['duration_days'] ?? 0) : 0;
$pkgWeight = $pkg ? safeStr($pkg['allowed_weight'] ?? '') : '';

// meals_per_day_override - القيمة الفعالة للوجبات اليومية
$mealsPerDayOverride = ($client['meals_per_day_override'] ?? null);
$mealsPerDayOverride = ($mealsPerDayOverride === null || $mealsPerDayOverride === '') ? null : safeInt($mealsPerDayOverride);
$effectiveMealsPerDay = ($mealsPerDayOverride !== null && $mealsPerDayOverride > 0) ? $mealsPerDayOverride : $pkgMealsPerDay;

$startDate = safeStr($client['subscription_start_date'] ?? '');
$durationOverride = ($client['duration_days_override'] ?? null);
$durationOverride = ($durationOverride === null || $durationOverride === '') ? null : safeInt($durationOverride);
$endDateManual = safeStr($client['subscription_end_date'] ?? '');

$effectiveDuration = ($durationOverride !== null && $durationOverride > 0) ? $durationOverride : $pkgDuration;
$effectiveEnd = $endDateManual !== '' ? $endDateManual : computeEndDate($startDate, $effectiveDuration);

$graceOverride = ($client['grace_period_days_override'] ?? null);
$graceOverride = ($graceOverride === null || $graceOverride === '') ? null : safeInt($graceOverride);
$effectiveGrace = ($graceOverride !== null && $graceOverride >= 0) ? $graceOverride : $graceDefault;

// status + remaining days
$subStatus = 'unknown';
$remainingDays = 0;
$endDtObj = null;
$graceEndObj = null;

try {
    if ($effectiveEnd) {
        $now = new DateTime('today');
        $endDtObj = new DateTime($effectiveEnd);
        $graceEndObj = (clone $endDtObj)->modify("+{$effectiveGrace} days");

        if ($now <= $endDtObj) {
            $subStatus = 'active';
            $remainingDays = (int)$now->diff($endDtObj)->days;
        } elseif ($now <= $graceEndObj) {
            $subStatus = 'grace';
            $remainingDays = (int)$now->diff($graceEndObj)->days;
        } else {
            $subStatus = 'expired';
            $remainingDays = 0;
        }
    }
} catch(Exception $e){}

// ----------------------------
// Meal credits + category balances
// ----------------------------
$totalAllowedMeals = 0;
$usedMeals = 0;
$remainingMeals = 0;

// category base limits
$pkgCatLimits = []; // [cat_id => allowed_count]
if ($packageId > 0) {
    $stCL = $pdo->prepare("SELECT category_id, allowed_count FROM package_category_limits WHERE package_id=?");
    $stCL->execute([$packageId]);
    $pkgCatLimits = $stCL->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
}

// client overrides for meal categories
$clientCatLimits = []; // [cat_id => allowed_count]
$stO = $pdo->prepare("SELECT category_id, allowed_count FROM client_category_limits WHERE user_id=?");
$stO->execute([$clientId]);
foreach ($stO->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $clientCatLimits[(int)$r['category_id']] = (int)$r['allowed_count'];
}

if ($packageId > 0) {
    // Calculate total allowed meals:
    // 1. Start with package category limits
    $mergedCats = $pkgCatLimits;
    
    // 2. Apply client overrides (replace or add)
    foreach ($clientCatLimits as $catId => $count) {
        $mergedCats[$catId] = $count;
    }
    
    // 3. Sum all category limits
    $totalAllowedMeals = 0;
    foreach ($mergedCats as $count) {
        $totalAllowedMeals += max(0, (int)$count);
    }

    // used meals since start
    if ($startDate !== '') {
        $stUsed = $pdo->prepare("SELECT COUNT(*) FROM daily_selections WHERE client_id=? AND delivery_date >= ?");
        $stUsed->execute([$clientId, $startDate]);
        $usedMeals = (int)$stUsed->fetchColumn();
    }

    $remainingMeals = max(0, $totalAllowedMeals - $usedMeals);
}

// categories names
$catNames = [];
if (!empty($pkgCatLimits)) {
    $ids = array_map('intval', array_keys($pkgCatLimits));
    $in = implode(',', $ids);
    try {
        $rows = $pdo->query("SELECT id, name FROM categories WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $r){ $catNames[(int)$r['id']] = $r['name']; }
    } catch(Exception $e){}
}

// used per category
$usedPerCat = []; // [cat_id => used]
if ($startDate !== '' && !empty($pkgCatLimits)) {
    $stUC = $pdo->prepare("
        SELECT category_id, COUNT(*) AS c
        FROM daily_selections
        WHERE client_id=? AND delivery_date >= ?
        GROUP BY category_id
    ");
    $stUC->execute([$clientId, $startDate]);
    foreach($stUC->fetchAll(PDO::FETCH_ASSOC) as $r){
        $usedPerCat[(int)$r['category_id']] = (int)$r['c'];
    }
}

// option category overrides load
$clientVis = [];
$clientLim = [];
$stV = $pdo->prepare("SELECT option_category_id, is_enabled FROM client_option_category_visibility WHERE user_id=?");
$stV->execute([$clientId]);
foreach ($stV->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $clientVis[(int)$r['option_category_id']] = (int)$r['is_enabled'];
}
$stL = $pdo->prepare("SELECT option_category_id, allowed_count FROM client_option_category_limits WHERE user_id=?");
$stL->execute([$clientId]);
foreach ($stL->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $clientLim[(int)$r['option_category_id']] = (int)$r['allowed_count'];
}

// ----------------------------
// Save
// ----------------------------
$flash = '';
$saved = (isset($_GET['saved']) && $_GET['saved'] == '1');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_subscription'])) {
    // التحقق من CSRF Token
    if (!verifyCSRFToken()) {
        $flash = "خطأ في التحقق من الأمان. يرجى المحاولة مرة أخرى.";
    } else {
        try {
            $pdo->beginTransaction();

        $package_id = safeInt($_POST['package_id'] ?? 0);
        $start_date = safeStr($_POST['subscription_start_date'] ?? '');
        $end_date   = safeStr($_POST['subscription_end_date'] ?? '');

        $duration_override = ($_POST['duration_days_override'] ?? '');
        $duration_override = ($duration_override === '' ? null : safeInt($duration_override));
        if ($duration_override !== null && $duration_override < 0) $duration_override = 0;

        // If end empty and duration+start exists -> auto compute
        if ($end_date === '' && $start_date !== '' && $duration_override !== null && $duration_override > 0) {
            $autoEnd = computeEndDate($start_date, $duration_override);
            if ($autoEnd) $end_date = $autoEnd;
        }

        $meals_extra = safeInt($_POST['meals_per_day_extra'] ?? 0);

        $meals_per_day_override_new = ($_POST['meals_per_day_override'] ?? '');
        $meals_per_day_override_new = ($meals_per_day_override_new === '' ? null : safeInt($meals_per_day_override_new));
        if ($meals_per_day_override_new !== null && $meals_per_day_override_new < 0) $meals_per_day_override_new = 0;

        $opt_per_day = safeInt($_POST['options_per_day_override'] ?? 0);
        $opt_vis = safeInt($_POST['options_visible_override'] ?? 0);
        $opt_sel = safeInt($_POST['options_selectable_override'] ?? 0);

        $pick_mode = safeStr($_POST['option_pick_mode'] ?? 'daily');
        // توافق مع قاعدة البيانات: enum('daily','off')
        // تحويل 'flexible' و 'none' إلى 'off'
        if ($pick_mode === 'flexible' || $pick_mode === 'none') {
            $pick_mode = 'off';
        }
        if (!in_array($pick_mode, ['daily','off'], true)) $pick_mode = 'daily';

        $order_no = safeStr($_POST['order_no'] ?? '');
        $grace_override_new = ($_POST['grace_period_days_override'] ?? '');
        $grace_override_new = ($grace_override_new === '' ? null : safeInt($grace_override_new));
        if ($grace_override_new !== null && $grace_override_new < 0) $grace_override_new = 0;

        if ($order_no === '') $order_no = 'SUB-' . $clientId . '-' . date('YmdHis');

        $up = $pdo->prepare("
            UPDATE client_details SET
                package_id=?,
                subscription_start_date=?,
                duration_days_override=?,
                subscription_end_date=?,
                meals_per_day_extra=?,
                meals_per_day_override=?,
                options_per_day_override=?,
                options_visible_override=?,
                options_selectable_override=?,
                option_pick_mode=?,
                order_no=?,
                grace_period_days_override=?
            WHERE user_id=?
        ");
        $up->execute([
            $package_id ?: null,
            $start_date ?: null,
            ($duration_override === null ? null : $duration_override),
            ($end_date !== '' ? $end_date : null),
            $meals_extra,
            ($meals_per_day_override_new === null ? null : $meals_per_day_override_new),
            $opt_per_day,
            $opt_vis,
            $opt_sel,
            $pick_mode,
            $order_no,
            ($grace_override_new === null ? null : $grace_override_new),
            $clientId
        ]);

        // meal category overrides
        $catOverrides = $_POST['cat_limit'] ?? [];
        $pdo->prepare("DELETE FROM client_category_limits WHERE user_id=?")->execute([$clientId]);
        if (is_array($catOverrides)) {
            $ins = $pdo->prepare("INSERT INTO client_category_limits (user_id, category_id, allowed_count) VALUES (?,?,?)");
            foreach($catOverrides as $catId => $val){
                $catId = safeInt($catId);
                if ($catId <= 0) continue;
                if ($val === '' || $val === null) continue; // blank => no override
                $v = safeInt($val);
                if ($v < 0) $v = 0;
                $ins->execute([$clientId, $catId, $v]);
            }
        }

        // option categories
        $visArr = $_POST['optcat_vis'] ?? [];
        $limArr = $_POST['optcat_lim'] ?? [];

        $pdo->prepare("DELETE FROM client_option_category_visibility WHERE user_id=?")->execute([$clientId]);
        $pdo->prepare("DELETE FROM client_option_category_limits WHERE user_id=?")->execute([$clientId]);

        if (is_array($visArr)) {
            $insV = $pdo->prepare("INSERT INTO client_option_category_visibility (user_id, option_category_id, is_enabled) VALUES (?,?,?)");
            foreach ($visArr as $cid => $val) {
                $cid = safeInt($cid);
                if ($cid <= 0) continue;
                $insV->execute([$clientId, $cid, ($val == '1' ? 1 : 0)]);
            }
        }

        if (is_array($limArr)) {
            $insL = $pdo->prepare("INSERT INTO client_option_category_limits (user_id, option_category_id, allowed_count) VALUES (?,?,?)");
            foreach ($limArr as $cid => $val) {
                $cid = safeInt($cid);
                $val = safeInt($val);
                if ($cid <= 0) continue;
                if ($val < 0) $val = 0;
                $insL->execute([$clientId, $cid, $val]);
            }
        }

        // log
        try {
            $lg = $pdo->prepare("
                INSERT INTO subscription_adjustments
                (client_id, admin_id, action_type, action_note, created_at)
                VALUES (?, ?, 'update', ?, NOW())
            ");
            $adminId = safeInt($_SESSION['user_id'] ?? 0);
            $note = "Updated subscription overrides + meal credits + meal category limits + option categories.";
            $lg->execute([$clientId, $adminId, $note]);
        } catch(Exception $e){}

            $pdo->commit();
            header("Location: subscription_edit.php?id={$clientId}&q=" . urlencode($qBack) . "&saved=1");
            exit;

        } catch(Exception $e) {
            $pdo->rollBack();
            $flash = "تعذر الحفظ: " . $e->getMessage();
        }
    }
}

// ----------------------------------
// UI rendering helpers
// ----------------------------------
function badge($status){
    if($status==='active') return ['نشط','ok'];
    if($status==='grace') return ['إمهال','warn'];
    if($status==='expired') return ['منتهي','bad'];
    return ['غير معروف','muted'];
}
[$badgeText,$badgeType] = badge($subStatus);

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>تعديل اشتراك العميل</title>
  <link rel="stylesheet" href="admin_colors.php">
  <link rel="stylesheet" href="admin-unified-style-v2.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
</head>
<body>

<div class="wrap">

  <div class="topHead">
    <div class="title">
      <b><i class="fas fa-sliders" style="color:var(--primary)"></i> تعديل اشتراك العميل</b>
      <small>
        <?php echo htmlspecialchars($client['name'] ?? ''); ?> —
        <?php echo htmlspecialchars($client['email'] ?? ''); ?>
      </small>
    </div>
    <div class="actions">
      <?php echo langSwitcher(); ?>
      <a class="btn btnGhost" href="subscriptions_center.php?q=<?php echo urlencode($qBack); ?>">
        <i class="fas fa-arrow-right"></i> رجوع للقائمة
      </a>
      <a class="btn btnGhost" href="logout.php">
        <i class="fas fa-sign-out-alt"></i> خروج
      </a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="flashErr"><i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars($flash); ?></div>
  <?php endif; ?>
  <?php if ($saved): ?>
    <div class="flashOk"><i class="fas fa-check-circle"></i> تم حفظ التعديلات بنجاح</div>
  <?php endif; ?>

  <div class="card" style="margin-bottom:14px;">
    <h3><i class="fas fa-circle-info" style="color:var(--primary)"></i> ملخص الاشتراك</h3>
    <div class="rowMini" style="margin-bottom:12px;">
      <span class="pill muted"><i class="fas fa-box"></i> الباقة: <b><?php echo htmlspecialchars($pkgName); ?></b></span>
      <span class="pill muted"><i class="fas fa-scale-balanced"></i> وزن: <b><?php echo htmlspecialchars($pkgWeight ?: '—'); ?></b></span>
      <span class="pill <?php echo $badgeType; ?>"><i class="fas fa-bolt"></i> الحالة: <b><?php echo $badgeText; ?></b></span>
      <span class="pill muted"><i class="fas fa-calendar-day"></i> بداية: <b><?php echo htmlspecialchars($startDate ?: '—'); ?></b></span>
      <span class="pill muted"><i class="fas fa-calendar-check"></i> نهاية: <b><?php echo htmlspecialchars($effectiveEnd ?: '—'); ?></b></span>
      <span class="pill muted"><i class="fas fa-hourglass-half"></i> متبقي: <b><?php echo (int)$remainingDays; ?></b> يوم</span>
    </div>

    <div class="kpiGrid">
      <div class="kpi">
        <small>إجمالي رصيد الوجبات</small>
        <b><?php echo (int)$totalAllowedMeals; ?></b>
      </div>
      <div class="kpi">
        <small>المستخدم</small>
        <b><?php echo (int)$usedMeals; ?></b>
      </div>
      <div class="kpi">
        <small>المتبقي</small>
        <b><?php echo (int)$remainingMeals; ?></b>
      </div>
      <div class="kpi">
        <small>وجبات/يوم (فعلي)</small>
        <b><?php echo (int)$effectiveMealsPerDay; ?></b>
        <?php if ($mealsPerDayOverride !== null): ?>
          <small style="color:var(--warn); font-size:0.75rem;">(مخصص: <?php echo (int)$mealsPerDayOverride; ?>)</small>
        <?php else: ?>
          <small style="color:var(--muted); font-size:0.75rem;">(من الباقة: <?php echo (int)$pkgMealsPerDay; ?>)</small>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <form method="POST">
    <input type="hidden" name="save_subscription" value="1">
    <?php echo csrfField(); ?>

    <div class="grid g2">
      <div class="card">
        <h3><i class="fas fa-id-card" style="color:var(--primary)"></i> بيانات الاشتراك الأساسية</h3>

        <label>الباقة</label>
        <select class="control" name="package_id">
          <option value="0">— بدون —</option>
          <?php foreach($allPackages as $p): ?>
            <option value="<?php echo (int)$p['id']; ?>"
              <?php echo ((int)$packageId === (int)$p['id']) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($p['name']); ?> (<?php echo (int)$p['duration_days']; ?> يوم)
            </option>
          <?php endforeach; ?>
        </select>

        <div class="grid g2" style="margin-top:10px;">
          <div>
            <label>بداية الاشتراك</label>
            <input class="control" type="date" name="subscription_start_date"
                   value="<?php echo htmlspecialchars($startDate); ?>">
          </div>
          <div>
            <label>مدة خاصة للعميل (يوم) — اتركها فارغة لاستخدام مدة الباقة</label>
            <input class="control" type="number" min="0" name="duration_days_override"
                   value="<?php echo htmlspecialchars((string)($client['duration_days_override'] ?? '')); ?>"
                   placeholder="مثال: 45">
          </div>
        </div>

        <label style="margin-top:10px;">نهاية الاشتراك (اختياري) — إذا تركتها فارغة سيتم الحساب تلقائيًا</label>
        <input class="control" type="date" name="subscription_end_date"
               value="<?php echo htmlspecialchars($endDateManual); ?>">

        <div class="grid g2" style="margin-top:10px;">
          <div>
            <label>إمهال خاص للعميل (يوم) — اتركه فارغ للعام (<?php echo (int)$graceDefault; ?>)</label>
            <input class="control" type="number" min="0" name="grace_period_days_override"
                   value="<?php echo htmlspecialchars((string)($client['grace_period_days_override'] ?? '')); ?>">
          </div>
          <div>
            <label>رقم الطلب (order_no)</label>
            <input class="control" name="order_no"
                   value="<?php echo htmlspecialchars((string)($client['order_no'] ?? '')); ?>"
                   placeholder="SUB-...">
          </div>
        </div>

        <div class="note">
          <i class="fas fa-lightbulb" style="color:var(--primary)"></i>
          إذا كتبت “نهاية الاشتراك” سيتم اعتمادها مباشرة.  
          إذا تركتها فارغة ووضعت “البداية + مدة العميل” سيتم حساب النهاية تلقائيًا.
        </div>
      </div>

      <div class="card">
        <h3><i class="fas fa-wand-magic-sparkles" style="color:var(--primary)"></i> تحكم الوجبات والإضافات</h3>

        <div class="grid g2">
          <div>
            <label>
              <i class="fas fa-calendar-day" style="color:var(--primary); margin-left:5px;"></i>
              عدد الوجبات اليومية المسموح للعميل
            </label>
            <div style="margin-bottom:8px;">
              <small style="color:var(--muted); font-weight:900;">
                من الباقة: <b><?php echo (int)$pkgMealsPerDay; ?></b> وجبة/يوم
                <?php if ($mealsPerDayOverride !== null): ?>
                  | المخصص: <b style="color:var(--warn);"><?php echo (int)$mealsPerDayOverride; ?></b> وجبة/يوم
                <?php endif; ?>
              </small>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
              <input class="control" type="number" min="0" name="meals_per_day_override" id="meals_per_day_override_input"
                     value="<?php echo ($mealsPerDayOverride !== null ? (int)$mealsPerDayOverride : (int)$pkgMealsPerDay); ?>" 
                     style="flex:1; text-align:center; font-weight:900; font-size:1.1rem; color:var(--primary);">
              <div style="display:flex; flex-direction:column; gap:4px;">
                <button type="button" onclick="adjustMealsPerDay(1)" class="btnAdjust" style="background:var(--ok);" title="إضافة 1 على القيمة الحالية">
                  <i class="fas fa-plus"></i> +1
                </button>
                <button type="button" onclick="adjustMealsPerDay(2)" class="btnAdjust" style="background:var(--ok);" title="إضافة 2 على القيمة الحالية">
                  <i class="fas fa-plus"></i> +2
                </button>
              </div>
              <div style="display:flex; flex-direction:column; gap:4px;">
                <button type="button" onclick="adjustMealsPerDay(-1)" class="btnAdjust" style="background:var(--warn);" title="إنقاص 1 من القيمة الحالية">
                  <i class="fas fa-minus"></i> -1
                </button>
                <button type="button" onclick="adjustMealsPerDay(-2)" class="btnAdjust" style="background:var(--bad);" title="إنقاص 2 من القيمة الحالية">
                  <i class="fas fa-minus"></i> -2
                </button>
              </div>
            </div>
            <small style="color:var(--muted); font-size:0.85rem; margin-top:5px; display:block;">
              <i class="fas fa-info-circle"></i> اتركه فارغاً لاستخدام قيمة الباقة (<?php echo (int)$pkgMealsPerDay; ?> وجبة/يوم)
            </small>
          </div>
          <?php if (!empty($client['meals_per_day_extra']) && (int)$client['meals_per_day_extra'] > 0): ?>
          <div>
            <label>زيادة وجبات/يوم (Extra)</label>
            <div style="display:flex; gap:8px; align-items:center;">
              <input class="control" type="number" min="0" name="meals_per_day_extra" id="meals_per_day_extra_input"
                     value="<?php echo (int)($client['meals_per_day_extra'] ?? 0); ?>"
                     style="flex:1; text-align:center; font-weight:900;">
              <div style="display:flex; flex-direction:column; gap:4px;">
                <button type="button" onclick="adjustValue('meals_per_day_extra_input', 1)" class="btnAdjust" style="background:var(--ok);" title="إضافة 1">
                  <i class="fas fa-plus"></i> +1
                </button>
                <button type="button" onclick="adjustValue('meals_per_day_extra_input', 2)" class="btnAdjust" style="background:var(--ok);" title="إضافة 2">
                  <i class="fas fa-plus"></i> +2
                </button>
              </div>
              <div style="display:flex; flex-direction:column; gap:4px;">
                <button type="button" onclick="adjustValue('meals_per_day_extra_input', -1)" class="btnAdjust" style="background:var(--warn);" title="إنقاص 1">
                  <i class="fas fa-minus"></i> -1
                </button>
                <button type="button" onclick="adjustValue('meals_per_day_extra_input', -2)" class="btnAdjust" style="background:var(--bad);" title="إنقاص 2">
                  <i class="fas fa-minus"></i> -2
                </button>
              </div>
            </div>
            <small style="color:var(--muted); font-size:0.85rem; margin-top:5px; display:block;">
              <i class="fas fa-info-circle"></i> إضافة إضافية على الحد اليومي
            </small>
          </div>
          <?php endif; ?>
        </div>


        <?php if (!empty($client['options_per_day_override']) || !empty($client['options_visible_override']) || !empty($client['options_selectable_override'])): ?>
        <div class="grid g2" style="margin-top:14px;">
          <?php if (!empty($client['options_per_day_override'])): ?>
          <div>
            <label>
              <i class="fas fa-sliders" style="color:var(--primary); margin-left:5px;"></i>
              حد الإضافات يومياً (Override)
            </label>
            <div style="display:flex; gap:8px; align-items:center;">
              <input class="control" type="number" min="0" name="options_per_day_override" id="options_per_day_override_input"
                     value="<?php echo (int)($client['options_per_day_override'] ?? 0); ?>"
                     style="flex:1; text-align:center; font-weight:900;">
              <div style="display:flex; flex-direction:column; gap:4px;">
                <button type="button" onclick="adjustValue('options_per_day_override_input', 1)" class="btnAdjust" style="background:var(--ok);" title="إضافة 1 على القيمة الحالية">
                  <i class="fas fa-plus"></i> +1
                </button>
                <button type="button" onclick="adjustValue('options_per_day_override_input', 2)" class="btnAdjust" style="background:var(--ok);" title="إضافة 2 على القيمة الحالية">
                  <i class="fas fa-plus"></i> +2
                </button>
              </div>
              <div style="display:flex; flex-direction:column; gap:4px;">
                <button type="button" onclick="adjustValue('options_per_day_override_input', -1)" class="btnAdjust" style="background:var(--warn);" title="إنقاص 1 من القيمة الحالية">
                  <i class="fas fa-minus"></i> -1
                </button>
                <button type="button" onclick="adjustValue('options_per_day_override_input', -2)" class="btnAdjust" style="background:var(--bad);" title="إنقاص 2 من القيمة الحالية">
                  <i class="fas fa-minus"></i> -2
                </button>
              </div>
            </div>
          </div>
          <?php endif; ?>
          <div>
            <label>نمط اختيار الإضافات</label>
            <?php $pm = (string)($client['option_pick_mode'] ?? 'daily'); ?>
            <select class="control" name="option_pick_mode">
              <option value="daily" <?php echo ($pm==='daily' || $pm==='flexible')?'selected':''; ?>>يومي</option>
              <option value="off" <?php echo ($pm==='off' || $pm==='none')?'selected':''; ?>>بدون إضافات</option>
            </select>
          </div>
        </div>

        <?php if (!empty($client['options_visible_override']) || !empty($client['options_selectable_override'])): ?>
        <div class="grid g2" style="margin-top:14px;">
          <?php if (!empty($client['options_visible_override'])): ?>
          <div>
            <label>
              <i class="fas fa-eye" style="color:var(--primary); margin-left:5px;"></i>
              Visible Options (Override)
            </label>
            <div style="display:flex; gap:8px; align-items:center;">
              <input class="control" type="number" min="0" name="options_visible_override" id="options_visible_override_input"
                     value="<?php echo (int)($client['options_visible_override'] ?? 0); ?>"
                     style="flex:1; text-align:center; font-weight:900;">
              <div style="display:flex; flex-direction:column; gap:4px;">
                <button type="button" onclick="adjustValue('options_visible_override_input', 1)" class="btnAdjust" style="background:var(--ok);" title="إضافة 1 على القيمة الحالية">
                  <i class="fas fa-plus"></i> +1
                </button>
                <button type="button" onclick="adjustValue('options_visible_override_input', 5)" class="btnAdjust" style="background:var(--ok);" title="إضافة 5 على القيمة الحالية">
                  <i class="fas fa-plus"></i> +5
                </button>
              </div>
              <div style="display:flex; flex-direction:column; gap:4px;">
                <button type="button" onclick="adjustValue('options_visible_override_input', -1)" class="btnAdjust" style="background:var(--warn);" title="إنقاص 1 من القيمة الحالية">
                  <i class="fas fa-minus"></i> -1
                </button>
                <button type="button" onclick="adjustValue('options_visible_override_input', -5)" class="btnAdjust" style="background:var(--bad);" title="إنقاص 5 من القيمة الحالية">
                  <i class="fas fa-minus"></i> -5
                </button>
              </div>
            </div>
          </div>
          <?php endif; ?>
          <?php if (!empty($client['options_selectable_override'])): ?>
          <div>
            <label>
              <i class="fas fa-hand-pointer" style="color:var(--primary); margin-left:5px;"></i>
              Selectable Options (Override)
            </label>
            <div style="display:flex; gap:8px; align-items:center;">
              <input class="control" type="number" min="0" name="options_selectable_override" id="options_selectable_override_input"
                     value="<?php echo (int)($client['options_selectable_override'] ?? 0); ?>"
                     style="flex:1; text-align:center; font-weight:900;">
              <div style="display:flex; flex-direction:column; gap:4px;">
                <button type="button" onclick="adjustValue('options_selectable_override_input', 1)" class="btnAdjust" style="background:var(--ok);" title="إضافة 1 على القيمة الحالية">
                  <i class="fas fa-plus"></i> +1
                </button>
                <button type="button" onclick="adjustValue('options_selectable_override_input', 5)" class="btnAdjust" style="background:var(--ok);" title="إضافة 5 على القيمة الحالية">
                  <i class="fas fa-plus"></i> +5
                </button>
              </div>
              <div style="display:flex; flex-direction:column; gap:4px;">
                <button type="button" onclick="adjustValue('options_selectable_override_input', -1)" class="btnAdjust" style="background:var(--warn);" title="إنقاص 1 من القيمة الحالية">
                  <i class="fas fa-minus"></i> -1
                </button>
                <button type="button" onclick="adjustValue('options_selectable_override_input', -5)" class="btnAdjust" style="background:var(--bad);" title="إنقاص 5 من القيمة الحالية">
                  <i class="fas fa-minus"></i> -5
                </button>
              </div>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <div class="note">
          <i class="fas fa-circle-check" style="color:var(--ok)"></i>
          “إمداد رصيد وجبات إضافي” يزيد إجمالي الرصيد العام للوجبات للعميل (بدون لمس الباقة الأصلية).
        </div>
      </div>
    </div>

    <div class="card" style="margin-top:14px;">
      <h3><i class="fas fa-utensils" style="color:var(--primary)"></i> تصنيفات الوجبات (Limits) — تخصيص للعميل</h3>
      <?php if ($packageId <= 0): ?>
        <div class="note">لا توجد باقة مرتبطة بالعميل حالياً.</div>
      <?php elseif (empty($pkgCatLimits)): ?>
        <div class="note">لا توجد حدود تصنيفات للباقة في جدول package_category_limits.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>التصنيف</th>
              <th>حد الباقة</th>
              <th>Override للعميل</th>
              <th>المستخدم</th>
              <th>المتبقي</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($pkgCatLimits as $catId => $baseLimit):
              $catId = (int)$catId;
              $baseLimit = (int)$baseLimit;
              $override = array_key_exists($catId, $clientCatLimits) ? (int)$clientCatLimits[$catId] : null;

              $effective = ($override !== null ? $override : $baseLimit);
              $used = (int)($usedPerCat[$catId] ?? 0);
              $rem = max(0, $effective - $used);

              $catName = $catNames[$catId] ?? ('تصنيف #' . $catId);
            ?>
              <tr>
                <td><?php echo htmlspecialchars($catName); ?></td>
                <td>
                  <b><?php echo (int)$baseLimit; ?></b>
                  <small style="color:var(--muted); display:block; margin-top:4px;">من الباقة</small>
                </td>
                <td class="tdInput">
                  <div style="display:flex; gap:6px; align-items:center;">
                    <input type="number" min="0"
                           name="cat_limit[<?php echo $catId; ?>]"
                           id="cat_limit_<?php echo $catId; ?>"
                           value="<?php echo ($override === null ? '' : (string)$override); ?>"
                           placeholder="اتركه فارغ"
                           style="flex:1; max-width:120px;">
                    <div style="display:flex; flex-direction:column; gap:3px;">
                      <button type="button" onclick="adjustValue('cat_limit_<?php echo $catId; ?>', 1)" class="btnAdjust" style="background:var(--ok); padding:4px 8px; font-size:0.75rem;" title="+1">
                        <i class="fas fa-plus"></i>
                      </button>
                      <button type="button" onclick="adjustValue('cat_limit_<?php echo $catId; ?>', -1)" class="btnAdjust" style="background:var(--warn); padding:4px 8px; font-size:0.75rem;" title="-1">
                        <i class="fas fa-minus"></i>
                      </button>
                    </div>
                  </div>
                </td>
                <td>
                  <b style="color:var(--muted);"><?php echo (int)$used; ?></b>
                  <small style="color:var(--muted); display:block; margin-top:4px;">مستخدم</small>
                </td>
                <td>
                  <b style="color:var(--ok); font-size:1.1rem;"><?php echo (int)$rem; ?></b>
                  <small style="color:var(--muted); display:block; margin-top:4px;">متبقي</small>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="note">
          <i class="fas fa-circle-info" style="color:var(--primary)"></i>
          اترك “Override للعميل” فارغًا ليبقى حد الباقة كما هو. اكتب رقمًا لتغيير حد التصنيف لهذا العميل فقط.
        </div>
      <?php endif; ?>
    </div>

    <div class="card" style="margin-top:14px;">
      <h3><i class="fas fa-layer-group" style="color:var(--primary)"></i> تصنيفات الإضافات للعميل (إظهار + Limits)</h3>

      <?php if (empty($optCats)): ?>
        <div class="note">لا توجد option_categories أو الجدول غير متوفر.</div>
      <?php else: ?>
        <?php foreach($optCats as $c):
          $cid = (int)$c['id'];
          $isActive = (int)($c['is_active'] ?? 1) === 1;
          $enabled = isset($clientVis[$cid]) ? (int)$clientVis[$cid] : 1;
          $lim = isset($clientLim[$cid]) ? (int)$clientLim[$cid] : 0;
        ?>
          <div style="display:flex; gap:10px; align-items:center; padding:12px; border:1px solid var(--stroke); border-radius:16px; margin-bottom:10px; background: rgba(255,255,255,.85);">
            <div style="flex:1; font-weight:1000;">
              <?php echo htmlspecialchars($c['name']); ?>
              <?php if(!$isActive): ?>
                <span class="pill bad" style="margin-right:8px;">غير نشط عام</span>
              <?php endif; ?>
            </div>

            <span class="pill muted">Enabled</span>
            <select class="control" style="max-width:160px;" name="optcat_vis[<?php echo $cid; ?>]">
              <option value="1" <?php echo $enabled===1?'selected':''; ?>>نعم</option>
              <option value="0" <?php echo $enabled===0?'selected':''; ?>>لا</option>
            </select>

            <span class="pill muted">Limit</span>
            <input class="control" style="max-width:160px;" type="number" min="0"
                   name="optcat_lim[<?php echo $cid; ?>]" value="<?php echo (int)$lim; ?>">
          </div>
        <?php endforeach; ?>

        <div class="note">
          <i class="fas fa-circle-info" style="color:var(--primary)"></i>
          Limit = عدد مرات يسمح للعميل باستخدام هذا التصنيف خلال الاشتراك (0 = مفتوح/غير محدد).
        </div>
      <?php endif; ?>
    </div>

    <div style="display:flex; gap:10px; justify-content:flex-end; margin: 16px 0 10px;">
      <button class="btn btnPrimary" type="submit">
        <i class="fas fa-save"></i> حفظ كل التعديلات
      </button>
      <a class="btn btnGhost" href="subscriptions_center.php?q=<?php echo urlencode($qBack); ?>">
        إلغاء
      </a>
    </div>

  </form>

</div>

<script>
function adjustMealsPerDay(amount) {
  const input = document.getElementById('meals_per_day_override_input');
  if (!input) return;
  let currentValue = parseInt(input.value) || 0;
  let newValue = currentValue + amount; // إضافة/نقص على القيمة الموجودة
  if (newValue < 0) newValue = 0;
  input.value = newValue;
  
  // Add visual feedback
  input.style.transform = 'scale(1.1)';
  input.style.transition = 'all 0.2s';
  setTimeout(() => {
    input.style.transform = 'scale(1)';
  }, 200);
}

function adjustValue(inputId, amount) {
  const input = document.getElementById(inputId);
  if (!input) return;
  let currentValue = parseInt(input.value) || 0;
  let newValue = currentValue + amount; // إضافة/نقص على القيمة الموجودة
  if (newValue < 0) newValue = 0;
  input.value = newValue;
  
  // Add visual feedback
  input.style.transform = 'scale(1.1)';
  input.style.transition = 'all 0.2s';
  setTimeout(() => {
    input.style.transform = 'scale(1)';
  }, 200);
}
</script>

</body>
</html>