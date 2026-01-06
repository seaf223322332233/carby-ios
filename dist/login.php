<?php
/**
 * login.php - صفحة تسجيل الدخول العصرية المتناسقة مع تصميم العميل
 * =================================================================
 */

// تحميل ملف الإعدادات أولاً
require_once __DIR__ . '/config.php';

// إعدادات الأخطاء (فقط في وضع التطوير)
if (IS_DEBUG) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    ini_set('log_errors', 1);
    error_reporting(E_ALL);
}

// تحسين أمان الجلسة
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

// إذا كان المستخدم مسجل دخوله، نعيد توجيهه حسب دوره
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'admin':
            header("Location: admin_dashboard.php");
            break;
        case 'client':
            header("Location: client_dashboard.php");
            break;
        case 'chef':
            header("Location: chef_dashboard.php");
            break;
        case 'driver':
            header("Location: driver_dashboard.php");
            break;
        default:
            session_destroy();
            header("Location: login.php?error=auth");
    }
    exit;
}

// جلب شعار المطعم من الإعدادات
require_once 'db_connect.php';
$logo_path = 'uploads/logo.png'; // افتراضي
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='splash_screen_img' LIMIT 1");
    $logo = $stmt->fetchColumn();
    if ($logo && file_exists('uploads/' . $logo)) {
        $logo_path = 'uploads/' . $logo;
    }
} catch (Exception $e) {
    // استخدام الافتراضي
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>تسجيل الدخول</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }
        
        :root {
            --primary: #6c5ce7;
            --primary2: #8e44ad;
            --accent: #00d2d3;
            --primary-light: #a29bfe;

            --bg: #f7f8ff;
            --surface: #ffffff;

            --text-main: #1f2937;
            --text-sub: #6b7280;

            --card-shadow: 0 14px 40px rgba(17, 24, 39, 0.08);
            --soft-shadow: 0 10px 25px rgba(17, 24, 39, 0.06);
            --border: rgba(17,24,39,.07);

            --danger: #ff7675;
            --success: #22c55e;
            --warning: #f59e0b;
        }
        
        body {
            font-family: 'Tajawal', sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            direction: rtl;
            position: relative;
            overflow-x: hidden;
        }

        /* خلفية متدرجة جميلة */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(108, 92, 231, 0.1) 0%, rgba(142, 68, 173, 0.1) 100%);
            z-index: 0;
            pointer-events: none;
        }
        
        .login-container {
            width: 100%;
            max-width: 420px;
            background: var(--surface);
            border-radius: 28px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            position: relative;
            z-index: 1;
        }
        
        .login-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary2) 100%);
            padding: 50px 30px 40px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .login-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 70%);
            animation: pulse 6s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 0.4; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(1.1); }
        }
        
        .logo-container {
            position: relative;
            z-index: 1;
            margin-bottom: 24px;
        }
        
        .logo-container img {
            width: 130px;
            height: 130px;
            object-fit: contain;
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.25);
            padding: 18px;
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.25);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        
        .login-header h1 {
            color: #fff;
            font-size: 1.9rem;
            font-weight: 900;
            margin: 0 0 8px 0;
            position: relative;
            z-index: 1;
            text-shadow: 0 2px 12px rgba(0, 0, 0, 0.2);
        }
        
        .login-header p {
            color: rgba(255, 255, 255, 0.95);
            font-size: 0.95rem;
            margin: 0;
            position: relative;
            z-index: 1;
            font-weight: 700;
        }
        
        .login-body {
            padding: 40px 30px;
        }
        
        .alert-message {
            padding: 16px 20px;
            margin-bottom: 24px;
            border-radius: 16px;
            font-weight: 800;
            text-align: center;
            font-size: 0.9rem;
            animation: slideDown 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-error {
            background: linear-gradient(135deg, rgba(255, 118, 117, 0.12) 0%, rgba(255, 118, 117, 0.08) 100%);
            color: #c92a2a;
            border: 1px solid rgba(255, 118, 117, 0.25);
        }
        
        .alert-success {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.12) 0%, rgba(34, 197, 94, 0.08) 100%);
            color: #15803d;
            border: 1px solid rgba(34, 197, 94, 0.25);
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 900;
            color: var(--text-main);
            margin-bottom: 10px;
            font-size: 0.95rem;
        }
        
        .form-group label i {
            color: var(--primary);
            font-size: 1rem;
        }
        
        .form-group input {
            width: 100%;
            padding: 18px 20px;
            border: 2px solid var(--border);
            border-radius: 16px;
            font-family: inherit;
            font-size: 1rem;
            font-weight: 700;
            background: var(--bg);
            color: var(--text-main);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            box-sizing: border-box;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            background: var(--surface);
            box-shadow: 0 0 0 5px rgba(108, 92, 231, 0.12);
            transform: translateY(-1px);
        }
        
        .form-group input::placeholder {
            color: var(--text-sub);
            font-weight: 500;
        }
        
        .btn-login {
            width: 100%;
            padding: 20px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary2) 100%);
            color: #fff;
            border: none;
            border-radius: 16px;
            font-size: 1.1rem;
            font-weight: 900;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 10px 25px rgba(108, 92, 231, 0.35);
            margin-top: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(108, 92, 231, 0.45);
        }
        
        .btn-login:active {
            transform: translateY(-1px);
        }
        
        .login-footer {
            text-align: center;
            margin-top: 32px;
            padding-top: 28px;
            border-top: 1px solid var(--border);
        }
        
        .footer-links {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .footer-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 900;
            font-size: 0.95rem;
            transition: all 0.25s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px 24px;
            border-radius: 14px;
            border: 2px solid var(--primary);
            background: rgba(108, 92, 231, 0.05);
        }
        
        .footer-link:hover {
            background: var(--primary);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(108, 92, 231, 0.3);
        }
        
        .footer-link.secondary {
            border: 2px solid var(--border);
            background: transparent;
            color: var(--text-sub);
        }
        
        .footer-link.secondary:hover {
            background: var(--bg);
            color: var(--text-main);
            border-color: var(--text-sub);
        }
        
        @media (max-width: 480px) {
            .login-container {
                border-radius: 24px;
            }
            
            .login-header {
                padding: 40px 24px 32px;
            }
            
            .login-body {
                padding: 32px 24px;
            }
            
            .logo-container img {
                width: 110px;
                height: 110px;
            }
            
            .login-header h1 {
                font-size: 1.6rem;
            }
        }
    </style>
