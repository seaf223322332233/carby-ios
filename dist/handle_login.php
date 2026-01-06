<?php
// ================================
// إعداد الجلسة
session_start();

// ================================
// استدعاء ملف الاتصال (حل مشكلة المسار نهائيًا)
require_once __DIR__ . '/db_connect.php'; 
// إذا كان db_connect.php داخل مجلد آخر (مثلاً includes)
// غيّر السطر إلى:
// require_once __DIR__ . '/includes/db_connect.php';

// ================================
// السماح فقط بطلب POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login.php");
    exit;
}

// ================================
// تنظيف المدخلات
$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    header("Location: login.php?error=invalid");
    exit;
}

try {
    // ================================
    // استعلام آمن
    $sql = "SELECT id, name, email, password_hash, role 
            FROM users 
            WHERE email = ? 
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // ================================
    // التحقق من المستخدم وكلمة المرور
    if (!$user || !password_verify($password, $user['password_hash'])) {
        header("Location: login.php?error=invalid");
        exit;
    }

    // ================================
    // حماية من Session Fixation
    session_regenerate_id(true);

    // ================================
    // تخزين بيانات الجلسة
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['name']    = $user['name'];
    $_SESSION['email']   = $user['email'];
    $_SESSION['role']    = $user['role'];
    $_SESSION['login_time'] = time(); // لتتبع وقت تسجيل الدخول
    
    // إعادة توليد CSRF Token بعد تسجيل الدخول
    if (file_exists(__DIR__ . '/csrf.php')) {
        require_once __DIR__ . '/csrf.php';
        regenerateCSRFToken();
    }

    // ================================
    // التوجيه حسب الدور
    switch ($user['role']) {

        case 'admin':
            header("Location: admin_dashboard.php");
            break;

        case 'client':
            // إذا كان هناك package_id في POST (من packages_public.php)، نحول إلى صفحة الباقات
            if (isset($_POST['package_id']) && !empty($_POST['package_id'])) {
                header("Location: client_browse_packages.php?package=" . urlencode($_POST['package_id']));
            } else {
                header("Location: client_dashboard.php");
            }
            break;

        case 'chef':
            header("Location: chef_dashboard.php");
            break;

        case 'preparer':
            header("Location: preparer_dashboard.php");
            break;

        case 'driver':
            header("Location: driver_dashboard.php");
            break;

        default:
            // دور غير معروف
            session_destroy();
            header("Location: login.php?error=auth");
    }

    exit;

} catch (PDOException $e) {
    // في الإنتاج لا تعرض الخطأ
    // error_log($e->getMessage());
    header("Location: login.php?error=server");
    exit;
}
