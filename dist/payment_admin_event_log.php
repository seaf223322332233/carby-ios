<?php
// payment_admin_event_log.php - Admin Payment Events Log (Crash-Proof v1 - No Function Collisions)
// ==============================================================================================

header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/payment_admin_event_log_error.log');
error_reporting(E_ALL);

// DEBUG MODE (show fatal errors safely when ?debug=1)
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            header('Content-Type: text/plain; charset=utf-8');
            echo "FATAL ERROR\n";
            echo $err['message'] . "\n";
            echo "FILE: " . $err['file'] . "\n";
            echo "LINE: " . $err['line'] . "\n";
        }
    });
}

require_once 'auth_admin.php';
require_once 'db_connect.php';
require_once 'PaymentConfig.php';

if (session_status() === PHP_SESSION_NONE) session_start();

/* -----------------------------------------------------------------------------
   CSRF
----------------------------------------------------------------------------- */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
function ev_csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $t = $_POST['csrf_token'] ?? '';
        if (!$t || !hash_equals($_SESSION['csrf_token'], $t)) {
            http_response_code(403);
            die("CSRF token invalid");
        }
    }
}

/* -----------------------------------------------------------------------------
   Helpers (namespaced)
----------------------------------------------------------------------------- */
function ev_h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ev_g($k, $d=''){ return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d; }
function ev_gi($k, $d=0){ return isset($_GET[$k]) ? (int)$_GET[$k] : $d; }

function ev_short($s, $max=220): string {
    $s = trim((string)$s);
    if ($s === '') return '';
    $s = preg_replace('/\s+/', ' ', $s);

    $len = function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
    if ($len <= $max) return $s;

    $sub = function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
    return $sub . '…';
}

function ev_log_err(string $msg): void {
    error_log("[payment_admin_event_log] " . $msg);
}

function ev_tableExists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE ?");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/* -----------------------------------------------------------------------------
   Pre-check tables
----------------------------------------------------------------------------- */
$needTables = ['payment_events','payment_transactions','payment_gateways'];
$missingTables = [];
foreach ($needTables as $t) {
    if (!ev_tableExists($pdo, $t)) $missingTables[] = $t;
}
$canQuery = empty($missingTables);

