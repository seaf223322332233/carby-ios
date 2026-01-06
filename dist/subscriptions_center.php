<?php
// subscriptions_center.php - Subscriptions List (Improved UI + Per-Client Summary + External Edit Page)
// ===============================================================================================
// يجب استدعاء lang_handler.php قبل أي header() أو output
require_once 'lang_handler.php';

header('Content-Type: text/html; charset=utf-8');
require_once 'auth_admin.php';
require_once 'db_connect.php';

ini_set('display_errors', 0);
error_reporting(E_ALL);

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

function ensureTables(PDO $pdo){
    // Per-client option categories
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

    // Per-client meal category limits (using client_category_limits - same table as subscription_edit.php)
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

    // client_details overrides
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

function pkgById(array $all, int $id): ?array {
    foreach ($all as $p) { if ((int)$p['id'] === $id) return $p; }
    return null;
}

function effectiveDurationDays(array $cdRow, ?array $pkgRow): int {
    $ov = safeInt($cdRow['duration_days_override'] ?? 0);
    if ($ov > 0) return $ov;
    return safeInt($pkgRow['duration_days'] ?? 0);
}

function effectiveEndDate(array $cdRow, ?array $pkgRow): string {
    $end = safeStr($cdRow['subscription_end_date'] ?? '');
    if ($end !== '') return $end;
    $start = safeStr($cdRow['subscription_start_date'] ?? '');
    $days  = effectiveDurationDays($cdRow, $pkgRow);
    return computeEndDate($start, $days) ?: '';
}

function computeRemainingDays(?string $endYmd): int {
    if (!$endYmd) return 0;
    try {
        $now = new DateTime(date('Y-m-d'));
        $end = new DateTime($endYmd);
        if ($now > $end) return 0;
        return (int)$now->diff($end)->days;
    } catch(Exception $e){
        return 0;
    }
}

function computeUsedMeals(PDO $pdo, int $clientId, ?string $startYmd): int {
    if (!$startYmd) return 0;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM daily_selections WHERE client_id=? AND delivery_date >= ?");
        $st->execute([$clientId, $startYmd]);
        return (int)$st->fetchColumn();
    } catch(Exception $e){
        return 0;
    }
}

/**
 * Total allowed meals for client:
 * - base from package_category_limits (sum)
 * - if client has overrides in client_category_limits => replace per category (only for categories that exist in package limits)
 * - if client override includes category not in package limits => add it (as extra supply)
 * - add meals_credit_extra from client_details
 */
