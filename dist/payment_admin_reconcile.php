<?php
// payment_admin_reconcile.php - Admin Reconcile / Verify Pending Transactions
// ==============================================================================
header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'auth_admin.php';
require_once 'db_connect.php';
require_once 'PaymentConfig.php';
require_once 'PaymentEngine.php';
require_once 'PaymentApply.php';

if (session_status() === PHP_SESSION_NONE) session_start();

/* =============================================================================
   CSRF
============================================================================= */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $t = $_POST['csrf_token'] ?? '';
        if (!$t || !hash_equals($_SESSION['csrf_token'], $t)) {
            http_response_code(403);
            die("CSRF token invalid");
        }
    }
}

/* =============================================================================
   Helpers
============================================================================= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function g($k,$d=''){ return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d; }
function gi($k,$d=0){ return isset($_GET[$k]) ? (int)$_GET[$k] : $d; }
function p($k,$d=''){ return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }
function pi($k,$d=0){ return isset($_POST[$k]) ? (int)$_POST[$k] : $d; }

function badgeClass(string $st): string {
    switch ($st) {
        case 'paid': return 'success';
        case 'failed': return 'danger';
        case 'pending':
        case 'created': return 'warning';
        default: return 'muted';
    }
}
function statusLabel(string $st): string {
    switch ($st) {
        case 'paid': return 'مدفوع';
        case 'failed': return 'فشل';
        case 'pending': return 'معلّق';
        case 'created': return 'مُنشأ';
        case 'canceled': return 'ملغي';
        case 'refunded': return 'مسترجع';
        default: return $st;
    }
}

/* =============================================================================
   Verification helpers
============================================================================= */

// Moyasar verify by payment id (provider_ref or in payload)
function verify_moyasar(int $txnId, array $txn): array
{
    $env = $txn['env'] ?? ps_getPaymentEnv();
    $secret = pg_getSetting('moyasar', $env, 'secret_key', '');
    if ($secret === '') return ['ok'=>false,'error'=>'MOYASAR_SECRET_KEY_MISSING'];

    // Try provider_ref first (may store payment id)
    $paymentId = (string)($txn['provider_ref'] ?? '');

    // Try extract from provider_payload if empty
    if ($paymentId === '' && !empty($txn['provider_payload'])) {
        $d = json_decode((string)$txn['provider_payload'], true);
        if (is_array($d)) {
            if (!empty($d['payment']['id'])) $paymentId = (string)$d['payment']['id'];
            if (!empty($d['id'])) $paymentId = (string)$d['id'];
        }
    }

    if ($paymentId === '') return ['ok'=>false,'error'=>'MOYASAR_PAYMENT_ID_MISSING'];

    $url = "https://api.moyasar.com/v1/payments/" . rawurlencode($paymentId);
    $auth = base64_encode($secret . ":");

    $r = PaymentEngine::httpJson("GET", $url, [
        "Authorization: Basic ".$auth,
        "Accept: application/json",
    ]);

    $data = json_decode((string)($r['body'] ?? ''), true);
    if (!is_array($data)) $data = ['raw'=>$r['body'] ?? null];

    if (!empty($r['error'])) return ['ok'=>false,'error'=>'MOYASAR_HTTP_ERROR','http'=>$r,'data'=>$data];
    if ((int)($r['code'] ?? 0) >= 400) return ['ok'=>false,'error'=>'MOYASAR_BAD_HTTP','http'=>$r,'data'=>$data];

    $st = (string)($data['status'] ?? '');
    $isPaid = ($st === 'paid' || $st === 'captured' || $st === 'authorized');

    if ($isPaid) {
        PaymentEngine::setTxnStatus($txnId, 'paid', $paymentId, ['ok'=>true,'verify'=>'moyasar','payment'=>$data]);
        PaymentApply::applyPaidTxn($txnId);
        return ['ok'=>true,'new_status'=>'paid','provider_ref'=>$paymentId,'status'=>$st];
    }

    // if explicitly failed
    if ($st === 'failed') {
        PaymentEngine::setTxnStatus($txnId, 'failed', $paymentId, ['ok'=>false,'verify'=>'moyasar','payment'=>$data]);
        return ['ok'=>true,'new_status'=>'failed','provider_ref'=>$paymentId,'status'=>$st];
    }

    // keep pending
    PaymentEngine::setTxnStatus($txnId, 'pending', $paymentId, ['ok'=>true,'verify'=>'moyasar','status'=>$st,'payment'=>$data]);
    return ['ok'=>true,'new_status'=>'pending','provider_ref'=>$paymentId,'status'=>$st];
}

