<?php
// payment_settings.php - Professional Payment Control Center (Admin) v5
// ==============================================================================

header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'auth_admin.php';
require_once 'db_connect.php';
require_once 'PaymentConfig.php';

if (session_status() === PHP_SESSION_NONE) session_start();

/* ===============================
   CSRF + Flash
================================ */
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
function flash_set(string $type, string $msg): void {
    $_SESSION['flash'] = ['type'=>$type,'msg'=>$msg];
}
function flash_get(): array {
    $f = $_SESSION['flash'] ?? ['type'=>'','msg'=>''];
    unset($_SESSION['flash']);
    return $f;
}

/* ===============================
   Helpers
================================ */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function post($k, $d=''){ return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }
function getq($k, $d=''){ return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d; }

function sanitize_env(string $env): string {
    return in_array($env, ['test','live'], true) ? $env : 'test';
}
function sanitize_gateway_code(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9_]+/i','', $s);
    return $s ?: '';
}
function mask($s): string {
    $s = (string)$s;
    if ($s === '') return '';
    $len = mb_strlen($s);
    if ($len <= 8) return str_repeat('•', $len);
    return mb_substr($s, 0, 4) . str_repeat('•', max(0, $len-8)) . mb_substr($s, -4);
}
function isFilled($v): bool { return trim((string)$v) !== ''; }

/* ===============================
   Presets (Minimal Required Fields)
================================ */
function presets(): array {
    return [
        'moyasar' => [
            'name' => 'Moyasar',
            'desc' => 'الأبسط — يحتاج مفتاحين فقط.',
            'fields' => [
                ['key'=>'publishable_key','label'=>'Publishable Key','secret'=>false,'ph'=>'pk_live_... / pk_test_...'],
                ['key'=>'secret_key','label'=>'Secret Key','secret'=>true,'ph'=>'sk_live_... / sk_test_...'],
            ],
            'docs_note' => 'عند الحفظ ستعمل عمليات الدفع مباشرة عبر بوابة Moyasar في البيئة المختارة.',
        ],
        'hyperpay' => [
            'name' => 'HyperPay (OPPWA)',
            'desc' => 'مناسب للمدى/فيزا/ماستر — يحتاج 3 حقول أساسية.',
            'fields' => [
                ['key'=>'base_url','label'=>'Base URL','secret'=>false,'ph'=>'https://eu-test.oppwa.com أو https://eu-prod.oppwa.com'],
                ['key'=>'entity_id','label'=>'Entity ID','secret'=>true,'ph'=>'Entity ID'],
                ['key'=>'access_token','label'=>'Access Token (Bearer)','secret'=>true,'ph'=>'Bearer Token'],
                ['key'=>'brands','label'=>'Brands (اختياري)','secret'=>false,'ph'=>'VISA MASTER MADA'],
            ],
            'docs_note' => 'تأكد أن base_url مطابق للبيئة (test/live).',
        ],
        'paytabs' => [
            'name' => 'PayTabs',
            'desc' => 'سهل للربط — والتأكيد النهائي يكون عبر Webhook.',
            'fields' => [
                ['key'=>'domain','label'=>'API Domain','secret'=>false,'ph'=>'https://secure.paytabs.sa'],
                ['key'=>'profile_id','label'=>'Profile ID','secret'=>false,'ph'=>'رقم البروفايل'],
                ['key'=>'server_key','label'=>'Server Key','secret'=>true,'ph'=>'Authorization Key'],
            ],
            'docs_note' => 'فعّل Webhook Token ثم ضع رابط الـ Webhook في لوحة PayTabs.',
        ],
    ];
}