</head>
<body>

<div class="login-container">
    <div class="login-header">
        <div class="logo-container">
            <img src="<?php echo htmlspecialchars($logo_path); ?>" alt="شعار المطعم" onerror="this.src='uploads/logo.png'">
        </div>
        <h1>مرحباً بك</h1>
        <p>سجل دخولك للوصول إلى حسابك</p>
    </div>
    
    <div class="login-body">
        <?php
        // رسائل الأخطاء
        if (isset($_GET['error'])) {
            $messages = [
                'invalid'     => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
                'auth_client' => 'يجب أن تكون عميلاً للوصول لهذه الصفحة.',
                'auth_chef'   => 'يجب أن تكون شيفاً للوصول لهذه الصفحة.',
                'auth_driver' => 'يجب أن تكون سائقاً للوصول لهذه الصفحة.',
                'auth'        => 'ليس لديك الصلاحية للوصول.',
                'server'      => 'حدث خطأ في الخادم. يرجى المحاولة لاحقاً.'
            ];
            
            $errorKey = $_GET['error'];
            $message  = $messages[$errorKey] ?? 'حدث خطأ غير متوقع.';
            
            echo '<div class="alert-message alert-error">';
            echo '<i class="fas fa-exclamation-circle"></i>';
            echo '<span>' . htmlspecialchars($message) . '</span>';
            echo '</div>';
        }
        
        // رسالة تسجيل الخروج
        if (isset($_GET['logout'])) {
            echo '<div class="alert-message alert-success">';
            echo '<i class="fas fa-check-circle"></i>';
            echo '<span>تم تسجيل الخروج بنجاح.</span>';
            echo '</div>';
        }
        
        // رسالة تسجيل ناجح
        if (isset($_GET['registered'])) {
            echo '<div class="alert-message alert-success">';
            echo '<i class="fas fa-check-circle"></i>';
            echo '<span>تم إنشاء حسابك بنجاح! يمكنك الآن تسجيل الدخول.</span>';
            echo '</div>';
        }
        ?>
        
        <form action="handle_login.php" method="POST" autocomplete="off">
            <?php if (isset($_GET['package']) && !empty($_GET['package'])): ?>
                <input type="hidden" name="package_id" value="<?php echo htmlspecialchars($_GET['package']); ?>">
            <?php endif; ?>
            <div class="form-group">
                <label for="email">
                    <i class="fas fa-envelope"></i>
                    <span>البريد الإلكتروني</span>
                </label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    required
                    autocomplete="username"
                    placeholder="أدخل بريدك الإلكتروني"
                >
            </div>
            
            <div class="form-group">
                <label for="password">
                    <i class="fas fa-lock"></i>
                    <span>كلمة المرور</span>
                </label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    placeholder="أدخل كلمة المرور"
                >
            </div>
            
            <button type="submit" class="btn-login">
                <i class="fas fa-sign-in-alt"></i>
                <span>تسجيل الدخول</span>
            </button>
        </form>
        
        <div class="login-footer">
            <div class="footer-links">
                <a href="register_client.php" class="footer-link">
                    <i class="fas fa-user-plus"></i>
                    <span>إنشاء حساب جديد</span>
                </a>
                
                <a href="packages_public.php" class="footer-link secondary">
                    <i class="fas fa-eye"></i>
                    <span>تصفح الباقات</span>
                </a>
            </div>
        </div>
    </div>
</div>

</body>
</html>
