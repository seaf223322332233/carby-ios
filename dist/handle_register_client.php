<?php
/**
 * handle_register_client.php - معالج تسجيل حساب عميل جديد
 * =========================================================
 */

header('Content-Type: text/html; charset=utf-8');

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: register_client.php");
    exit;
}

require_once 'db_connect.php';

// استلام البيانات
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$password = $_POST['password'] ?? '';
$password_confirm = $_POST['password_confirm'] ?? '';

// التحقق من البيانات
if (empty($name) || empty($email) || empty($phone) || empty($password)) {
    header("Location: register_client.php?error=empty");
    exit;
}

// التحقق من تطابق كلمة المرور
if ($password !== $password_confirm) {
    header("Location: register_client.php?error=password");
    exit;
}

// التحقق من قوة كلمة المرور
if (strlen($password) < 6) {
    header("Location: register_client.php?error=weak");
    exit;
}

// التحقق من صحة البريد الإلكتروني
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: register_client.php?error=email_invalid");
    exit;
}

try {
    $pdo->beginTransaction();
    
    // التحقق من عدم وجود البريد الإلكتروني
    $stmt_check = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt_check->execute([$email]);
    if ($stmt_check->fetch()) {
        $pdo->rollBack();
        header("Location: register_client.php?error=email");
        exit;
    }
    
    // تأمين كلمة المرور
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    
    // إضافة المستخدم إلى جدول users
    $stmt_user = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'client')");
    $stmt_user->execute([$name, $email, $password_hash]);
    $user_id = $pdo->lastInsertId();
    
    // إضافة تفاصيل العميل إلى client_details
    $stmt_client = $pdo->prepare("
        INSERT INTO client_details (user_id, phone_number, subscription_status) 
        VALUES (?, ?, 'none')
    ");
    $stmt_client->execute([$user_id, $phone]);
    
    $pdo->commit();
    
    // تسجيل الدخول التلقائي
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user_id;
    $_SESSION['name'] = $name;
    $_SESSION['email'] = $email;
    $_SESSION['role'] = 'client';
    $_SESSION['login_time'] = time();
    
    // إعادة التوجيه إلى صفحة عرض الباقات
    header("Location: client_browse_packages.php?registered=1");
    exit;
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // التحقق من تكرار البريد الإلكتروني
    if ($e->errorInfo[1] == 1062) {
        header("Location: register_client.php?error=email");
    } else {
        error_log("Register client error: " . $e->getMessage());
        header("Location: register_client.php?error=server");
    }
    exit;
}
?>