// HyperPay verify by checkoutId (provider_ref)
// uses GET /v1/checkouts/{id}/payment?entityId=...
function verify_hyperpay(int $txnId, array $txn): array
{
    $env = $txn['env'] ?? ps_getPaymentEnv();

    $baseUrl     = rtrim(pg_getSetting('hyperpay', $env, 'base_url', ''), '/');
    $entityId    = pg_getSetting('hyperpay', $env, 'entity_id', '');
    $accessToken = pg_getSetting('hyperpay', $env, 'access_token', '');

    if ($baseUrl === '' || $entityId === '' || $accessToken === '') {
        return ['ok'=>false,'error'=>'HYPERPAY_KEYS_MISSING'];
    }

    $checkoutId = (string)($txn['provider_ref'] ?? '');
    if ($checkoutId === '') return ['ok'=>false,'error'=>'HYPERPAY_CHECKOUTID_MISSING'];

    $url = $baseUrl . "/v1/checkouts/" . rawurlencode($checkoutId) . "/payment?entityId=" . rawurlencode($entityId);

    $r = PaymentEngine::httpJson("GET", $url, [
        "Authorization: Bearer ".$accessToken,
        "Accept: application/json",
    ]);

    $data = json_decode((string)($r['body'] ?? ''), true);
    if (!is_array($data)) $data = ['raw'=>$r['body'] ?? null];

    if (!empty($r['error'])) return ['ok'=>false,'error'=>'HYPERPAY_HTTP_ERROR','http'=>$r,'data'=>$data];
    if ((int)($r['code'] ?? 0) >= 400) return ['ok'=>false,'error'=>'HYPERPAY_BAD_HTTP','http'=>$r,'data'=>$data];

    $resultCode = (string)($data['result']['code'] ?? '');
    $resultDesc = (string)($data['result']['description'] ?? '');
    $paymentId  = (string)($data['id'] ?? $checkoutId);

    // Success typical: 000.* (general)
    $isSuccess = (strpos($resultCode, '000.') === 0);

    if ($isSuccess) {
        PaymentEngine::setTxnStatus($txnId, 'paid', $paymentId, ['ok'=>true,'verify'=>'hyperpay','code'=>$resultCode,'desc'=>$resultDesc,'verify_data'=>$data]);
        PaymentApply::applyPaidTxn($txnId);
        return ['ok'=>true,'new_status'=>'paid','provider_ref'=>$paymentId,'code'=>$resultCode,'desc'=>$resultDesc];
    }

    // If code indicates failure (many patterns exist); we'll mark failed only if clearly not pending.
    // Otherwise keep pending.
    $isClearlyFailed = ($resultCode !== '' && strpos($resultCode, '100.') === 0); // broad "request invalid" family
    if ($isClearlyFailed) {
        PaymentEngine::setTxnStatus($txnId, 'failed', $paymentId, ['ok'=>false,'verify'=>'hyperpay','code'=>$resultCode,'desc'=>$resultDesc,'verify_data'=>$data]);
        return ['ok'=>true,'new_status'=>'failed','provider_ref'=>$paymentId,'code'=>$resultCode,'desc'=>$resultDesc];
    }

    PaymentEngine::setTxnStatus($txnId, 'pending', $paymentId, ['ok'=>true,'verify'=>'hyperpay','code'=>$resultCode,'desc'=>$resultDesc,'verify_data'=>$data]);
    return ['ok'=>true,'new_status'=>'pending','provider_ref'=>$paymentId,'code'=>$resultCode,'desc'=>$resultDesc];
}