/* -----------------------------------------------------------------------------
   Filters / Pagination
----------------------------------------------------------------------------- */
$page    = max(1, ev_gi('page', 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$f_txn     = ev_g('txn','');       // txn_id exact
$f_gateway = ev_g('gateway','');   // gateway_code exact
$f_env     = ev_g('env','');       // env exact
$f_type    = ev_g('type','');      // event_type exact
$f_q       = ev_g('q','');         // search in payload/provider_ref
$f_from    = ev_g('from','');      // date
$f_to      = ev_g('to','');        // date

$where  = [];
$params = [];

if ($f_txn !== '')      { $where[] = "e.txn_id = ?"; $params[] = (int)$f_txn; }
if ($f_gateway !== '')  { $where[] = "e.gateway_code = ?"; $params[] = $f_gateway; }
if ($f_env !== '')      { $where[] = "e.env = ?"; $params[] = $f_env; }
if ($f_type !== '')     { $where[] = "e.event_type = ?"; $params[] = $f_type; }

if ($f_from !== '')     { $where[] = "DATE(e.created_at) >= ?"; $params[] = $f_from; }
if ($f_to !== '')       { $where[] = "DATE(e.created_at) <= ?"; $params[] = $f_to; }

if ($f_q !== '') {
    // بحث عام داخل payload + provider_ref من جدول transactions (اختياري)
    $where[] = "(e.payload LIKE ? OR t.provider_ref LIKE ?)";
    $params[] = "%".$f_q."%";
    $params[] = "%".$f_q."%";
}

$whereSql = $where ? ("WHERE ".implode(" AND ", $where)) : "";

/* -----------------------------------------------------------------------------
   Queries (safe)
----------------------------------------------------------------------------- */
$total = 0;
$totalPages = 1;
$rows = [];
$gateways = [];
$types = [];
$sqlError = '';

$viewId = ev_gi('view', 0);
$viewEvent = null;

if ($canQuery) {
    try {
        // count
        $stmtC = $pdo->prepare("
            SELECT COUNT(*)
            FROM payment_events e
            LEFT JOIN payment_transactions t ON t.id = e.txn_id
            $whereSql
        ");
        $stmtC->execute($params);
        $total = (int)$stmtC->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $perPage));

        // list
        $stmt = $pdo->prepare("
            SELECT
                e.*,
                t.order_type,
                t.order_id,
                t.user_id,
                t.provider_ref
            FROM payment_events e
            LEFT JOIN payment_transactions t ON t.id = e.txn_id
            $whereSql
            ORDER BY e.id DESC
            LIMIT $perPage OFFSET $offset
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // gateways dropdown
        $gateways = $pdo->query("SELECT code, name_ar FROM payment_gateways ORDER BY sort_order ASC, id ASC")
                        ->fetchAll(PDO::FETCH_ASSOC);

        // types dropdown (آخر 50 نوع)
        $types = $pdo->query("SELECT DISTINCT event_type FROM payment_events ORDER BY event_type ASC LIMIT 50")
                     ->fetchAll(PDO::FETCH_COLUMN);

        // view
        if ($viewId > 0) {
            $stV = $pdo->prepare("
                SELECT
                    e.*,
                    t.order_type,
                    t.order_id,
                    t.user_id,
                    t.provider_ref
                FROM payment_events e
                LEFT JOIN payment_transactions t ON t.id = e.txn_id
                WHERE e.id = ?
                LIMIT 1
            ");
            $stV->execute([$viewId]);
            $viewEvent = $stV->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (Throwable $e) {
        $sqlError = $e->getMessage();
        ev_log_err("SQL error: ".$e->getMessage());
        $rows = [];
        $gateways = [];
        $types = [];
        $total = 0;
        $totalPages = 1;
        $viewEvent = null;
    }
}

// theme
$admin_dark = ps_getSystemSetting('admin_dark_mode','0') === '1';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>سجل أحداث الدفع</title>

<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="admin-unified-style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">

<style>
:root{--bg:#f6f7fb;--card:#fff;--text:#1f2d3d;--muted:#6b7280;--border:#e8eaee;--primary:#1e88e5;--success:#27ae60;--danger:#e74c3c;--warning:#f39c12;--shadow:0 12px 30px rgba(0,0,0,.06);--radius:16px;}
body.dark{--bg:#0f172a;--card:#101b33;--text:#e5e7eb;--muted:#9ca3af;--border:#1f2a44;--shadow:0 14px 40px rgba(0,0,0,.35);}
body{background:var(--bg);color:var(--text);margin:0;font-family:system-ui,-apple-system,"Segoe UI",Tahoma,Arial}
.main-content{padding:0 10px}
.wrap{max-width:1200px;margin:0 auto;padding:10px 0 40px}
.top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:18px 0 8px}
.title{display:flex;align-items:center;gap:10px;font-weight:1000;font-size:1.2rem}
.pill{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:rgba(30,136,229,.10);border:1px solid rgba(30,136,229,.25);font-weight:900}
.pill.gray{background:rgba(107,114,128,.10);border-color:rgba(107,114,128,.25)}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:16px;margin-bottom:14px}
.notice{padding:12px 14px;border-radius:14px;border:1px solid var(--border);background:var(--card);box-shadow:var(--shadow);display:flex;gap:10px;align-items:flex-start;margin-bottom:12px}
.notice.bad{border-color:rgba(231,76,60,.35)}
.notice.info{border-color:rgba(30,136,229,.35)}
.small{color:var(--muted);font-weight:800;font-size:.9rem;line-height:1.6}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.col{flex:1;min-width:190px}
.label{display:block;margin:8px 0 6px;font-weight:1000}
.input,select{width:100%;padding:11px 12px;border-radius:12px;border:1px solid var(--border);background:transparent;color:var(--text);box-sizing:border-box}
.btn{border:none;border-radius:12px;padding:10px 14px;font-weight:1000;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:.15s}
.btn:hover{transform:translateY(-1px)}
.btn-primary{background:var(--primary);color:#fff}
.btn-outline{background:transparent;color:var(--text);border:1px solid var(--border)}
.btn-light{background:rgba(107,114,128,.12);color:var(--text);border:1px solid var(--border)}
.table{width:100%;border-collapse:separate;border-spacing:0 10px}
.table th{text-align:right;color:var(--muted);font-weight:1000;font-size:.9rem;padding:0 10px}
.table td{background:rgba(107,114,128,.06);border:1px solid var(--border);padding:12px 10px;font-weight:900;vertical-align:top}
.table tr td:first-child{border-top-right-radius:14px;border-bottom-right-radius:14px}
.table tr td:last-child{border-top-left-radius:14px;border-bottom-left-radius:14px}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
.hr{height:1px;background:var(--border);margin:12px 0}
.pagination{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:center;margin-top:10px}
.page-link{padding:8px 12px;border-radius:12px;border:1px solid var(--border);text-decoration:none;color:var(--text);font-weight:1000;background:transparent}
.page-link.active{background:var(--primary);border-color:var(--primary);color:#fff}
pre{white-space:pre-wrap;word-break:break-word;background:rgba(0,0,0,.06);border:1px solid var(--border);padding:12px;border-radius:14px}
body.dark pre{background:rgba(255,255,255,.04)}
</style>
</head>

<body class="<?= $admin_dark ? 'dark' : '' ?>">
<div class="sidebar"><?php include 'sidebar.php'; ?></div>

<div class="main-content">
  <div class="wrap">

    <div class="top">
      <div class="title">
        <i class="fas fa-wave-square"></i>
        <span>سجل أحداث الدفع</span>
        <span class="pill gray"><i class="fas fa-database"></i> إجمالي: <b><?= (int)$total ?></b></span>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a class="btn btn-outline" href="payment_admin_transactions.php"><i class="fas fa-file-invoice-dollar"></i> المعاملات</a>
        <a class="btn btn-outline" href="payment_settings.php"><i class="fas fa-gear"></i> الإعدادات</a>
      </div>
    </div>

    <?php if (!$canQuery): ?>
      <div class="notice bad">
        <i class="fas fa-triangle-exclamation"></i>
        <div>
          <div style="font-weight:1000">جداول الدفع غير جاهزة.</div>
          <div class="small" style="margin-top:6px">الناقص: <span class="mono"><?= ev_h(implode(', ', $missingTables)) ?></span></div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($sqlError): ?>
      <div class="notice bad">
        <i class="fas fa-bug"></i>
        <div>
          <div style="font-weight:1000">خطأ SQL (تم منعه من إسقاط الصفحة)</div>
          <div class="small" style="margin-top:6px"><?= ev_h($sqlError) ?></div>
          <div class="small" style="margin-top:6px">تم تسجيل التفاصيل في: <b>payment_admin_event_log_error.log</b></div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <div style="font-weight:1000"><i class="fas fa-filter"></i> فلترة وبحث</div>
        <div class="small">ابحث داخل payload أو provider_ref، أو فلتر بالبوابة/البيئة/النوع</div>
      </div>

      <form method="GET" class="row" style="margin-top:10px">
        <div class="col">
          <label class="label">TXN ID</label>
          <input class="input mono" name="txn" value="<?= ev_h($f_txn) ?>" placeholder="مثال: 123">
        </div>

        <div class="col">
          <label class="label">البوابة</label>
          <select name="gateway">
            <option value="">الكل</option>
            <?php foreach ($gateways as $g): ?>
              <option value="<?= ev_h($g['code']) ?>" <?= $f_gateway===$g['code']?'selected':'' ?>>
                <?= ev_h($g['name_ar']) ?> (<?= ev_h($g['code']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col">
          <label class="label">البيئة</label>
          <select name="env">
            <option value="">الكل</option>
            <option value="test" <?= $f_env==='test'?'selected':'' ?>>test</option>
            <option value="live" <?= $f_env==='live'?'selected':'' ?>>live</option>
          </select>
        </div>

        <div class="col">
          <label class="label">نوع الحدث</label>
          <select name="type">
            <option value="">الكل</option>
            <?php foreach ($types as $t): ?>
              <option value="<?= ev_h($t) ?>" <?= $f_type===$t?'selected':'' ?>><?= ev_h($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col">
          <label class="label">بحث عام (q)</label>
          <input class="input" name="q" value="<?= ev_h($f_q) ?>" placeholder="جزء من JSON أو provider_ref">
        </div>

        <div class="col">
          <label class="label">من</label>
          <input class="input" type="date" name="from" value="<?= ev_h($f_from) ?>">
        </div>

        <div class="col">
          <label class="label">إلى</label>
          <input class="input" type="date" name="to" value="<?= ev_h($f_to) ?>">
        </div>

        <div class="col" style="flex:0;min-width:200px">
          <button class="btn btn-primary" type="submit"><i class="fas fa-magnifying-glass"></i> تطبيق</button>
          <a class="btn btn-light" href="payment_admin_event_log.php"><i class="fas fa-rotate"></i> إعادة</a>
        </div>
      </form>
    </div>

    <!-- List -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <div style="font-weight:1000"><i class="fas fa-list"></i> الأحداث</div>
        <div class="small">صفحة <?= (int)$page ?> من <?= (int)$totalPages ?></div>
      </div>

      <div style="overflow:auto;margin-top:10px">
        <table class="table">
          <thead>
            <tr>
              <th>ID</th>
              <th>TXN</th>
              <th>النوع</th>
              <th>البوابة/البيئة</th>
              <th>provider_ref</th>
              <th>مختصر payload</th>
              <th>الوقت</th>
              <th>إجراء</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td class="mono">#<?= (int)$r['id'] ?></td>
                <td class="mono"><?= (int)$r['txn_id'] ?></td>
                <td><?= ev_h($r['event_type']) ?></td>
                <td class="mono"><?= ev_h($r['gateway_code']) ?>/<?= ev_h($r['env']) ?></td>
                <td class="mono"><?= ev_h($r['provider_ref'] ?: '—') ?></td>
                <td class="mono"><?= ev_h(ev_short($r['payload'], 260) ?: '—') ?></td>
                <td class="mono"><?= ev_h($r['created_at']) ?></td>
                <td style="min-width:160px">
                  <a class="btn btn-outline" href="?<?= ev_h(http_build_query(array_merge($_GET, ['view'=>(int)$r['id']]))) ?>">
                    <i class="fas fa-eye"></i> تفاصيل
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>

            <?php if (!$rows): ?>
              <tr>
                <td colspan="8" style="text-align:center;background:transparent;border:none;color:var(--muted);font-weight:1000">
                  لا توجد نتائج
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="pagination">
        <?php
          $mk = function($p){ $q = $_GET; $q['page'] = $p; return '?'.http_build_query($q); };
          $start = max(1, $page - 2);
          $end = min($totalPages, $page + 2);
        ?>
        <?php if ($page > 1): ?><a class="page-link" href="<?= ev_h($mk($page-1)) ?>">السابق</a><?php endif; ?>
        <?php for ($i=$start; $i<=$end; $i++): ?>
          <a class="page-link <?= $i===$page?'active':'' ?>" href="<?= ev_h($mk($i)) ?>"><?= (int)$i ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?><a class="page-link" href="<?= ev_h($mk($page+1)) ?>">التالي</a><?php endif; ?>
      </div>
    </div>

    <!-- View -->
    <?php if ($viewEvent): ?>
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
          <div style="font-weight:1000">
            <i class="fas fa-receipt"></i>
            تفاصيل الحدث <span class="mono">#<?= (int)$viewEvent['id'] ?></span>
          </div>
          <?php $qq = $_GET; unset($qq['view']); ?>
          <a class="btn btn-outline" href="payment_admin_event_log.php?<?= ev_h(http_build_query($qq)) ?>"><i class="fas fa-xmark"></i> إغلاق</a>
        </div>

        <div class="hr"></div>

        <div class="row">
          <div class="col">
            <div class="small">TXN</div>
            <div class="mono" style="font-weight:1000">#<?= (int)$viewEvent['txn_id'] ?></div>
            <div style="height:8px"></div>
            <a class="btn btn-light" href="payment_admin_transactions.php?view=<?= (int)$viewEvent['txn_id'] ?>" target="_blank">
              <i class="fas fa-up-right-from-square"></i> فتح المعاملة
            </a>
          </div>

          <div class="col">
            <div class="small">النوع</div>
            <div style="font-weight:1000"><?= ev_h($viewEvent['event_type']) ?></div>
            <div style="height:8px"></div>
            <div class="small">البوابة/البيئة</div>
            <div class="mono" style="font-weight:1000"><?= ev_h($viewEvent['gateway_code']) ?>/<?= ev_h($viewEvent['env']) ?></div>
          </div>

          <div class="col">
            <div class="small">provider_ref</div>
            <div class="mono" style="font-weight:1000"><?= ev_h($viewEvent['provider_ref'] ?? '—') ?></div>
            <div style="height:8px"></div>
            <div class="small">الوقت</div>
            <div class="mono" style="font-weight:1000"><?= ev_h($viewEvent['created_at']) ?></div>
          </div>
        </div>

        <div class="hr"></div>

        <div style="font-weight:1000;margin-bottom:8px"><i class="fas fa-code"></i> Payload</div>
        <pre><?= ev_h($viewEvent['payload'] ?: '—') ?></pre>
      </div>
    <?php endif; ?>

  </div>
</div>

</body>
</html>