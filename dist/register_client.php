<?php
/**
 * register_client.php - صفحة تسجيل حساب عميل جديد
 * ================================================
 */

header('Content-Type: text/html; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// إذا كان المستخدم مسجل دخوله، نعيد توجيهه
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    if ($_SESSION['role'] === 'client') {
        header("Location: client_dashboard.php");
    } else {
        header("Location: login.php");
    }
    exit;
}

// جلب شعار المطعم
require_once 'db_connect.php';
$logo_path = 'uploads/logo.png';
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
    <title>إنشاء حساب جديد</title>
    
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
            --bg: #f7f8ff;
            --surface: #ffffff;
            --text-main: #1f2937;
            --text-sub: #6b7280;
            --border: rgba(17, 24, 39, 0.07);
            --shadow: 0 14px 40px rgba(17, 24, 39, 0.08);
            --success: #22c55e;
            --danger: #ff7675;
        }
        
        body {
            font-family: 'Tajawal', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            direction: rtl;
        }
        
        .register-container {
            width: 100%;
            max-width: 500px;
            background: var(--surface);
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            position: relative;
        }
        
        .register-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary2) 100%);
            padding: 35px 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .register-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: pulse 4s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 0.5; transform: scale(1); }
            50% { opacity: 0.8; transform: scale(1.1); }
        }
        
        .logo-container {
            position: relative;
            z-index: 1;
            margin-bottom: 15px;
        }
        
        .logo-container img {
            width: 100px;
            height: 100px;
            object-fit: contain;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.2);
            padding: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        
        .register-header h1 {
            color: #fff;
            font-size: 1.6rem;
            font-weight: 900;
            margin: 0;
            position: relative;
            z-index: 1;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        .register-header p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.9rem;
            margin-top: 6px;
            position: relative;
            z-index: 1;
        }
        
        .register-body {
            padding: 35px 30px;
            max-height: 70vh;
            overflow-y: auto;
        }
        
        .register-body::-webkit-scrollbar {
            width: 6px;
        }
        
        .register-body::-webkit-scrollbar-track {
            background: var(--bg);
        }
        
        .register-body::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 10px;
        }
        
        .alert-message {
            padding: 14px 18px;
            margin-bottom: 20px;
            border-radius: 12px;
            font-weight: 700;
            text-align: center;
            font-size: 0.9rem;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-error {
            background: rgba(255, 118, 117, 0.1);
            color: #c92a2a;
            border: 1px solid rgba(255, 118, 117, 0.3);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 800;
            color: var(--text-main);
            margin-bottom: 8px;
            font-size: 0.95rem;
        }
        
        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 700;
            background: var(--surface);
            color: var(--text-main);
            transition: all 0.2s;
            box-sizing: border-box;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(108, 92, 231, 0.1);
        }
        
        .form-group input::placeholder {
            color: var(--text-sub);
            font-weight: 500;
        }
        
        .btn-register {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary2) 100%);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 1.05rem;
            font-weight: 900;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 8px 20px rgba(108, 92, 231, 0.3);
            margin-top: 10px;
        }
        
        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(108, 92, 231, 0.4);
        }
        
        .btn-register:active {
            transform: translateY(0);
        }
        
        .login-link {
            text-align: center;
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid var(--border);
        }
        
        .login-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 800;
            font-size: 0.95rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .login-link a:hover {
            color: var(--primary2);
            transform: translateX(-3px);
        }
        
        .password-strength {
            margin-top: 6px;
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-sub);
        }
        
        .password-strength.weak { color: var(--danger); }
        .password-strength.medium { color: #f59e0b; }
        .password-strength.strong { color: var(--success); }
        
        @media (max-width: 480px) {
            .register-container {
                border-radius: 20px;
            }
            
            .register-header {
                padding: 25px 20px;
            }
            
            .register-body {
                padding: 25px 20px;
            }
        }
    </style>
</head>
<body>

<div class="register-container">
    <div class="register-header">
        <div class="logo-container">
            <img src="<?php echo htmlspecialchars($logo_path); ?>" alt="شعار المطعم" onerror="this.src='uploads/logo.png'">
        </div>
        <h1>إنشاء حساب جديد</h1>
        <p>انضم إلينا واستمتع بخدماتنا المميزة</p>
    </div>
    
    <div class="register-body">
        <?php
        // رسائل الأخطاء
        if (isset($_GET['error'])) {
            $messages = [
                'password' => 'كلمتا المرور غير متطابقتين.',
                'email'    => 'البريد الإلكتروني مستخدم مسبقاً.',
                'weak'     => 'كلمة المرور ضعيفة. يجب أن تكون 6 أحرف على الأقل.',
                'server'   => 'حدث خطأ في الخادم. يرجى المحاولة لاحقاً.'
            ];
            
            $errorKey = $_GET['error'];
            $message  = $messages[$errorKey] ?? 'حدث خطأ غير متوقع.';
            
            echo '<div class="alert-message alert-error">';
            echo '<i class="fas fa-exclamation-circle"></i> ' . htmlspecialchars($message);
            echo '</div>';
        }
        ?>
        
        <form action="handle_register_client.php" method="POST" id="registerForm" autocomplete="off">
            <div class="form-group">
                <label for="name">
                    <i class="fas fa-user" style="color: var(--primary); margin-left: 5px;"></i>
                    الاسم الكامل
                </label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    required
                    placeholder="أدخل اسمك الكامل"
                    autocomplete="name"
                >
            </div>
            
            <div class="form-group">
                <label for="email">
                    <i class="fas fa-envelope" style="color: var(--primary); margin-left: 5px;"></i>
                    البريد الإلكتروني
                </label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    required
                    placeholder="example@email.com"
                    autocomplete="email"
                >
            </div>
            
            <div class="form-group">
                <label for="phone">
                    <i class="fas fa-phone" style="color: var(--primary); margin-left: 5px;"></i>
                    رقم الهاتف
                </label>
                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    required
                    placeholder="05xxxxxxxx"
                    pattern="[0-9]{10}"
                >
            </div>
            
            <div class="form-group">
                <label for="password">
                    <i class="fas fa-lock" style="color: var(--primary); margin-left: 5px;"></i>
                    كلمة المرور
                </label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                    placeholder="6 أحرف على الأقل"
                    minlength="6"
                    autocomplete="new-password"
                >
                <div class="password-strength" id="passwordStrength"></div>
            </div>
            
            <div class="form-group">
                <label for="password_confirm">
                    <i class="fas fa-lock" style="color: var(--primary); margin-left: 5px;"></i>
                    تأكيد كلمة المرور
                </label>
                <input
                    type="password"
                    id="password_confirm"
                    name="password_confirm"
                    required
                    placeholder="أعد إدخال كلمة المرور"
                    autocomplete="new-password"
                >
            </div>
            
            <button type="submit" class="btn-register">
                <i class="fas fa-user-plus"></i> إنشاء الحساب
            </button>
        </form>
        
        <div class="login-link">
            <a href="login.php">
                <i class="fas fa-sign-in-alt"></i>
                لديك حساب بالفعل؟ سجل الدخول
            </a>
        </div>
    </div>
</div>

<script>
// التحقق من قوة كلمة المرور
document.getElementById('password').addEventListener('input', function() {
    const password = this.value;
    const strengthDiv = document.getElementById('passwordStrength');
    
    if (password.length === 0) {
        strengthDiv.textContent = '';
        return;
    }
    
    let strength = 'weak';
    let text = 'ضعيفة';
    
    if (password.length >= 8 && /[A-Z]/.test(password) && /[0-9]/.test(password)) {
        strength = 'strong';
        text = 'قوية ✓';
    } else if (password.length >= 6) {
        strength = 'medium';
        text = 'متوسطة';
    }
    
    strengthDiv.textContent = 'قوة كلمة المرور: ' + text;
    strengthDiv.className = 'password-strength ' + strength;
});

// التحقق من تطابق كلمة المرور
document.getElementById('registerForm').addEventListener('submit', function(e) {
    const password = document.getElementById('password').value;
    const passwordConfirm = document.getElementById('password_confirm').value;
    
    if (password !== passwordConfirm) {
        e.preventDefault();
        alert('كلمتا المرور غير متطابقتين');
        return false;
    }
    
    if (password.length < 6) {
        e.preventDefault();
        alert('كلمة المرور يجب أن تكون 6 أحرف على الأقل');
        return false;
    }
});
</script>

</body>
</html>