/* ===============================
   POST Actions (PRG Pattern)
================================ */
csrf_check();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action','');
    $return_tab = sanitize_gateway_code(post('return_tab','general')); // general|gateways|webhook|bank (sanitize safe-ish)
    if (!in_array($return_tab, ['general','gateways','webhook','bank'], true)) $return_tab = 'general';

    // 1) Save General (env + default gateway)
    if ($action === 'save_general') {
        $env = sanitize_env(post('payment_env','test'));
        $default = sanitize_gateway_code(post('active_gateway',''));

        ps_setSystemSetting('payment_env', $env);
        if ($default !== '') pg_setActiveGateway($default);

        flash_set('ok', 'تم حفظ الإعدادات العامة بنجاح.');
        header("Location: payment_settings.php?tab={$return_tab}");
        exit;
    }

    // 2) Save Gateway (preset + advanced)
    if ($action === 'save_gateway') {
        $gw  = sanitize_gateway_code(post('gateway',''));
        $env = sanitize_env(post('env','test'));

        if ($gw === '') {
            flash_set('bad', 'بوابة غير صحيحة.');
            header("Location: payment_settings.php?tab=gateways&env={$env}");
            exit;
        }

        $p = presets();
        // Preset fields (only set when not empty to avoid accidental wipe)
        if (isset($p[$gw])) {
            foreach ($p[$gw]['fields'] as $f) {
                $key = $f['key'];
                $val = post("f_$key", '');
                if ($val !== '') {
                    pg_setSetting($gw, $env, $key, $val);
                }
            }
        }

        // Advanced custom KV
        $adv_k = $_POST['adv_key'] ?? [];
        $adv_v = $_POST['adv_val'] ?? [];
        for ($i=0; $i<count($adv_k); $i++) {
            $k = trim((string)$adv_k[$i]);
            $v = trim((string)$adv_v[$i]);
            if ($k === '' || $v === '') continue;
            pg_setSetting($gw, $env, $k, $v);
        }

        flash_set('ok', "تم حفظ إعدادات البوابة: {$gw} ({$env}).");
        header("Location: payment_settings.php?tab=gateways&env={$env}#gw_{$gw}");
        exit;
    }

    // 3) Save Webhook
    if ($action === 'save_webhook') {
        $token = post('webhook_token','');
        $allow = post('webhook_ip_allowlist','');

        if (isset($_POST['generate_webhook_token'])) {
            $token = bin2hex(random_bytes(24));
        }

        $allow = preg_replace('/[^0-9\.\,\:\s]/', '', $allow);
        $allow = trim(preg_replace('/\s+/', '', $allow));

        ps_setSystemSetting('payment_webhook_token', $token);
        ps_setSystemSetting('payment_webhook_ip_allowlist', $allow);

        flash_set('ok', 'تم حفظ إعدادات Webhook بنجاح.');
        header("Location: payment_settings.php?tab=webhook");
        exit;
    }

    // 4) Save Bank
    if ($action === 'save_bank') {
        $enabled = isset($_POST['bank_enabled']) ? '1' : '0';
        ps_setSystemSetting('bank_transfer_enabled', $enabled);
        ps_setSystemSetting('bank_name', post('bank_name'));
        ps_setSystemSetting('account_name', post('account_name'));
        ps_setSystemSetting('iban', post('iban'));
        ps_setSystemSetting('stc_pay_number', post('stc_pay_number'));

        flash_set('ok', 'تم حفظ بيانات التحويل البنكي.');
        header("Location: payment_settings.php?tab=bank");
        exit;
    }

    flash_set('bad', 'عملية غير معروفة.');
    header("Location: payment_settings.php?tab={$return_tab}");
    exit;
}

/* ===============================
   Fetch UI Data
================================ */
$flash = flash_get();

$admin_dark = ps_getSystemSetting('admin_dark_mode','0') === '1';

$payment_env    = sanitize_env(ps_getSystemSetting('payment_env','test'));
$active_gateway = pg_getActiveGatewayCode();

$tab = getq('tab','general');
if (!in_array($tab, ['general','gateways','webhook','bank'], true)) $tab = 'general';

// env switch for gateways tab
$env_view = sanitize_env(getq('env', $payment_env));

