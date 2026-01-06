<?php
/**
 * handle_delete_client.php - معالج حذف العميل (آمن مع CSRF)
 */

require_once 'auth_admin.php';
require_once 'db_connect.php';
require_once 'csrf.php';

// التحقق من أن الطلب هو POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: view_clients.php");
    exit;
}

// التحقق من CSRF Token
if (!verifyCSRFToken()) {
    header("Location: view_clients.php?error=csrf");
    exit;
}

// جلب ID العميل
$id = isset($_POST['delete_id']) ? (int)$_POST['delete_id'] : 0;

if ($id <= 0) {
    header("Location: view_clients.php?error=invalid");
    exit;
}

try {
    // التحقق من أن المستخدم عميل قبل الحذف
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'client' LIMIT 1");
    $stmt->execute([$id]);
    
    if (!$stmt->fetch()) {
        header("Location: view_clients.php?error=notfound");
        exit;
    }
    
    // الحذف (سيتم حذف client_details تلقائياً بسبب ON DELETE CASCADE)
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'client' LIMIT 1");
    $stmt->execute([$id]);
    
    header("Location: view_clients.php?success=deleted");
    exit;
    
} catch (PDOException $e) {
    error_log("Delete client error: " . $e->getMessage());
    header("Location: view_clients.php?error=server");
    exit;
}