function computeTotalAllowedMeals(PDO $pdo, int $clientId, int $packageId, int $mealsCreditExtra = 0): int {
    $pkgCats = [];
    try {
        $st = $pdo->prepare("SELECT category_id, allowed_count FROM package_category_limits WHERE package_id=?");
        $st->execute([$packageId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pkgCats[(int)$r['category_id']] = (int)$r['allowed_count'];
        }
    } catch(Exception $e){}

    $clientCats = [];
    try {
        $st = $pdo->prepare("SELECT category_id, allowed_count FROM client_category_limits WHERE user_id=?");
        $st->execute([$clientId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $clientCats[(int)$r['category_id']] = (int)$r['allowed_count'];
        }
    } catch(Exception $e){}

    // Merge: client overrides replace or add
    foreach ($clientCats as $cid => $cnt) {
        $pkgCats[$cid] = $cnt; // replace or add
    }

    $sum = 0;
    foreach ($pkgCats as $cnt) $sum += max(0, (int)$cnt);
    
    // Add meals_credit_extra
    $sum += max(0, (int)$mealsCreditExtra);
    
    return (int)$sum;
}

ensureTables($pdo);

// Base data
$allPackages = $pdo->query("SELECT id, name, duration_days, meals_per_day, allowed_weight FROM packages ORDER BY id DESC")
    ->fetchAll(PDO::FETCH_ASSOC);

// Search
$q = safeStr($_GET['q'] ?? '');
$where = "u.role='client'";
$params = [];
if ($q !== '') {
    $where .= " AND (u.name LIKE ? OR u.email LIKE ? OR cd.phone LIKE ? OR cd.order_no LIKE ? OR u.id = ?)";
    $like = "%{$q}%";
    $params = [$like, $like, $like, $like, safeInt($q)];
}

// Fetch clients
$st = $pdo->prepare("
    SELECT
        u.id, u.name, u.email,
        cd.package_id, cd.subscription_start_date, cd.duration_days_override, cd.subscription_end_date,
        cd.meals_per_day_extra, cd.meals_credit_extra, cd.options_per_day_override,
        cd.order_no
    FROM users u
    LEFT JOIN client_details cd ON cd.user_id = u.id
    WHERE $where
    ORDER BY u.id DESC
    LIMIT 300
");
$st->execute($params);
$clients = $st->fetchAll(PDO::FETCH_ASSOC);

// Summary counters (simple)
$totalClients = count($clients);
$activeCount = 0;
$today = date('Y-m-d');

foreach ($clients as $c) {
    $pid = safeInt($c['package_id'] ?? 0);
    if ($pid <= 0) continue;
    $pkg = pkgById($allPackages, $pid);
    $end = effectiveEndDate($c, $pkg);
    if ($end && $today <= $end) $activeCount++;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <title>إدارة الاشتراكات</title>
  <link rel="stylesheet" href="admin_colors.php">
  <link rel="stylesheet" href="admin-unified-style-v2.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
</head>
<body>

  <div class="sidebar"><?php include 'sidebar.php'; ?></div>

  <div class="main-content">
    <header class="top-bar">
      <div class="user-info">🧩 إدارة الاشتراكات</div>
      <div style="display:flex; gap:10px; align-items:center;">
        <?php echo langSwitcher(); ?>
        <a href="logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> خروج</a>
      </div>
    </header>

    <div class="wrap">

      <div class="pageHead">
        <div class="titleBox">
          <h1>مركز الاشتراكات — عرض وإدارة لكل عميل</h1>
          <p>هنا فقط عرض وبحث + دخول لصفحة التعديل المنفصلة.</p>
        </div>
      </div>

      <div class="statsRow">
        <div class="statCard">
          <div class="statIcon"><i class="fa-solid fa-users"></i></div>
          <div class="statInfo">
            <b><?php echo (int)$totalClients; ?></b>
            <small>عملاء ضمن نتائج البحث</small>
          </div>
        </div>

        <div class="statCard">
          <div class="statIcon"><i class="fa-solid fa-bolt"></i></div>
          <div class="statInfo">
            <b><?php echo (int)$activeCount; ?></b>
            <small>اشتراكات “فعّالة” حسب نهاية الاشتراك</small>
          </div>
        </div>

        <div class="statCard">
          <div class="statIcon"><i class="fa-solid fa-magnifying-glass"></i></div>
          <div class="statInfo">
            <b><?php echo ($q !== '' ? 'مفعّل' : 'غير مفعّل'); ?></b>
            <small>فلتر البحث</small>
          </div>
        </div>
      </div>

      <div class="card">
        <form method="GET" class="searchForm">
          <input class="inp" name="q" value="<?php echo htmlspecialchars($q); ?>"
                 placeholder="ابحث بالاسم / البريد / الهاتف / رقم الطلب / ID">
          <button class="btn btnPrimary" type="submit"><i class="fa-solid fa-magnifying-glass"></i> بحث</button>
          <a class="btn btnGhost" href="subscriptions_center.php"><i class="fa-solid fa-rotate-left"></i> إلغاء</a>
        </form>
      </div>

      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;">
          <div style="font-weight:1000;font-size:1.05rem;"><i class="fa-solid fa-table-list" style="color:var(--primary)"></i> قائمة العملاء</div>
          <div style="color:var(--muted);font-weight:900;font-size:.9rem;">افتح التعديل من زر “إدارة”</div>
        </div>

        <div class="tableWrap">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>العميل</th>
                <th>رقم الطلب</th>
                <th>الباقة</th>
                <th>بداية</th>
                <th>نهاية (فعلي)</th>
                <th>متبقي أيام</th>
                <th>متبقي وجبات</th>
                <th>تحكم</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($clients as $c):
                $cid = safeInt($c['id'] ?? 0);
                $pid = safeInt($c['package_id'] ?? 0);
                $pkg = ($pid>0) ? pkgById($allPackages, $pid) : null;

                $pkgName = $pkg ? ($pkg['name'] ?? '—') : '—';
                $start = safeStr($c['subscription_start_date'] ?? '');
                $end   = $pkg ? effectiveEndDate($c, $pkg) : '';

                $daysRem = ($end !== '') ? computeRemainingDays($end) : 0;

                $mealsCreditExtra = safeInt($c['meals_credit_extra'] ?? 0);
                $totalAllowed = ($pid>0) ? computeTotalAllowedMeals($pdo, $cid, $pid, $mealsCreditExtra) : 0;
                $usedMeals = ($pid>0) ? computeUsedMeals($pdo, $cid, $start) : 0;
                $mealsRem = max(0, $totalAllowed - $usedMeals);

                $statusPill = 'danger';
                $statusText = 'غير نشط';
                if ($pid > 0 && $end !== '') {
                  if (date('Y-m-d') <= $end) { $statusPill='ok'; $statusText='نشط'; }
                  else { $statusPill='warn'; $statusText='منتهي'; }
                }
              ?>
                <tr>
                  <td><?php echo (int)$cid; ?></td>
                  <td>
                    <b><?php echo htmlspecialchars($c['name'] ?? ''); ?></b><br>
                    <small><?php echo htmlspecialchars($c['email'] ?? ''); ?></small>
                    <div style="margin-top:6px;">
                      <span class="pill <?php echo $statusPill; ?>"><i class="fa-solid fa-circle"></i> <?php echo $statusText; ?></span>
                    </div>
                  </td>
                  <td><?php echo htmlspecialchars($c['order_no'] ?? ''); ?></td>
                  <td><?php echo htmlspecialchars($pkgName); ?></td>
                  <td><?php echo htmlspecialchars($start); ?></td>
                  <td><?php echo htmlspecialchars($end); ?></td>
                  <td><span class="pill"><?php echo (int)$daysRem; ?> يوم</span></td>
                  <td><span class="pill"><?php echo (int)$mealsRem; ?> وجبة</span></td>
                  <td>
                    <a class="btn btnPrimary" style="padding:10px 12px;border-radius:14px;"
                       href="subscription_edit.php?id=<?php echo (int)$cid; ?>&q=<?php echo urlencode($q); ?>">
                      <i class="fa-solid fa-sliders"></i> إدارة
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>

              <?php if (!$clients): ?>
                <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:18px;font-weight:1000;">لا توجد نتائج</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>

</body>
</html>