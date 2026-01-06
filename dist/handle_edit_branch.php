<?php
require_once 'auth_admin.php'; 
require_once 'db_connect.php'; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $branch_id = $_POST['branch_id'];
    $name = trim($_POST['name']);
    $address = trim($_POST['address']);
    $map = trim($_POST['google_maps_link']);
    $phone = trim($_POST['phone_number']);
    $hours = trim($_POST['working_hours']);

    if (empty($name) || empty($address)) {
        die("خطأ: البيانات الأساسية ناقصة.");
    }

    try {
        $sql = "UPDATE branches SET 
                    name = ?, 
                    address = ?, 
                    google_maps_link = ?, 
                    phone_number = ?, 
                    working_hours = ? 
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $address, $map, $phone, $hours, $branch_id]);

        header("Location: view_branches.php?success=edit");
        exit;

    } catch (PDOException $e) {
        die("<h1>❌ حدث خطأ أثناء التحديث:</h1><p>" . $e->getMessage() . "</p>");
    }

} else {
    header("Location: view_branches.php");
    exit;
}
?>