<?php
require_once 'db_connect.php';
require_once 'csrf.php';
require_once 'auth_admin.php';

if ($_SERVER["REQUEST_METHOD"] != "POST") {
    header("Location: view_clients.php");
    exit;
}

// التحقق من CSRF Token
if (!verifyCSRFToken()) {
    header("Location: view_clients.php?error=csrf");
    exit;
}

$user_id = (int)($_POST['user_id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$phone_number = trim($_POST['phone_number'] ?? '');
$invoice_id = trim($_POST['invoice_id'] ?? '');
$subscription_end_date = $_POST['subscription_end_date'] ?? '';
$google_maps_link = trim($_POST['google_maps_link'] ?? '');
$address_text = trim($_POST['address_text'] ?? '');

// معالجة package_id - يمكن أن يكون NULL (إيقاف الباقة)
$package_id = $_POST['package_id'] ?? '';
$package_id = ($package_id === '') ? null : (int)$package_id;
if ($package_id !== null && $package_id <= 0) {
    $package_id = null;
}

// معالجة subscription_status
$subscription_status = trim($_POST['subscription_status'] ?? 'none');
$allowed_statuses = ['none', 'active', 'paused', 'expired', 'pending_payment'];
if (!in_array($subscription_status, $allowed_statuses)) {
    $subscription_status = 'none';
}

// التحقق من البيانات المطلوبة
if (empty($user_id) || empty($name) || empty($email) || empty($phone_number) || empty($subscription_end_date)) {
    header("Location: edit_client.php?id=$user_id&error=missing");
    exit;
}

// التحقق من أن العميل موجود
try {
    $stmt_check = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'client'");
    $stmt_check->execute([$user_id]);
    if (!$stmt_check->fetch()) {
        header("Location: view_clients.php?error=notfound");
        exit;
    }
} catch (PDOException $e) {
    header("Location: view_clients.php?error=server");
    exit;
}

try {
    $pdo->beginTransaction();

    // تحديث بيانات المستخدم
    if (!empty($password)) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $sql_users = "UPDATE users SET name = ?, email = ?, password_hash = ? WHERE id = ?";
        $params_users = [$name, $email, $password_hash, $user_id];
    } else {
        $sql_users = "UPDATE users SET name = ?, email = ? WHERE id = ?";
        $params_users = [$name, $email, $user_id];
    }
    $stmt_users = $pdo->prepare($sql_users);
    $stmt_users->execute($params_users);

    // التأكد من وجود سجل في client_details
    $stmt_check_details = $pdo->prepare("SELECT user_id FROM client_details WHERE user_id = ?");
    $stmt_check_details->execute([$user_id]);
    if (!$stmt_check_details->fetch()) {
        // إنشاء سجل جديد إذا لم يكن موجوداً
        $stmt_insert = $pdo->prepare("INSERT INTO client_details (user_id, phone_number) VALUES (?, ?)");
        $stmt_insert->execute([$user_id, $phone_number]);
    }

    // تحديث بيانات العميل (مع package_id يمكن أن يكون NULL)
    $sql_details = "UPDATE client_details SET 
                        phone_number = ?, 
                        invoice_id = ?, 
                        subscription_end_date = ?, 
                        Maps_link = ?, 
                        address_text = ?, 
                        package_id = ?,
                        subscription_status = ?
                    WHERE user_id = ?";
    
    $stmt_details = $pdo->prepare($sql_details);
    $stmt_details->execute([
        $phone_number, 
        $invoice_id ?: null, 
        $subscription_end_date, 
        $google_maps_link ?: null, 
        $address_text ?: null, 
        $package_id,
        $subscription_status,
        $user_id
    ]);

    $pdo->commit();
    header("Location: view_clients.php?success=edit");
    exit;
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Edit client error: " . $e->getMessage());
    header("Location: edit_client.php?id=$user_id&error=server");
    exit;
}
?>