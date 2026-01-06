<?php
// payment_admin_transactions.php - Admin Payment Transactions Center (Crash-Proof v4.1)
// - No function collisions (all helpers prefixed + guarded)
// - Safe debug (?debug=1) prints fatal errors as plain text
// - Avoid "headers already sent" issues by starting output buffering early
// ==============================================================================

/* -----------------------------
   0) Output buffering + headers
------------------------------ */
if (!headers_sent()) {
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header('Content-Type: text/html; charset=utf-8');
}
if (!ob_get_level()) { ob_start(); }

/* -----------------------------
   1) Error logging
------------------------------ */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/payment_admin_transactions_error.log');
error_reporting(E_ALL);

// DEBUG MODE (show fatal errors safely when ?debug=1)
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
            echo "FATAL ERROR\n";
            echo $err['message'] . "\n";
            echo "FILE: " . $err['file'] . "\n";
            echo "LINE: " . $err['line'] . "\n";
        }
    });
}

/* -----------------------------
   2) Session FIRST (before includes that may redirect)
------------------------------ */
if (session_status() === PHP_SESSION_NONE) { @session_start(); }

/* -----------------------------
   3) Includes
------------------------------ */
require_once 'auth_admin.php';
require_once 'db_connect.php';
require_once 'PaymentConfig.php';

// optional deps (won't break page if missing)
$HAS_ENGINE = false;
$HAS_APPLY  = false;
if (file_exists(__DIR__ . '/PaymentEngine.php')) { require_once 'PaymentEngine.php'; $HAS_ENGINE = class_exists('PaymentEngine'); }
if (file_exists(__DIR__ . '/PaymentApply.php'))  { require_once 'PaymentApply.php';  $HAS_APPLY  = class_exists('PaymentApply');  }