/* =============================================================================
   Actions
============================================================================= */
csrf_check();

$success = '';
$error = '';
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['verify_one'])) {
        $txnId = pi('txn_id', 0);
        $txn = PaymentEngine::getTxn($txnId);

        if (!$txn) {
            $error = "TXN غير موجود.";
        } else {
            $gw = (string)$txn['gateway_code'];
            if ($gw === 'moyasar') $results[] = ['txn'=>$txnId, 'gateway'=>'moyasar'] + verify_moyasar($txnId, $txn);
            elseif ($gw === 'hyperpay') $results[] = ['txn'=>$txnId, 'gateway'=>'hyperpay'] + verify_hyperpay($txnId, $txn);
            elseif ($gw === 'paytabs') $results[] = ['txn'=>$txnId, 'gateway'=>'paytabs', 'ok'=>true, 'new_status'=>($txn['status'] ?? ''), 'note'=>'PayTabs يعتمد على webhook للتأكيد'];
            else $results[] = ['txn'=>$txnId, 'gateway'=>$gw, 'ok'=>false, 'error'=>'UNKNOWN_GATEWAY'];
            $success = "تم تنفيذ التحقق.";
        }
    }

    if (isset($_POST['verify_batch'])) {
        $limit = max(1, min(50, pi('limit', 20)));
        $env = p('env', '');
        $gateway = p('gateway','');

        $where = "WHERE status IN ('created','pending')";
        $params = [];

        if ($env !== '' && in_array($env, ['test','live'], true)) { $where .= " AND env=?"; $params[] = $env; }
        if ($gateway !== '') { $where .= " AND gateway_code=?"; $params[] = $gateway; }

        $stmt = $pdo->prepare("SELECT * FROM payment_transactions $where ORDER BY id DESC LIMIT $limit");
        $stmt->execute($params);
        $txns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($txns as $t) {
            $id = (int)$t['id'];
            $gw = (string)$t['gateway_code'];
            if ($gw === 'moyasar') $results[] = ['txn'=>$id, 'gateway'=>'moyasar'] + verify_moyasar($id, $t);
            elseif ($gw === 'hyperpay') $results[] = ['txn'=>$id, 'gateway'=>'hyperpay'] + verify_hyperpay($id, $t);
            elseif ($gw === 'paytabs') $results[] = ['txn'=>$id, 'gateway'=>'paytabs', 'ok'=>true, 'new_status'=>($t['status'] ?? ''), 'note'=>'PayTabs يعتمد على webhook للتأكيد'];
            else $results[] = ['txn'=>$id, 'gateway'=>$gw, 'ok'=>false, 'error'=>'UNKNOWN_GATEWAY'];
        }

        $success = "تم تنفيذ التحقق للدفعة.";
    }
}

/* =============================================================================
   List pending
============================================================================= */
$f_gateway = g('gateway','');
$f_env = g('env','');
$f_limit = max(10, min(200, gi('limit', 50)));

$where = "WHERE status IN ('created','pending')";
$params = [];

if ($f_env !== '' && in_array($f_env, ['test','live'], true)) { $where .= " AND env=?"; $params[] = $f_env; }
if ($f_gateway !== '') { $where .= " AND gateway_code=?"; $params[] = $f_gateway; }

