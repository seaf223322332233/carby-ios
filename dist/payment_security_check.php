<?php
// payment_security_check.php - Admin Payment Security & Keys Health Check
// ==============================================================================
// Tests connectivity and validity of gateway keys for test/live without exposing secrets.
// - Moyasar: GET /v1/payments (Basic auth) -> expects 200/401 etc.
// - HyperPay: POST /v1/checkouts (Bearer token) with minimal payload -> expects 200 with id
// - PayTabs: POST /payment/request (authorization server_key) with minimal payload -> expects 200 with redirect_url
// ==============================================================================

header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'auth_admin.php';
require_once 'db_connect.php';
require_once 'PaymentConfig.php';
require_once 'PaymentEngine.php';

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

function mask_secret($s): string {
    $s = (string)$s;
    if ($s === '') return '—';
    $len = mb_strlen($s);
    if ($len <= 6) return str_repeat('•', $len);
    return mb_substr($s, 0, 3) . str_repeat('•', max(0, $len-6)) . mb_substr($s, -3);
}

function badge($ok): string {
    return $ok ? 'success' : 'danger';
}

function http_ok_code(int $code): bool {
    return $code >= 200 && $code < 300;
}

function test_moyasar(string $env): array
{
    $secret = pg_getSetting('moyasar', $env, 'secret_key', '');
    if ($secret === '') return ['ok'=>false,'error'=>'secret_key missing'];

    $auth = base64_encode($secret . ":");
    $url = "https://api.moyasar.com/v1/payments?limit=1";

    $r = PaymentEngine::httpJson("GET", $url, [
        "Authorization: Basic ".$auth,
        "Accept: application/json",
    ]);

    $code = (int)($r['code'] ?? 0);
    $ok = http_ok_code($code);

    // 401 = invalid secret typically
    $note = $ok ? 'OK' : ("HTTP ".$code.((!empty($r['error']))?(" / ".$r['error']):''));
    if ($code === 401) $note = 'Unauthorized (secret_key غالباً غير صحيح)';

    return [
        'ok' => $ok,
        'http_code' => $code,
        'note' => $note,
        'key_masked' => mask_secret($secret),
    ];
}

function test_hyperpay(string $env): array
{
    $baseUrl     = rtrim(pg_getSetting('hyperpay', $env, 'base_url', ''), '/');
    $entityId    = pg_getSetting('hyperpay', $env, 'entity_id', '');
    $accessToken = pg_getSetting('hyperpay', $env, 'access_token', '');

    if ($baseUrl === '' || $entityId === '' || $accessToken === '') {
        return ['ok'=>false,'error'=>'missing base_url/entity_id/access_token'];
    }

    // Create a minimal checkout (small amount) - does NOT charge, only creates session
    $endpoint = $baseUrl . "/v1/checkouts";

    $fields = [
        'entityId' => $entityId,
        'amount' => '1.00',
        'currency' => 'SAR',
        'paymentType' => 'DB',
        'merchantTransactionId' => 'HEALTHCHECK-'.time(),
        'shopperResultUrl' => ps_getAppBaseUrl()."/payment_return.php?gateway=hyperpay&txn=0",
    ];

    $r = PaymentEngine::httpJson("POST", $endpoint, [
        "Authorization: Bearer ".$accessToken,
        "Content-Type: application/x-www-form-urlencoded",
        "Accept: application/json",
    ], http_build_query($fields));

    $code = (int)($r['code'] ?? 0);
    $data = json_decode((string)($r['body'] ?? ''), true);
    if (!is_array($data)) $data = [];

    $checkoutId = (string)($data['id'] ?? '');
    $resultCode = (string)($data['result']['code'] ?? '');
    $resultDesc = (string)($data['result']['description'] ?? '');

    $ok = http_ok_code($code) && $checkoutId !== '';

    $note = $ok ? ("OK (checkoutId: ".substr($checkoutId,0,8)."…)") : ("HTTP ".$code);
    if (!$ok && $code === 401) $note = "Unauthorized (access_token غالباً غير صحيح)";
    if (!$ok && $resultCode) $note .= " / ".$resultCode." ".$resultDesc;

    return [
        'ok' => $ok,
        'http_code' => $code,
        'note' => $note,
        'base_url' => $baseUrl,
        'entity_id' => mask_secret($entityId),
        'access_token' => mask_secret($accessToken),
        'result_code' => $resultCode,
    ];
}

