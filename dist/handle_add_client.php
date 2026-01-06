<?php
require_once 'auth_admin.php';
require_once 'db_connect.php'; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // استلام البيانات
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    $phone = trim($_POST['phone_number']);
    $package_id = (int)$_POST['package_id'];
    $end_date = $_POST['subscription_end_date'];
    $address = trim($_POST['address_text']);
    $map = trim($_POST['google_maps_link']);

    if (empty($name) || empty($email) || empty($password) || empty($package_id)) {
        die("<h1>خطأ:</h1> يرجى ملء جميع الحقول الأساسية.");
    }

    try {
        $pdo->beginTransaction();

        // 1. إنشاء المستخدم في جدول users
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt1 = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'client')");
        $stmt1->execute([$name, $email, $hash]);
        $user_id = $pdo->lastInsertId();

        // 2. إضافة تفاصيل الاشتراك في جدول client_details
        $stmt2 = $pdo->prepare("INSERT INTO client_details 
            (user_id, phone_number, subscription_end_date, package_id, address_text, google_maps_link) 
            VALUES (?, ?, ?, ?, ?, ?)");
        $stmt2->execute([$user_id, $phone, $end_date, $package_id, $address, $map]);

        $pdo->commit();
        header("Location: view_clients.php?success=added");
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->errorInfo[1] == 1062) { // كود تكرار الإيميل
            die("<h1>خطأ:</h1> البريد الإلكتروني مستخدم مسبقاً.");
        }
        die("<h1>خطأ في قاعدة البيانات:</h1> " . $e->getMessage());
    }
}
header("Location: add_client.php");
?>