$stmt = $pdo->prepare("
  SELECT
    t.*,
    (SELECT COUNT(*) FROM payment_events e WHERE e.txn_id = t.id) AS events_count
  FROM payment_transactions t
  $where
  ORDER BY t.id DESC
  LIMIT $f_limit
");
$stmt->execute($params);
$pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

// gateways list
$gateways = $pdo->query("SELECT code, name_ar FROM payment_gateways ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

// dark mode
$admin_dark = ps_getSystemSetting('admin_dark_mode','0') === '1';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>مطابقة/تحقق معاملات الدفع</title>
<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="admin-unified-style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">

<style>
:root{
  --bg:#f6f7fb; --card:#fff; --text:#1f2d3d; --muted:#6b7280;
  --border:#e8eaee; --primary:#1e88e5; --success:#27ae60; --danger:#e74c3c; --warning:#f39c12;
  --shadow:0 12px 30px rgba(0,0,0,.06); --radius:16px;
}
body.dark{
  --bg:#0f172a; --card:#101b33; --text:#e5e7eb; --muted:#9ca3af;
  --border:#1f2a44; --shadow:0 14px 40px rgba(0,0,0,.35);
}
body{background:var(--bg);color:var(--text)}
.main-content{padding:0 10px}
.wrap{max-width:1200px;margin:0 auto;padding:10px 0 40px}
.top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:18px 0 8px}
.title{display:flex;align-items:center;gap:10px;font-weight:1000;font-size:1.2rem}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:16px;margin-bottom:14px}
.notice{padding:12px 14px;border-radius:14px;border:1px solid var(--border);background:var(--card);box-shadow:var(--shadow);display:flex;gap:10px;align-items:flex-start;margin-bottom:12px}
.notice.ok{border-color:rgba(39,174,96,.35)} .notice.bad{border-color:rgba(231,76,60,.35)}
.small{color:var(--muted);font-weight:800;font-size:.9rem}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.col{flex:1;min-width:190px}
.label{display:block;margin:8px 0 6px;font-weight:1000}
.input, select{width:100%;padding:11px 12px;border-radius:12px;border:1px solid var(--border);background:transparent;color:var(--text);box-sizing:border-box}
.btn{border:none;border-radius:12px;padding:10px 14px;font-weight:1000;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:.15s}
.btn:hover{transform:translateY(-1px)}
.btn-primary{background:var(--primary);color:#fff}
.btn-outline{background:transparent;color:var(--text);border:1px solid var(--border)}
.btn-success{background:var(--success);color:#fff}
.btn-light{background:rgba(107,114,128,.12);color:var(--text);border:1px solid var(--border)}
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
.mono{font-family: ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
.hr{height:1px;background:var(--border);margin:12px 0}
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
        <i class="fas fa-shield-halved"></i>
        <span>مطابقة/تحقق معاملات الدفع</span>
        <span class="small">هذه الصفحة تفحص pending/created وتعيد مزامنة الحالة</span>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a class="btn btn-outline" href="payment_admin_transactions.php"><i class="fas fa-list"></i> معاملات الدفع</a>
        <a class="btn btn-outline" href="payment_admin_event_log.php"><i class="fas fa-wave-square"></i> سجل الأحداث</a>
      </div>
    </div>

    <?php if ($success): ?>
      <div class="notice ok"><i class="fas fa-check-circle"></i><div><?= h($success) ?></div></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="notice bad"><i class="fas fa-triangle-exclamation"></i><div><?= h($error) ?></div></div>
    <?php endif; ?>

    <?php if ($results): ?>
      <div class="card">
        <div style="font-weight:1000"><i class="fas fa-clipboard-check"></i> نتائج آخر عملية تحقق</div>
        <div class="hr"></div>
        <div style="overflow:auto">
          <table class="table">
            <thead>
              <tr>
                <th>TXN</th>
                <th>Gateway</th>
                <th>OK</th>
                <th>New Status</th>
                <th>Note / Error</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($results as $r): ?>
                <tr>
                  <td class="mono">#<?= (int)$r['txn'] ?></td>
                  <td class="mono"><?= h($r['gateway']) ?></td>
                  <td><?= !empty($r['ok']) ? '✅' : '❌' ?></td>
                  <td class="mono"><?= h($r['new_status'] ?? '-') ?></td>
                  <td class="mono"><?= h($r['note'] ?? ($r['error'] ?? '')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <!-- Batch verify -->
    <div class="card">
      <div style="font-weight:1000"><i class="fas fa-bolt"></i> تحقق دفعة واحدة (Batch)</div>
      <div class="small" style="margin-top:6px">يفحص معاملات pending/created ويحدثها حسب البوابة.</div>

      <form method="POST" class="row" style="margin-top:10px">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

        <div class="col">
          <label class="label">Limit (حتى 50)</label>
          <input class="input" type="number" name="limit" value="20" min="1" max="50">
        </div>

        <div class="col">
          <label class="label">البيئة</label>
          <select name="env">
            <option value="">الكل</option>
            <option value="test">test</option>
            <option value="live">live</option>
          </select>
        </div>

        <div class="col">
          <label class="label">البوابة</label>
          <select name="gateway">
            <option value="">الكل</option>
            <?php foreach ($gateways as $g): ?>
              <option value="<?= h($g['code']) ?>"><?= h($g['name_ar']) ?> (<?= h($g['code']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col" style="flex:0;min-width:220px">
          <button class="btn btn-primary" name="verify_batch" type="submit">
            <i class="fas fa-play"></i> تشغيل التحقق
          </button>
        </div>
      </form>
    </div>

    <!-- Pending list -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <div style="font-weight:1000"><i class="fas fa-hourglass-half"></i> آخر معاملات pending/created</div>
        <div class="small">Limit: <?= (int)$f_limit ?></div>
      </div>

      <form method="GET" class="row" style="margin-top:10px">
        <div class="col">
          <label class="label">البيئة</label>
          <select name="env">
            <option value="">الكل</option>
            <option value="test" <?= $f_env==='test'?'selected':'' ?>>test</option>
            <option value="live" <?= $f_env==='live'?'selected':'' ?>>live</option>
          </select>
        </div>
        <div class="col">
          <label class="label">البوابة</label>
          <select name="gateway">
            <option value="">الكل</option>
            <?php foreach ($gateways as $g): ?>
              <option value="<?= h($g['code']) ?>" <?= $f_gateway===$g['code']?'selected':'' ?>>
                <?= h($g['name_ar']) ?> (<?= h($g['code']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col">
          <label class="label">Limit (10-200)</label>
          <input class="input" type="number" name="limit" value="<?= (int)$f_limit ?>" min="10" max="200">
        </div>
        <div class="col" style="flex:0;min-width:220px">
          <button class="btn btn-outline" type="submit"><i class="fas fa-filter"></i> تحديث</button>
        </div>
      </form>

      <div style="overflow:auto;margin-top:10px">
        <table class="table">
          <thead>
            <tr>
              <th>TXN</th>
              <th>الحالة</th>
              <th>المبلغ</th>
              <th>البوابة</th>
              <th>Provider Ref</th>
              <th>Events</th>
              <th>وقت</th>
              <th>تحقق</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pending as $t): ?>
              <tr>
                <td class="mono">#<?= (int)$t['id'] ?></td>
                <td><span class="badge <?= h(badgeClass($t['status'])) ?>"><?= h(statusLabel($t['status'])) ?></span></td>
                <td><?= h($t['amount']) ?> <?= h($t['currency']) ?></td>
                <td class="mono"><?= h($t['gateway_code']) ?>/<?= h($t['env']) ?></td>
                <td class="mono"><?= h($t['provider_ref'] ?: '—') ?></td>
                <td class="mono"><?= (int)$t['events_count'] ?></td>
                <td class="mono"><?= h($t['created_at']) ?></td>
                <td style="min-width:220px">
                  <form method="POST" style="margin:0;display:inline-block">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="txn_id" value="<?= (int)$t['id'] ?>">
                    <button class="btn btn-success" name="verify_one" type="submit">
                      <i class="fas fa-check"></i> Verify
                    </button>
                  </form>

                  <a class="btn btn-light" href="payment_admin_transactions.php?view=<?= (int)$t['id'] ?>">
                    <i class="fas fa-eye"></i> تفاصيل
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>

            <?php if (!$pending): ?>
              <tr><td colspan="8" style="text-align:center;background:transparent;border:none;color:var(--muted);font-weight:1000">
                لا توجد معاملات pending/created ضمن الفلاتر.
              </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="small" style="margin-top:10px">
        ملاحظة: PayTabs يعتمد على Webhook للتأكيد النهائي — إذا بقيت العملية Pending لفترة طويلة، راجع سجل الأحداث أو اعمل Override من صفحة المعاملة.
      </div>
    </div>

  </div>
</div>
</body>
</html>
