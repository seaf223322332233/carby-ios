<?php
// client_profile.php - Profile (Secure + Theme Sync + Fixed Bottom Nav Safe Area)
// ==============================================================================

header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');

require_once 'auth_client.php';
require_once 'db_connect.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$client_id = (int)($_SESSION['user_id'] ?? 0);
if ($client_id <= 0) { header("Location: login.php"); exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$msg = '';
$msg_type = '';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// Load user (prepared)
$stmtUser = $pdo->prepare("SELECT id, name, email, password_hash FROM users WHERE id = ? LIMIT 1");
$stmtUser->execute([$client_id]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);
if (!$user) { header("Location: logout.php"); exit; }

$stmtDet = $pdo->prepare("SELECT phone_number FROM client_details WHERE user_id = ? LIMIT 1");
$stmtDet->execute([$client_id]);
$details = $stmtDet->fetch(PDO::FETCH_ASSOC) ?: [];
$phone = $details['phone_number'] ?? '';

// Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim((string)($_POST['name'] ?? ''));
    $csrf_post = (string)($_POST['csrf_token'] ?? '');

    $current_pass = (string)($_POST['current_password'] ?? '');
    $new_pass     = (string)($_POST['new_password'] ?? '');
    $confirm_new  = (string)($_POST['confirm_new_password'] ?? '');

    try {
        if (!hash_equals($csrf_token, $csrf_post)) {
            throw new Exception("انتهت صلاحية الطلب. حدّث الصفحة وحاول مرة أخرى.");
        }

        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            throw new Exception("الاسم غير صالح (بين 2 و 60 حرف).");
        }

        $wantPass = ($current_pass !== '' || $new_pass !== '' || $confirm_new !== '');

        if ($wantPass) {
            if ($current_pass === '' || $new_pass === '' || $confirm_new === '') {
                throw new Exception("لتغيير كلمة المرور: أدخل الحالية + الجديدة + التأكيد.");
            }
            if (!password_verify($current_pass, (string)$user['password_hash'])) {
                throw new Exception("كلمة المرور الحالية غير صحيحة.");
            }
            if ($new_pass !== $confirm_new) {
                throw new Exception("كلمتا المرور الجديدتان غير متطابقتين.");
            }
            if (strlen($new_pass) < 8 || !preg_match('/[A-Za-z]/', $new_pass) || !preg_match('/\d/', $new_pass)) {
                throw new Exception("كلمة المرور ضعيفة. 8 أحرف+ وبها رقم وحرف.");
            }

            $stmt = $pdo->prepare("UPDATE users SET name=?, password_hash=? WHERE id=?");
            $stmt->execute([$name, password_hash($new_pass, PASSWORD_DEFAULT), $client_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET name=? WHERE id=?");
            $stmt->execute([$name, $client_id]);
        }

        $_SESSION['name'] = $name;

        // Reload user
        $stmtUser->execute([$client_id]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: $user;

        $msg = "تم حفظ التغييرات بنجاح ✅";
        $msg_type = "success";

        // Rotate CSRF
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $csrf_token = $_SESSION['csrf_token'];

    } catch (Exception $e) {
        $msg = $e->getMessage();
        $msg_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>حسابي</title>

    <link rel="stylesheet" href="client_style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <!-- ✅ Theme Sync exactly like client_dashboard.php -->
    <script>
      (function(){
        try{
          const t = localStorage.getItem('theme') || 'light';
          document.addEventListener('DOMContentLoaded', () => {
            document.body.setAttribute('data-theme', (t === 'dark') ? 'dark' : 'light');
          });
          window.addEventListener('storage', (e) => {
            if(e.key === 'theme'){
              const v = e.newValue || 'light';
              document.body.setAttribute('data-theme', (v === 'dark') ? 'dark' : 'light');
            }
          });
        }catch(e){}
      })();
    </script>

    <style>
      /* =========================================================
         Safe Area for fixed bottom nav (حل جذري)
         ========================================================= */
      :root{
        --bottom-nav-h: 92px;     /* ارتفاع القائمة السفلية (عدله إذا تغير تصميمها) */
        --bottom-nav-gap: 18px;   /* مسافة إضافية فوقها */
      }

      /* نخلي الصفحة دائمًا فيها مساحة كافية */
      body{
        padding-bottom: calc(var(--bottom-nav-h) + var(--bottom-nav-gap)) !important;
      }

      /* Wrapper خاص بهذه الصفحة */
      .content-wrapper{
        position: relative;
        z-index: 5;
        padding: 0 20px;
        max-width: 800px;
        margin: 0 auto;
      }

      /* Spacer إضافي قبل الفوتر (يضمن عدم التداخل حتى لو تغيّر footer_nav) */
      .bottom-spacer{
        height: calc(var(--bottom-nav-h) + var(--bottom-nav-gap));
      }

      /* ================= Profile UI ================= */
      .profile-header { text-align: center; margin-bottom: 22px; }
      .profile-avatar{
        width: 80px; height: 80px; background: rgba(255,255,255,0.2);
        border-radius: 50%; margin: 0 auto 10px;
        display:flex; align-items:center; justify-content:center;
        font-size: 2.5rem; color: white;
        border: 3px solid rgba(255,255,255,0.3);
      }

      .menu-link{
        display:flex; justify-content:space-between; align-items:center;
        padding: 15px;
        background: var(--bg-card);
        border-radius: 12px;
        margin-bottom: 10px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.02);
        color: var(--text-main);
        font-weight: 800;
        border: 1px solid var(--border);
      }
      .menu-link:active{ transform: scale(0.98); filter: brightness(0.98); }
      .menu-icon{ width:30px; color: var(--primary); text-align:center; }

      .form-group{ margin-bottom: 15px; }
      .form-label{
        display:block; margin-bottom: 6px;
        font-weight: 900;
        color: var(--text-light);
        font-size: 0.9rem;
      }
      .form-input{
        width:100%;
        padding: 12px;
        border: 1px solid var(--border);
        border-radius: 10px;
        background: var(--input-bg);
        font-family: inherit;
        color: var(--text-main);
      }
      .form-input:focus{ border-color: var(--primary); background: var(--bg-card); }

      .hint{
        font-size: 0.82rem;
        color: var(--text-light);
        opacity: .9;
        margin-top: 6px;
        line-height: 1.6;
      }

      .alert-box{
        padding: 15px;
        border-radius: 10px;
        margin-bottom: 18px;
        text-align:center;
        font-weight: 900;
      }
      .alert-success{ background:#d4edda; color:#155724; }
      .alert-error{ background:#f8d7da; color:#721c24; }

      /* زر تسجيل الخروج داخل كارد لطيف ويبعده عن الناف */
      .logout-card{
        margin-top: 16px;
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: 16px;
        overflow: hidden;
      }
      .logout-btn{
        display:block;
        padding: 16px;
        text-align:center;
        color: #e74c3c;
        font-weight: 900;
      }
      .logout-btn:active{ filter: brightness(0.95); }
    </style>
</head>

<body data-theme="light">
    <div class="app-header" style="height: 220px;"></div>

    <div class="content-wrapper" style="margin-top: 40px;">

        <div class="profile-header">
            <div class="profile-avatar"><i class="fas fa-user-circle"></i></div>
            <h2 style="color:white; margin:0;"><?php echo h($user['name']); ?></h2>
            <div style="color:rgba(255,255,255,0.85); font-size:0.9rem;"><?php echo h($user['email']); ?></div>
        </div>

        <?php if($msg): ?>
            <div class="alert-box alert-<?php echo h($msg_type); ?>"><?php echo h($msg); ?></div>
        <?php endif; ?>

        <div style="margin-bottom: 22px;">
            <a href="client_history.php" class="menu-link">
                <div><span class="menu-icon"><i class="fas fa-history"></i></span> سجل الطلبات والأرشيف</div>
                <i class="fas fa-chevron-left" style="color:var(--text-muted); font-size:0.8rem;"></i>
            </a>

            <a href="client_package.php" class="menu-link">
                <div><span class="menu-icon"><i class="far fa-calendar-check"></i></span> تفاصيل اشتراكي</div>
                <i class="fas fa-chevron-left" style="color:var(--text-muted); font-size:0.8rem;"></i>
            </a>
        </div>

        <div class="card">
            <h3 class="section-title" style="font-size:1rem; margin-bottom:15px;">تعديل البيانات</h3>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">

                <div class="form-group">
                    <label class="form-label">الاسم</label>
                    <input type="text" name="name" value="<?php echo h($user['name']); ?>" class="form-input" required maxlength="60">
                    <div class="hint">إذا لم تغيّر كلمة المرور سيتم حفظ الاسم فقط.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">رقم الجوال (للعرض فقط)</label>
                    <input type="text" value="<?php echo h($phone); ?>" class="form-input" disabled>
                </div>

                <hr style="border:none; height:1px; background:var(--border); margin:18px 0;">

                <div class="form-group">
                    <label class="form-label">كلمة المرور الحالية (فقط إذا تريد تغييرها)</label>
                    <input type="password" name="current_password" placeholder="أدخل كلمة المرور الحالية" class="form-input" autocomplete="current-password">
                </div>

                <div class="form-group">
                    <label class="form-label">كلمة المرور الجديدة</label>
                    <input type="password" name="new_password" placeholder="8 أحرف+ وبها رقم وحرف" class="form-input" autocomplete="new-password">
                </div>

                <div class="form-group">
                    <label class="form-label">تأكيد كلمة المرور الجديدة</label>
                    <input type="password" name="confirm_new_password" placeholder="أعد إدخال كلمة المرور الجديدة" class="form-input" autocomplete="new-password">
                </div>

                <button type="submit" class="btn-main">حفظ التغييرات</button>
            </form>
        </div>

        <!-- ✅ Logout in safe card + not overlapping -->
        <div class="logout-card">
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> تسجيل الخروج
            </a>
        </div>

        <!-- ✅ Spacer ensures nothing sits under fixed bottom nav -->
        <div class="bottom-spacer"></div>

    </div>

    <?php include 'client_footer_nav.php'; ?>
</body>
</html>