$gateways = $pdo->query("SELECT * FROM payment_gateways WHERE is_active=1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
if (!$gateways) {
    $gateways = $pdo->query("SELECT * FROM payment_gateways ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$presets = presets();

// Read gateway settings for each gateway in env_view (for status + display masked)
$gwData = [];
foreach ($presets as $code => $meta) {
    $settings = pg_getSettings($code, $env_view);
    $gwData[$code] = $settings;
}

// Webhook
$webhook_token        = ps_getSystemSetting('payment_webhook_token','');
$webhook_ip_allowlist = ps_getSystemSetting('payment_webhook_ip_allowlist','');
$app_base = ps_getAppBaseUrl();
$webhook_url_paytabs  = $app_base . "/payment_webhook.php?gateway=paytabs&token=" . urlencode($webhook_token);
$webhook_url_hyperpay = $app_base . "/payment_webhook.php?gateway=hyperpay&token=" . urlencode($webhook_token);

// Bank
$bank_enabled   = ps_getSystemSetting('bank_transfer_enabled','1') === '1';
$bank_name      = ps_getSystemSetting('bank_name','');
$account_name   = ps_getSystemSetting('account_name','');
$iban           = ps_getSystemSetting('iban','');
$stc_pay_number = ps_getSystemSetting('stc_pay_number','');

function gatewayStatus(array $meta, array $settings): array {
    $required = [];
    foreach ($meta['fields'] as $f) {
        // brands optional for hyperpay
        if (($f['key'] ?? '') === 'brands') continue;
        $required[] = $f['key'];
    }
    $missing = [];
    foreach ($required as $k) {
        if (!isFilled($settings[$k] ?? '')) $missing[] = $k;
    }
    return [
        'ok' => count($missing) === 0,
        'missing' => $missing,
    ];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>مركز إعدادات الدفع</title>
<link rel="stylesheet" href="admin-unified-style-v2.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">

<style>
:root{
  --bg:#f6f7fb; --card:#fff; --text:#111827; --muted:#6b7280;
  --border:#e8eaee; --primary:#2563eb; --success:#16a34a; --danger:#ef4444; --warning:#f59e0b;
  --shadow:0 16px 40px rgba(0,0,0,.06); --radius:18px;
}
body.dark{
  --bg:#0b1220; --card:#101b33; --text:#e5e7eb; --muted:#9ca3af;
  --border:#1f2a44; --shadow:0 18px 50px rgba(0,0,0,.42);
}
body{background:var(--bg);color:var(--text);margin:0;font-family:system-ui,-apple-system,"Segoe UI",Tahoma,Arial}
.main-content{padding:0 10px}
.wrap{max-width:1180px;margin:0 auto;padding:16px 0 50px}

.header{
  display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;
  padding:18px 0 10px
}
.h-title{display:flex;flex-direction:column;gap:6px}
.h-title .big{font-weight:1000;font-size:1.25rem;display:flex;align-items:center;gap:10px}
.h-title .sub{color:var(--muted);font-weight:800;font-size:.95rem}

.actions{display:flex;gap:10px;flex-wrap:wrap}
.btn{
  border:none;border-radius:14px;padding:10px 14px;font-weight:1000;cursor:pointer;
  display:inline-flex;align-items:center;gap:8px;transition:.15s
}
.btn:hover{transform:translateY(-1px)}
.btn-primary{background:var(--primary);color:#fff}
.btn-outline{background:transparent;color:var(--text);border:1px solid var(--border)}
.btn-soft{background:rgba(37,99,235,.10);border:1px solid rgba(37,99,235,.20);color:var(--text)}
.btn-danger{background:var(--danger);color:#fff}
.btn-light{background:rgba(107,114,128,.12);border:1px solid var(--border);color:var(--text)}

.notice{
  padding:12px 14px;border-radius:16px;border:1px solid var(--border);
  background:var(--card);box-shadow:var(--shadow);display:flex;gap:10px;align-items:flex-start;margin:10px 0 14px
}
.notice.ok{border-color:rgba(22,163,74,.35)}
.notice.bad{border-color:rgba(239,68,68,.35)}

.shell{
  display:grid;grid-template-columns:260px 1fr;gap:14px;
}
@media(max-width:980px){ .shell{grid-template-columns:1fr} }

.nav{
  background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);
  padding:10px;position:sticky;top:10px;height:fit-content
}
.nav a{
  display:flex;align-items:center;gap:10px;padding:12px 12px;border-radius:14px;
  text-decoration:none;color:var(--text);font-weight:1000;border:1px solid transparent
}
.nav a:hover{background:rgba(107,114,128,.10)}
.nav a.active{background:rgba(37,99,235,.12);border-color:rgba(37,99,235,.25)}
.badge{
  margin-inline-start:auto;
  display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;
  font-weight:1000;font-size:.85rem;border:1px solid var(--border)
}
.badge.ok{background:rgba(22,163,74,.12);border-color:rgba(22,163,74,.25)}
.badge.warn{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.25)}
.badge.muted{background:rgba(107,114,128,.10);border-color:rgba(107,114,128,.25)}

.card{
  background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);
  padding:16px;margin-bottom:14px
}
.card h3{margin:0 0 6px;font-size:1.05rem;font-weight:1000;display:flex;align-items:center;gap:10px}
.small{color:var(--muted);font-weight:800;font-size:.92rem;line-height:1.7}
.hr{height:1px;background:var(--border);margin:12px 0}

.row{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.col{flex:1;min-width:240px}
.label{display:block;margin:8px 0 6px;font-weight:1000}
.input, select{
  width:100%;padding:12px 12px;border-radius:14px;border:1px solid var(--border);
  background:transparent;color:var(--text);box-sizing:border-box
}
.mono{font-family: ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;}
.hint{margin-top:6px;color:var(--muted);font-weight:800;font-size:.88rem}
.codebox{padding:10px;border-radius:14px;border:1px solid var(--border);background:rgba(107,114,128,.08);overflow:auto}
.split{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:980px){ .split{grid-template-columns:1fr} }

.gw-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:980px){ .gw-grid{grid-template-columns:1fr} }

.adv{display:none;margin-top:10px}
.adv.show{display:block}
.kv{display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end;margin-top:10px}
@media(max-width:900px){ .kv{grid-template-columns:1fr} }
.iconbtn{border:1px solid var(--border);background:transparent;border-radius:14px;padding:12px 12px;cursor:pointer;color:var(--text);font-weight:1000}

.footer-save{
  display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-top:10px
}
</style>
</head>

<body class="<?= $admin_dark ? 'dark' : '' ?>">
<div class="sidebar"><?php include 'sidebar.php'; ?></div>

<div class="main-content">
  <div class="wrap">

    <div class="header">
      <div class="h-title">
        <div class="big"><i class="fas fa-credit-card"></i> مركز إعدادات الدفع</div>
        <div class="sub">واجهة واضحة: اختر البيئة → اربط البوابات → فعّل Webhook → حفظ.</div>
      </div>
      <div class="actions">
        <a class="btn btn-outline" href="payment_admin_transactions.php"><i class="fas fa-list"></i> المعاملات</a>
        <a class="btn btn-outline" href="payment_security_check.php"><i class="fas fa-shield"></i> فحص المفاتيح</a>
      </div>
    </div>

    <?php if (!empty($flash['msg'])): ?>
      <div class="notice <?= $flash['type']==='ok' ? 'ok' : 'bad' ?>">
        <i class="fas <?= $flash['type']==='ok' ? 'fa-check-circle' : 'fa-triangle-exclamation' ?>"></i>
        <div><?= h($flash['msg']) ?></div>
      </div>
    <?php endif; ?>

    <div class="shell">

      <!-- LEFT NAV -->
      <div class="nav">
        <a class="<?= $tab==='general'?'active':'' ?>" href="?tab=general">
          <i class="fas fa-gear"></i> عام
          <span class="badge muted"><?= h($payment_env) ?></span>
        </a>

        <a class="<?= $tab==='gateways'?'active':'' ?>" href="?tab=gateways&env=<?= urlencode($env_view) ?>">
          <i class="fas fa-plug"></i> بوابات الدفع
          <span class="badge muted"><?= h($env_view) ?></span>
        </a>

        <a class="<?= $tab==='webhook'?'active':'' ?>" href="?tab=webhook">
          <i class="fas fa-bell"></i> Webhook
          <span class="badge <?= $webhook_token ? 'ok' : 'warn' ?>"><?= $webhook_token ? 'مفعل' : 'ناقص' ?></span>
        </a>

        <a class="<?= $tab==='bank'?'active':'' ?>" href="?tab=bank">
          <i class="fas fa-university"></i> تحويل بنكي
          <span class="badge <?= $bank_enabled ? 'ok' : 'muted' ?>"><?= $bank_enabled ? 'مفعل' : 'معطل' ?></span>
        </a>

        <div class="hr"></div>
        <div class="small">
          البوابة الافتراضية الحالية:
          <div class="mono" style="margin-top:6px;font-weight:1000"><?= h($active_gateway ?: '—') ?></div>
        </div>
      </div>

      <!-- CONTENT -->
      <div>

        <?php if ($tab === 'general'): ?>
          <div class="card">
            <h3><i class="fas fa-gear"></i> إعدادات عامة</h3>
            <div class="small">هنا تحدد البيئة العامة والبوابة الافتراضية. (بسيط وواضح)</div>
            <div class="hr"></div>

            <form method="POST" class="row">
              <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="save_general">
              <input type="hidden" name="return_tab" value="general">

              <div class="col">
                <label class="label">بيئة التشغيل</label>
                <select name="payment_env">
                  <option value="test" <?= $payment_env==='test'?'selected':'' ?>>test (اختبار)</option>
                  <option value="live" <?= $payment_env==='live'?'selected':'' ?>>live (إنتاج)</option>
                </select>
                <div class="hint">كل عمليات الدفع ستقرأ هذه البيئة كقيمة افتراضية.</div>
              </div>

              <div class="col">
                <label class="label">البوابة الافتراضية</label>
                <select name="active_gateway">
                  <?php foreach ($gateways as $g): ?>
                    <option value="<?= h($g['code']) ?>" <?= $active_gateway===$g['code']?'selected':'' ?>>
                      <?= h($g['name_ar']) ?> (<?= h($g['code']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="hint">هذه البوابة ستظهر كخيار الدفع الإلكتروني الأساسي للعميل.</div>
              </div>

              <div class="col" style="flex:0;min-width:240px">
                <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> حفظ</button>
              </div>
            </form>
          </div>

        <?php elseif ($tab === 'gateways'): ?>

          <div class="card">
            <h3><i class="fas fa-plug"></i> بوابات الدفع</h3>
            <div class="small">
              اختر البيئة من هنا (تنعكس على كل البوابات)، ثم أكمل مفاتيح كل بوابة في بطاقة واحدة.
            </div>
            <div class="hr"></div>

            <form method="GET" class="row">
              <input type="hidden" name="tab" value="gateways">
              <div class="col" style="max-width:320px">
                <label class="label">بيئة إعداد البوابات</label>
                <select name="env" onchange="this.form.submit()">
                  <option value="test" <?= $env_view==='test'?'selected':'' ?>>test</option>
                  <option value="live" <?= $env_view==='live'?'selected':'' ?>>live</option>
                </select>
                <div class="hint">هذه البيئة للتعديل فقط داخل هذا التبويب.</div>
              </div>
              <div class="col">
                <div class="hint" style="margin-top:30px">
                  نصيحة: ابدأ ببوابة واحدة فقط، احفظ، ثم جرّب الدفع من صفحة العميل.
                </div>
              </div>
            </form>
          </div>

          <div class="gw-grid">
            <?php foreach ($presets as $code => $meta): ?>
              <?php
                $settings = $gwData[$code] ?? [];
                $st = gatewayStatus($meta, $settings);
                $ok = $st['ok'];
              ?>
              <div class="card" id="gw_<?= h($code) ?>">
                <h3>
                  <i class="fas fa-key"></i>
                  <?= h($meta['name']) ?>
                  <span class="badge <?= $ok ? 'ok' : 'warn' ?>"><?= $ok ? 'مكتملة' : 'ناقصة' ?></span>
                </h3>
                <div class="small"><?= h($meta['desc']) ?></div>
                <?php if (!$ok): ?>
                  <div class="hint">الحقول الناقصة: <span class="mono"><?= h(implode(', ', $st['missing'])) ?></span></div>
                <?php else: ?>
                  <div class="hint">جاهزة في بيئة <span class="mono"><?= h($env_view) ?></span>.</div>
                <?php endif; ?>

                <div class="hr"></div>

                <form method="POST">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                  <input type="hidden" name="action" value="save_gateway">
                  <input type="hidden" name="return_tab" value="gateways">
                  <input type="hidden" name="gateway" value="<?= h($code) ?>">
                  <input type="hidden" name="env" value="<?= h($env_view) ?>">

                  <div class="row">
                    <?php foreach ($meta['fields'] as $f): 
                      $k = $f['key'];
                      $val = $settings[$k] ?? '';
                      $type = $f['secret'] ? 'password' : 'text';
                      // brands optional
                      $isOptional = ($k === 'brands');
                    ?>
                      <div class="col">
                        <label class="label">
                          <?= h($f['label']) ?>
                          <?php if ($isOptional): ?><span class="small">(اختياري)</span><?php endif; ?>
                        </label>

                        <input class="input mono"
                               type="<?= $type ?>"
                               name="f_<?= h($k) ?>"
                               value="<?= h($val) ?>"
                               placeholder="<?= h($f['ph']) ?>"
                               autocomplete="off">

                        <?php if ($val !== '' && $f['secret']): ?>
                          <div class="hint">محفوظ: <span class="mono"><?= h(mask($val)) ?></span></div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <div class="hr"></div>

                  <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
                    <div class="small"><i class="fas fa-circle-info"></i> <?= h($meta['docs_note']) ?></div>
                    <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> حفظ <?= h($meta['name']) ?></button>
                  </div>

                  <div class="hr"></div>

                  <!-- Advanced (optional) -->
                  <button type="button" class="btn btn-light" onclick="toggleAdv('adv_<?= h($code) ?>')">
                    <i class="fas fa-sliders"></i> إعدادات متقدمة (اختياري)
                  </button>

                  <div class="adv" id="adv_<?= h($code) ?>">
                    <div class="hint">أضف مفاتيح إضافية فقط إذا طلبها مزود الدفع (مثل webhook_secret, callback_url...).</div>

                    <div class="kv" style="margin-top:10px">
                      <div>
                        <div class="hint">Key</div>
                        <input class="input mono" name="adv_key[]" placeholder="مثال: webhook_secret">
                      </div>
                      <div>
                        <div class="hint">Value</div>
                        <input class="input mono" name="adv_val[]" placeholder="القيمة">
                      </div>
                      <div style="display:flex;justify-content:flex-end">
                        <button type="button" class="iconbtn" onclick="addMoreKV(this)"><i class="fas fa-plus"></i></button>
                      </div>
                    </div>

                    <div class="hint" style="margin-top:8px">
                      المفاتيح الموجودة (للمراجعة): <span class="mono"><?= h(implode(', ', array_keys($settings ?: []))) ?></span>
                    </div>
                  </div>

                </form>
              </div>
            <?php endforeach; ?>
          </div>

        <?php elseif ($tab === 'webhook'): ?>

          <div class="card">
            <h3><i class="fas fa-bell"></i> Webhook (الحماية + الروابط)</h3>
            <div class="small">فعّل Token ثم انسخ الروابط وضعها في لوحة PayTabs/HyperPay.</div>
            <div class="hr"></div>

            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="save_webhook">
              <input type="hidden" name="return_tab" value="webhook">

              <div class="split">
                <div>
                  <label class="label">Webhook Token</label>
                  <input class="input mono" name="webhook_token" value="<?= h($webhook_token) ?>" placeholder="اضغط توليد لإنشاء Token">
                  <div class="hint">Token يمنع Webhook مزيف. الأفضل تفعيله دائمًا.</div>

                  <div class="footer-save">
                    <button class="btn btn-light" name="generate_webhook_token" value="1" type="submit">
                      <i class="fas fa-wand-magic-sparkles"></i> توليد
                    </button>
                    <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> حفظ</button>
                    <button type="button" class="btn btn-outline" onclick="copyText('<?= h($webhook_token) ?>', this)">
                      <i class="fas fa-copy"></i> نسخ
                    </button>
                  </div>
                </div>

                <div>
                  <label class="label">IP Allowlist (اختياري)</label>
                  <input class="input mono" name="webhook_ip_allowlist" value="<?= h($webhook_ip_allowlist) ?>" placeholder="1.1.1.1,2.2.2.2">
                  <div class="hint">استخدمه فقط إذا مزود الدفع أعطاك IP ثابت.</div>
                </div>
              </div>
            </form>

            <div class="hr"></div>

            <div class="split">
              <div>
                <label class="label">Webhook URL — PayTabs</label>
                <div class="codebox mono" id="pt_url"><?= h($webhook_url_paytabs) ?></div>
                <div class="footer-save">
                  <button class="btn btn-outline" type="button" onclick="copyById('pt_url', this)"><i class="fas fa-copy"></i> نسخ الرابط</button>
                </div>
              </div>

              <div>
                <label class="label">Webhook URL — HyperPay</label>
                <div class="codebox mono" id="hp_url"><?= h($webhook_url_hyperpay) ?></div>
                <div class="footer-save">
                  <button class="btn btn-outline" type="button" onclick="copyById('hp_url', this)"><i class="fas fa-copy"></i> نسخ الرابط</button>
                </div>
              </div>
            </div>

            <div class="hint" style="margin-top:10px">
              ⚠️ لا تنسَ تحديث `payment_webhook.php` ليقوم بالتحقق من token/allowlist (كما قدمته لك سابقًا).
            </div>
          </div>

        <?php else: ?>

          <div class="card">
            <h3><i class="fas fa-university"></i> التحويل البنكي</h3>
            <div class="small">بيانات بسيطة وواضحة تظهر للعميل.</div>
            <div class="hr"></div>

            <form method="POST" class="row">
              <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="save_bank">
              <input type="hidden" name="return_tab" value="bank">

              <div class="col">
                <label class="label">
                  <input type="checkbox" name="bank_enabled" <?= $bank_enabled ? 'checked' : '' ?>>
                  تفعيل التحويل البنكي
                </label>

                <label class="label">اسم البنك</label>
                <input class="input" name="bank_name" value="<?= h($bank_name) ?>" placeholder="مثال: الراجحي">

                <label class="label">اسم الحساب</label>
                <input class="input" name="account_name" value="<?= h($account_name) ?>" placeholder="اسم صاحب الحساب">

                <label class="label">IBAN</label>
                <input class="input mono" name="iban" value="<?= h($iban) ?>" placeholder="SA...">

                <label class="label">STC Pay (اختياري)</label>
                <input class="input mono" name="stc_pay_number" value="<?= h($stc_pay_number) ?>" placeholder="05xxxxxxxx">
              </div>

              <div class="col" style="flex:0;min-width:240px;align-self:end">
                <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> حفظ</button>
              </div>
            </form>
          </div>

        <?php endif; ?>

      </div>
    </div>

  </div>
</div>

<script>
function copyById(id, btn){
  const el = document.getElementById(id);
  if (!el) return;
  const txt = el.innerText || el.textContent || '';
  navigator.clipboard.writeText(txt);
  if (btn){
    const old = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check"></i> تم';
    setTimeout(()=>btn.innerHTML = old, 900);
  }
}
function copyText(txt, btn){
  navigator.clipboard.writeText(txt || '');
  if (btn){
    const old = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check"></i> تم';
    setTimeout(()=>btn.innerHTML = old, 900);
  }
}
function toggleAdv(id){
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.toggle('show');
}

// Add more KV rows under the same advanced section
function addMoreKV(btn){
  const kv = btn.closest('.kv');
  if (!kv) return;
  const clone = kv.cloneNode(true);

  // clear inputs
  const ins = clone.querySelectorAll('input');
  ins.forEach(i => i.value = '');

  // replace + button with trash on clones (optional)
  const area = clone.querySelector('button');
  if (area){
    area.innerHTML = '<i class="fas fa-trash"></i>';
    area.onclick = function(){ clone.remove(); };
  }

  kv.parentElement.appendChild(clone);
}
</script>

</body>
</html>