function test_paytabs(string $env): array
{
    $domain    = rtrim(pg_getSetting('paytabs', $env, 'domain', ''), '/');
    $profileId = trim((string)pg_getSetting('paytabs', $env, 'profile_id', ''));
    $serverKey = trim((string)pg_getSetting('paytabs', $env, 'server_key', ''));

    if ($domain === '' || $profileId === '' || $serverKey === '') {
        return ['ok'=>false,'error'=>'missing domain/profile_id/server_key'];
    }

    // Create a minimal payment request (will return redirect_url, but we won't redirect)
    $endpoint = $domain . "/payment/request";

    $payload = [
        'profile_id' => (int)$profileId,
        'tran_type' => 'sale',
        'tran_class' => 'ecom',
        'cart_id' => 'HEALTHCHECK-'.time(),
        'cart_description' => 'HealthCheck',
        'cart_currency' => 'SAR',
        'cart_amount' => 1.00,
        'return' => ps_getAppBaseUrl()."/payment_return.php?gateway=paytabs&txn=0",
        'callback' => ps_getAppBaseUrl()."/payment_webhook.php?gateway=paytabs&txn=0",
        'paypage_lang' => 'ar',
        'hide_shipping' => true,
        'customer_details' => [
            'name'=>'HealthCheck',
            'email'=>'healthcheck@example.com',
            'phone'=>'0500000000',
            'street1'=>'N/A',
            'city'=>'Riyadh',
            'state'=>'Riyadh',
            'country'=>'SA',
            'zip'=>'00000',
        ],
    ];

    $r = PaymentEngine::httpJson("POST", $endpoint, [
        "authorization: ".$serverKey,
        "Content-Type: application/json",
        "Accept: application/json",
    ], json_encode($payload, JSON_UNESCAPED_UNICODE));

    $code = (int)($r['code'] ?? 0);
    $data = json_decode((string)($r['body'] ?? ''), true);
    if (!is_array($data)) $data = [];

    $redirect = (string)($data['redirect_url'] ?? '');
    $tranRef  = (string)($data['tran_ref'] ?? '');

    $ok = http_ok_code($code) && $redirect !== '';

    $note = $ok ? "OK (tran_ref: ".($tranRef ? substr($tranRef,0,8).'…' : '—').")" : ("HTTP ".$code);
    if (!$ok && $code === 401) $note = "Unauthorized (server_key غالباً غير صحيح)";

    return [
        'ok' => $ok,
        'http_code' => $code,
        'note' => $note,
        'domain' => $domain,
        'profile_id' => mask_secret($profileId),
        'server_key' => mask_secret($serverKey),
    ];
}

/* =============================================================================
   Run tests
============================================================================= */
csrf_check();

$run = isset($_POST['run_checks']);
$results = [];

if ($run) {
    foreach (['test','live'] as $env) {
        $results[$env] = [
            'moyasar' => test_moyasar($env),
            'hyperpay' => test_hyperpay($env),
            'paytabs' => test_paytabs($env),
        ];
    }
}