/* -----------------------------
   4) Helpers (guarded, prefixed)
------------------------------ */
if (!function_exists('tx_csrf_check')) {
    function tx_csrf_check(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $t = $_POST['csrf_token'] ?? '';
            if (!$t || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $t)) {
                http_response_code(403);
                die("CSRF token invalid");
            }
        }
    }
}
if (!function_exists('tx_h')) {
    function tx_h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('tx_g')) {
    function tx_g($k, $d=''){ return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d; }
}
if (!function_exists('tx_gi')) {
    function tx_gi($k, $d=0){ return isset($_GET[$k]) ? (int)$_GET[$k] : $d; }
}
if (!function_exists('tx_p')) {
    function tx_p($k, $d=''){ return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }
}
if (!function_exists('tx_pi')) {
    function tx_pi($k, $d=0){ return isset($_POST[$k]) ? (int)$_POST[$k] : $d; }
}
if (!function_exists('tx_badgeClass')) {
    function tx_badgeClass(string $st): string {
        return match ($st) {
            'paid' => 'success',
            'failed' => 'danger',
            'pending', 'created' => 'warning',
            'canceled', 'refunded' => 'muted',
            default => 'muted'
        };
    }
}
if (!function_exists('tx_statusLabel')) {
    function tx_statusLabel(string $st): string {
        return match ($st) {
            'paid' => 'مدفوع',
            'failed' => 'فشل',
            'pending' => 'معلّق',
            'created' => 'مُنشأ',
            'canceled' => 'ملغي',
            'refunded' => 'مسترجع',
            default => $st
        };
    }
}
if (!function_exists('tx_shortJson')) {
    function tx_shortJson($s, $max=180): string {
        $s = trim((string)$s);
        if ($s === '') return '';
        $s = preg_replace('/\s+/', ' ', $s);

        $len = function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
        if ($len <= $max) return $s;

        $sub = function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
        return $sub . '…';
    }
}
if (!function_exists('tx_log_err')) {
    function tx_log_err(string $msg): void {
        error_log("[payment_admin_transactions] " . $msg);
    }
}
if (!function_exists('tx_tableExists')) {
    function tx_tableExists(PDO $pdo, string $table): bool {
        try {
            $st = $pdo->prepare("SHOW TABLES LIKE ?");
            $st->execute([$table]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}
if (!function_exists('tx_flash_set')) {
    function tx_flash_set(string $type, string $msg, string $info=''): void {
        $_SESSION['flash_pay_admin_txn'] = ['type'=>$type,'msg'=>$msg,'info'=>$info];
    }
}
if (!function_exists('tx_flash_get')) {
    function tx_flash_get(): array {
        $f = $_SESSION['flash_pay_admin_txn'] ?? ['type'=>'','msg'=>'','info'=>''];
        unset($_SESSION['flash_pay_admin_txn']);
        return $f;
    }
}

/* -----------------------------
   5) CSRF token create
------------------------------ */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

/* -----------------------------
   6) Pre-check tables
------------------------------ */
$needTables = ['payment_transactions','payment_events','payment_fulfillments','payment_gateways'];
$missingTables = [];
foreach ($needTables as $t) {
    if (!tx_tableExists($pdo, $t)) $missingTables[] = $t;
}
$canQuery = empty($missingTables);

/* -----------------------------
   7) Actions
------------------------------ */
tx_csrf_check();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canQuery) {

    // Apply paid transaction (idempotent)
    if (isset($_POST['apply_paid'])) {
        $txnId = tx_pi('txn_id', 0);

        if ($txnId <= 0) {
            tx_flash_set('bad', 'معاملة غير صحيحة.');
        } elseif (!$HAS_APPLY) {
            tx_flash_set('bad', 'PaymentApply غير متاح. زر Apply معطل.');
        } else {
            try {
                $res = PaymentApply::applyPaidTxn($txnId);
                if (!empty($res['ok'])) {
                    $msg  = !empty($res['already_applied']) ? "✅ تم تطبيق الدفع مسبقاً (لا تكرار)." : "✅ تم تطبيق الدفع بنجاح.";
                    $info = '';
                    if (!empty($res['applied_to'])) $info = "Applied to: ".$res['applied_to']." — ".($res['note'] ?? '');
                    tx_flash_set('ok', $msg, $info);
                } else {
                    tx_flash_set('bad', 'فشل Apply: '.($res['error'] ?? 'UNKNOWN'));
                }
            } catch (Throwable $e) {
                tx_log_err("Apply error: ".$e->getMessage());
                tx_flash_set('bad', 'Apply exception: '.$e->getMessage());
            }
        }

        header("Location: payment_admin_transactions.php?".http_build_query($_GET));
        exit;
    }

    // Manual status override
    if (isset($_POST['force_status'])) {
        $txnId = tx_pi('txn_id', 0);
        $st = tx_p('new_status', '');
        $allowed = ['created','pending','paid','failed','canceled','refunded'];

        if ($txnId <= 0 || !in_array($st, $allowed, true)) {
            tx_flash_set('bad', 'بيانات غير صحيحة.');
        } elseif (!$HAS_ENGINE) {
            tx_flash_set('bad', 'PaymentEngine غير متاح. تعديل الحالة معطل.');
        } else {
            try {
                PaymentEngine::setTxnStatus($txnId, $st, null, ['ok'=>true,'note'=>'manual override by admin']);

                $info = '';
                if ($st === 'paid' && $HAS_APPLY) {
                    $res = PaymentApply::applyPaidTxn($txnId);
                    $info = !empty($res['ok']) ? "✅ تم Apply بعد paid." : ("paid تم وضعها لكن Apply فشل: ".($res['error'] ?? 'UNKNOWN'));
                }
                tx_flash_set('ok', '✅ تم تعديل الحالة.', $info);
            } catch (Throwable $e) {
                tx_log_err("Force status error: ".$e->getMessage());
                tx_flash_set('bad', 'Status exception: '.$e->getMessage());
            }
        }

        header("Location: payment_admin_transactions.php?".http_build_query($_GET));
        exit;
    }
}

/* -----------------------------
   8) Filters / Pagination
------------------------------ */
$page = max(1, tx_gi('page', 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$f_status     = tx_g('status','');
$f_gateway    = tx_g('gateway','');
$f_env        = tx_g('env','');
$f_q          = tx_g('q','');
$f_order_type = tx_g('order_type','');
$f_date_from  = tx_g('from','');
$f_date_to    = tx_g('to','');

$where  = [];
$params = [];

if ($f_status !== '')     { $where[] = "t.status = ?";      $params[] = $f_status; }
if ($f_gateway !== '')    { $where[] = "t.gateway_code = ?";$params[] = $f_gateway; }
if ($f_env !== '')        { $where[] = "t.env = ?";         $params[] = $f_env; }
if ($f_order_type !== '') { $where[] = "t.order_type = ?";  $params[] = $f_order_type; }
if ($f_date_from !== '')  { $where[] = "DATE(t.created_at) >= ?"; $params[] = $f_date_from; }
if ($f_date_to !== '')    { $where[] = "DATE(t.created_at) <= ?"; $params[] = $f_date_to; }

if ($f_q !== '') {
    $where[] = "(t.id = ? OR t.provider_ref LIKE ? OR t.order_id = ? OR t.user_id = ?)";
    $params[] = (int)$f_q;
    $params[] = "%".$f_q."%";
    $params[] = (int)$f_q;
    $params[] = (int)$f_q;
}

$whereSql = $where ? ("WHERE ".implode(" AND ", $where)) : "";

/* -----------------------------
   9) Queries (safe)
------------------------------ */
$total = 0;
$totalPages = 1;
$rows = [];
$gateways = [];

$viewId = tx_gi('view', 0);
$viewTxn = null;
$viewEvents = [];

$sqlError = '';

if ($canQuery) {
    try {
        // count
        $stmtC = $pdo->prepare("SELECT COUNT(*) FROM payment_transactions t $whereSql");
        $stmtC->execute($params);
        $total = (int)$stmtC->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $perPage));

        // list
        $sql = "
          SELECT
            t.*,
            (SELECT COUNT(*) FROM payment_events e WHERE e.txn_id = t.id) AS events_count,
            (SELECT COUNT(*) FROM payment_fulfillments f WHERE f.txn_id = t.id) AS applied_count
          FROM payment_transactions t
          $whereSql
          ORDER BY t.id DESC
          LIMIT $perPage OFFSET $offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // gateways dropdown
        $gateways = $pdo->query("SELECT code, name_ar FROM payment_gateways ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

        // view details
        if ($viewId > 0) {
            $stmtV = $pdo->prepare("
                SELECT
                  t.*,
                  (SELECT COUNT(*) FROM payment_fulfillments f WHERE f.txn_id = t.id) AS applied_count
                FROM payment_transactions t
                WHERE t.id=? LIMIT 1
            ");
            $stmtV->execute([$viewId]);
            $viewTxn = $stmtV->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($viewTxn) {
                $stmtE = $pdo->prepare("SELECT * FROM payment_events WHERE txn_id=? ORDER BY id DESC LIMIT 25");
                $stmtE->execute([$viewId]);
                $viewEvents = $stmtE->fetchAll(PDO::FETCH_ASSOC);
            }
        }

    } catch (Throwable $e) {
        $sqlError = $e->getMessage();
        tx_log_err("SQL error: ".$e->getMessage());
        $rows = [];
        $gateways = [];
        $total = 0;
        $totalPages = 1;
        $viewTxn = null;
        $viewEvents = [];
    }
}

// theme (from system_settings via PaymentConfig helpers)
$admin_dark = (function_exists('ps_getSystemSetting') ? (ps_getSystemSetting('admin_dark_mode','0') === '1') : false);
$flash = tx_flash_get();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>معاملات الدفع</title>

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
.notice.ok{border-color:rgba(39,174,96,.35)}
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
.btn-success{background:var(--success);color:#fff}
.btn-danger{background:var(--danger);color:#fff}
.btn-light{background:rgba(107,114,128,.12);color:var(--text);border:1px solid var(--border)}
.btn-disabled{opacity:.45;cursor:not-allowed}
.table{width:100%;border-collapse:separate;border-spacing:0 10px}
.table th{text-align:right;color:var(--muted);font-weight:1000;font-size:.9rem;padding:0 10px}
.table td{background:rgba(107,114,128,.06);border:1px solid var(--border);padding:12px 10px;font-weight:900}
.table tr td:first-child{border-top-right-radius:14px;border-bottom-right-radius:14px}
.table tr td:last-child{border-top-left-radius:14px;border-bottom-left-radius:14px}
.badge{display:inline-flex;align-items:center;gap:8px;padding:8px 10px;border-radius:999px;border:1px solid var(--border);font-weight:1000}
.badge.success{background:rgba(39,174,96,.12);border-color:rgba(39,174,96,.25)}
.badge.danger{background:rgba(231,76,60,.12);border-color:rgba(231,76,60,.25)}
.badge.warning{background:rgba(243,156,18,.12);border-color:rgba(243,156,18,.25)}
.badge.muted{background:rgba(107,114,128,.10);border-color:rgba(107,114,128,.25)}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
.hr{height:1px;background:var(--border);margin:12px 0}
.pagination{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:center;margin-top:10px}
.page-link{padding:8px 12px;border-radius:12px;border:1px solid var(--border);text-decoration:none;color:var(--text);font-weight:1000;background:transparent}
.page-link.active{background:var(--primary);border-color:var(--primary);color:#fff}
.kv{display:grid;grid-template-columns:1fr 2fr;gap:10px}
@media(max-width:900px){.kv{grid-template-columns:1fr}}
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
        <i class="fas fa-file-invoice-dollar"></i>
        <span>معاملات الدفع</span>
        <span class="pill gray"><i class="fas fa-database"></i> إجمالي: <b><?= (int)$total ?></b></span>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a class="btn btn-outline" href="payment_settings.php"><i class="fas fa-gear"></i> إعدادات الدفع</a>
        <a class="btn btn-outline" href="payment_admin_event_log.php"><i class="fas fa-wave-square"></i> سجل الأحداث</a>
      </div>
    </div>

    <?php if (!empty($flash['msg'])): ?>
      <div class="notice <?= $flash['type']==='ok' ? 'ok' : 'bad' ?>">
        <i class="fas <?= $flash['type']==='ok' ? 'fa-check-circle' : 'fa-triangle-exclamation' ?>"></i>
        <div>
          <?= tx_h($flash['msg']) ?>
          <?php if (!empty($flash['info'])): ?><div class="small" style="margin-top:6px"><?= tx_h($flash['info']) ?></div><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!$canQuery): ?>
      <div class="notice bad">
        <i class="fas fa-triangle-exclamation"></i>
        <div>
          <div style="font-weight:1000">جداول الدفع غير جاهزة — لا يمكن عرض المعاملات.</div>
          <div class="small" style="margin-top:6px">الناقص: <span class="mono"><?= tx_h(implode(', ', $missingTables)) ?></span></div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($sqlError): ?>
      <div class="notice bad">
        <i class="fas fa-bug"></i>
        <div>
          <div style="font-weight:1000">خطأ SQL (تم منعه من إسقاط الصفحة)</div>
          <div class="small" style="margin-top:6px"><?= tx_h($sqlError) ?></div>
          <div class="small" style="margin-top:6px">تم تسجيل التفاصيل في: <b>payment_admin_transactions_error.log</b></div>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!$HAS_ENGINE || !$HAS_APPLY): ?>
      <div class="notice info">
        <i class="fas fa-circle-info"></i>
        <div class="small">
          <?php if (!$HAS_ENGINE): ?>- PaymentEngine غير متاح ⇒ تعديل الحالة اليدوي معطل.<br><?php endif; ?>
          <?php if (!$HAS_APPLY): ?>- PaymentApply غير متاح ⇒ زر Apply معطل.<br><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <div style="font-weight:1000"><i class="fas fa-filter"></i> فلترة وبحث</div>
        <div class="small">ابحث بالـ TXN أو رقم المستخدم أو رقم الطلب أو provider_ref</div>
      </div>

      <form method="GET" class="row" style="margin-top:10px">
        <div class="col">
          <label class="label">بحث (q)</label>
          <input class="input mono" name="q" value="<?= tx_h($f_q) ?>" placeholder="TXN / user_id / order_id / provider_ref">
        </div>

        <div class="col">
          <label class="label">الحالة</label>
          <select name="status">
            <option value="">الكل</option>
            <?php foreach (['created','pending','paid','failed','canceled','refunded'] as $st): ?>
              <option value="<?= tx_h($st) ?>" <?= $f_status===$st?'selected':'' ?>><?= tx_h(tx_statusLabel($st)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col">
          <label class="label">البوابة</label>
          <select name="gateway">
            <option value="">الكل</option>
            <?php foreach ($gateways as $gw): ?>
              <option value="<?= tx_h($gw['code']) ?>" <?= $f_gateway===$gw['code']?'selected':'' ?>>
                <?= tx_h($gw['name_ar']) ?> (<?= tx_h($gw['code']) ?>)
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
          <label class="label">order_type</label>
          <input class="input" name="order_type" value="<?= tx_h($f_order_type) ?>" placeholder="package_subscription / order ...">
        </div>

        <div class="col">
          <label class="label">من</label>
          <input class="input" type="date" name="from" value="<?= tx_h($f_date_from) ?>">
        </div>

        <div class="col">
          <label class="label">إلى</label>
          <input class="input" type="date" name="to" value="<?= tx_h($f_date_to) ?>">
        </div>

        <div class="col" style="flex:0;min-width:200px">
          <button class="btn btn-primary" type="submit"><i class="fas fa-magnifying-glass"></i> تطبيق</button>
          <a class="btn btn-light" href="payment_admin_transactions.php"><i class="fas fa-rotate"></i> إعادة</a>
        </div>
      </form>
    </div>

    <!-- List -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <div style="font-weight:1000"><i class="fas fa-list"></i> القائمة</div>
        <div class="small">صفحة <?= (int)$page ?> من <?= (int)$totalPages ?></div>
      </div>

      <div style="overflow:auto;margin-top:10px">
        <table class="table">
          <thead>
            <tr>
              <th>TXN</th><th>الحالة</th><th>المبلغ</th><th>البوابة</th><th>الطلب</th><th>المستخدم</th>
              <th>Provider Ref</th><th>Events</th><th>Applied</th><th>وقت</th><th>إجراءات</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td class="mono">#<?= (int)$r['id'] ?></td>
                <td><span class="badge <?= tx_h(tx_badgeClass((string)$r['status'])) ?>"><?= tx_h(tx_statusLabel((string)$r['status'])) ?></span></td>
                <td><?= tx_h($r['amount']) ?> <?= tx_h($r['currency']) ?></td>
                <td class="mono"><?= tx_h($r['gateway_code']) ?> / <?= tx_h($r['env']) ?></td>
                <td><?= tx_h($r['order_type']) ?> <span class="mono">#<?= tx_h($r['order_id']) ?></span></td>
                <td class="mono">#<?= tx_h($r['user_id']) ?></td>
                <td class="mono"><?= tx_h($r['provider_ref'] ?: '—') ?></td>
                <td class="mono"><?= (int)$r['events_count'] ?></td>
                <td><?= ((int)$r['applied_count'] > 0) ? '✅' : '—' ?></td>
                <td class="mono"><?= tx_h($r['created_at']) ?></td>
                <td style="min-width:260px">
                  <a class="btn btn-outline" href="?<?= tx_h(http_build_query(array_merge($_GET, ['view'=>(int)$r['id']]))) ?>">
                    <i class="fas fa-eye"></i> تفاصيل
                  </a>

                  <?php if ((string)$r['status'] === 'paid'): ?>
                    <form method="POST" style="display:inline-block;margin:0">
                      <input type="hidden" name="csrf_token" value="<?= tx_h($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="txn_id" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-success <?= $HAS_APPLY ? '' : 'btn-disabled' ?>" name="apply_paid" type="submit" <?= $HAS_APPLY ? '' : 'disabled' ?>>
                        <i class="fas fa-bolt"></i> Apply
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>

            <?php if (!$rows): ?>
              <tr><td colspan="11" style="text-align:center;background:transparent;border:none;color:var(--muted);font-weight:1000">
                لا توجد نتائج
              </td></tr>
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
        <?php if ($page > 1): ?><a class="page-link" href="<?= tx_h($mk($page-1)) ?>">السابق</a><?php endif; ?>
        <?php for ($i=$start; $i<=$end; $i++): ?>
          <a class="page-link <?= $i===$page?'active':'' ?>" href="<?= tx_h($mk($i)) ?>"><?= (int)$i ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?><a class="page-link" href="<?= tx_h($mk($page+1)) ?>">التالي</a><?php endif; ?>
      </div>
    </div>

    <?php if ($viewTxn): ?>
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
          <div style="font-weight:1000"><i class="fas fa-receipt"></i> تفاصيل المعاملة <span class="mono">#<?= (int)$viewTxn['id'] ?></span></div>
          <?php $qq = $_GET; unset($qq['view']); ?>
          <a class="btn btn-outline" href="payment_admin_transactions.php?<?= tx_h(http_build_query($qq)) ?>"><i class="fas fa-xmark"></i> إغلاق</a>
        </div>

        <div class="hr"></div>

        <div class="kv">
          <div class="small">الحالة</div>
          <div><span class="badge <?= tx_h(tx_badgeClass((string)$viewTxn['status'])) ?>"><?= tx_h(tx_statusLabel((string)$viewTxn['status'])) ?></span></div>

          <div class="small">المبلغ</div>
          <div><?= tx_h($viewTxn['amount']) ?> <?= tx_h($viewTxn['currency']) ?></div>

          <div class="small">البوابة/البيئة</div>
          <div class="mono"><?= tx_h($viewTxn['gateway_code']) ?>/<?= tx_h($viewTxn['env']) ?></div>

          <div class="small">الطلب</div>
          <div><?= tx_h($viewTxn['order_type']) ?> — <span class="mono">#<?= tx_h($viewTxn['order_id']) ?></span></div>

          <div class="small">المستخدم</div>
          <div class="mono">#<?= tx_h($viewTxn['user_id']) ?></div>

          <div class="small">Provider Ref</div>
          <div class="mono"><?= tx_h($viewTxn['provider_ref'] ?: '—') ?></div>
        </div>

        <div class="hr"></div>

        <div class="row" style="align-items:end">
          <?php if ((string)$viewTxn['status'] === 'paid'): ?>
            <form method="POST" style="margin:0">
              <input type="hidden" name="csrf_token" value="<?= tx_h($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="txn_id" value="<?= (int)$viewTxn['id'] ?>">
              <button class="btn btn-success <?= $HAS_APPLY ? '' : 'btn-disabled' ?>" name="apply_paid" type="submit" <?= $HAS_APPLY ? '' : 'disabled' ?>>
                <i class="fas fa-bolt"></i> Apply Paid
              </button>
            </form>
          <?php endif; ?>

          <form method="POST" class="row" style="margin:0;align-items:end">
            <input type="hidden" name="csrf_token" value="<?= tx_h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="txn_id" value="<?= (int)$viewTxn['id'] ?>">
            <div class="col" style="min-width:220px">
              <label class="label">تعديل الحالة (يدوي)</label>
              <select name="new_status" <?= $HAS_ENGINE ? '' : 'disabled' ?>>
                <?php foreach (['created','pending','paid','failed','canceled','refunded'] as $st): ?>
                  <option value="<?= tx_h($st) ?>" <?= ((string)$viewTxn['status']===$st)?'selected':'' ?>><?= tx_h(tx_statusLabel($st)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col" style="flex:0;min-width:220px">
              <button class="btn btn-danger <?= $HAS_ENGINE ? '' : 'btn-disabled' ?>" name="force_status" type="submit" <?= $HAS_ENGINE ? '' : 'disabled' ?>>
                <i class="fas fa-pen"></i> حفظ الحالة
              </button>
            </div>
          </form>
        </div>

        <div class="hr"></div>

        <div style="font-weight:1000;margin-bottom:8px"><i class="fas fa-code"></i> provider_payload</div>
        <pre><?= tx_h($viewTxn['provider_payload'] ?: '—') ?></pre>

        <div class="hr"></div>

        <div style="font-weight:1000;margin-bottom:8px"><i class="fas fa-wave-square"></i> آخر الأحداث</div>
        <?php if (!$viewEvents): ?>
          <div class="small">لا توجد أحداث.</div>
        <?php else: ?>
          <div style="overflow:auto">
            <table class="table">
              <thead><tr><th>ID</th><th>نوع</th><th>بوابة/بيئة</th><th>وقت</th><th>مختصر</th></tr></thead>
              <tbody>
              <?php foreach ($viewEvents as $e): ?>
                <tr>
                  <td class="mono">#<?= (int)$e['id'] ?></td>
                  <td><?= tx_h($e['event_type']) ?></td>
                  <td class="mono"><?= tx_h($e['gateway_code']) ?>/<?= tx_h($e['env']) ?></td>
                  <td class="mono"><?= tx_h($e['created_at']) ?></td>
                  <td class="mono"><?= tx_h(tx_shortJson($e['payload'], 220) ?: '—') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </div>
</div>

<?php
// flush buffer at the very end
if (ob_get_level()) { ob_end_flush(); }
?>
</body>
</html>