$admin_dark = ps_getSystemSetting('admin_dark_mode','0') === '1';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>فحص مفاتيح الدفع (Health Check)</title>
<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="admin-unified-style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#f6f7fb; --card:#fff; --text:#1f2d3d; --muted:#6b7280;
  --border:#e8eaee; --primary:#1e88e5; --success:#27ae60; --danger:#e74c3c;
  --shadow:0 12px 30px rgba(0,0,0,.06); --radius:16px;
}
body.dark{
  --bg:#0f172a; --card:#101b33; --text:#e5e7eb; --muted:#9ca3af;
  --border:#1f2a44; --shadow:0 14px 40px rgba(0,0,0,.35);
}
body{background:var(--bg);color:var(--text)}
.main-content{padding:0 10px}
.wrap{max-width:1100px;margin:0 auto;padding:10px 0 40px}
.top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:18px 0 8px}
.title{display:flex;align-items:center;gap:10px;font-weight:1000;font-size:1.2rem}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:16px;margin-bottom:14px}
.small{color:var(--muted);font-weight:800;font-size:.9rem}
.btn{border:none;border-radius:12px;padding:10px 14px;font-weight:1000;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:.15s}
.btn:hover{transform:translateY(-1px)}
.btn-primary{background:var(--primary);color:#fff}
.btn-outline{background:transparent;color:var(--text);border:1px solid var(--border)}
.table{width:100%;border-collapse:separate;border-spacing:0 10px}
.table th{text-align:right;color:var(--muted);font-weight:1000;font-size:.9rem;padding:0 10px}
.table td{background:rgba(107,114,128,.06);border:1px solid var(--border);padding:12px 10px;font-weight:900}
.table tr td:first-child{border-top-right-radius:14px;border-bottom-right-radius:14px}
.table tr td:last-child{border-top-left-radius:14px;border-bottom-left-radius:14px}
.badge{
  display:inline-flex;align-items:center;gap:8px;padding:8px 10px;border-radius:999px;border:1px solid var(--border);font-weight:1000
}
.badge.success{background:rgba(39,174,96,.12);border-color:rgba(39,174,96,.25)}
.badge.danger{background:rgba(231,76,60,.12);border-color:rgba(231,76,60,.25)}
.mono{font-family: ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
.hr{height:1px;background:var(--border);margin:12px 0}
</style>
</head>
<body class="<?= $admin_dark ? 'dark' : '' ?>">
<div class="sidebar"><?php include 'sidebar.php'; ?></div>

<div class="main-content">
  <div class="wrap">

    <div class="top">
      <div class="title">
        <i class="fas fa-shield"></i>
        <span>فحص مفاتيح الدفع (Health Check)</span>
        <span class="small">يفحص مفاتيح test/live دون كشف الأسرار</span>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a class="btn btn-outline" href="payment_settings.php"><i class="fas fa-gear"></i> إعدادات الدفع</a>
        <a class="btn btn-outline" href="payment_admin_transactions.php"><i class="fas fa-list"></i> معاملات الدفع</a>
      </div>
    </div>

    <div class="card">
      <div style="font-weight:1000"><i class="fas fa-play"></i> تشغيل الفحص</div>
      <div class="small" style="margin-top:6px">
        سيتم إنشاء طلبات “HealthCheck” صغيرة (1 SAR) لا يتم دفعها تلقائياً — فقط إنشاء جلسة/رابط للتأكد من صحة المفاتيح.
      </div>
      <form method="POST" style="margin-top:10px">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <button class="btn btn-primary" name="run_checks" type="submit">
          <i class="fas fa-bolt"></i> Run Checks (test + live)
        </button>
      </form>
    </div>

    <?php if ($run): ?>
      <?php foreach ($results as $env => $resEnv): ?>
        <div class="card">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
            <div style="font-weight:1000"><i class="fas fa-flask"></i> البيئة: <span class="mono"><?= h($env) ?></span></div>
            <div class="small">PayTabs يعتمد على Webhook للتأكيد النهائي، هنا فقط صحة إنشاء الطلب.</div>
          </div>

          <div class="hr"></div>

          <div style="overflow:auto">
            <table class="table">
              <thead>
                <tr>
                  <th>Gateway</th>
                  <th>Status</th>
                  <th>HTTP</th>
                  <th>Note</th>
                  <th>Keys (Masked)</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (['moyasar','hyperpay','paytabs'] as $gw): ?>
                  <?php $r = $resEnv[$gw]; ?>
                  <tr>
                    <td class="mono"><?= h($gw) ?></td>
                    <td>
                      <span class="badge <?= h(badge(!empty($r['ok']))) ?>">
                        <?= !empty($r['ok']) ? 'OK' : 'FAIL' ?>
                      </span>
                    </td>
                    <td class="mono"><?= h($r['http_code'] ?? '—') ?></td>
                    <td class="mono"><?= h($r['note'] ?? ($r['error'] ?? '')) ?></td>
                    <td class="mono">
                      <?php if ($gw === 'moyasar'): ?>
                        secret_key: <?= h($r['key_masked'] ?? '—') ?>
                      <?php elseif ($gw === 'hyperpay'): ?>
                        entity_id: <?= h($r['entity_id'] ?? '—') ?><br>
                        access_token: <?= h($r['access_token'] ?? '—') ?><br>
                        base_url: <?= h($r['base_url'] ?? '—') ?>
                      <?php else: ?>
                        profile_id: <?= h($r['profile_id'] ?? '—') ?><br>
                        server_key: <?= h($r['server_key'] ?? '—') ?><br>
                        domain: <?= h($r['domain'] ?? '—') ?>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="small" style="margin-top:10px">
            نصيحة: إذا فشل test ونجح live (أو العكس) راجع إعدادات البيئة المحددة في `payment_settings.php`.
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

  </div>
</div>
</body>
</html>