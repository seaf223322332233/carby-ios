<?php
// (يمكن إضافة حارس المدير هنا، لكنه غير ضروري لأن الصفحة محمية)
require_once 'db_connect.php'; 
// (سنضيف حارس المدير للتأكد أن المدير فقط هو من يضيف)
require_once 'auth_admin.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 1. استلام البيانات
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role']; // (chef, driver, preparer)

    // 2. التحقق من أن الدور مسموح به (أمان)
    $allowed_roles = ['chef', 'driver', 'preparer'];
    if (empty($name) || empty($email) || empty($password)) {
        header("Location: add_staff.php?error=empty"); // خطأ: حقول فارغة
        exit;
    }
    if (!in_array($role, $allowed_roles)) {
        header("Location: add_staff.php?error=role"); // خطأ: دور غير صالح
        exit;
    }

    // 3. تأمين كلمة المرور
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // 4. الإضافة إلى جدول "users"
    try {
        $sql = "INSERT INTO users (name, email, password_hash, role) 
                VALUES (?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $email, $password_hash, $role]);

        // إعادة توجيه إلى نفس الصفحة مع رسالة نجاح
        header("Location: add_staff.php?success=1");
        exit;

    } catch (PDOException $e) {
        if ($e->errorInfo[1] == 1062) {
            // خطأ: البريد الإلكتروني مكرر
            header("Location: add_staff.php?error=email");
            exit;
        } else {
            // خطأ آخر
            die("<h1>❌ حدث خطأ.</h1> <p>" . $e->getMessage() . "</p>");
        }
    }

} else {
    // إذا لم يكن الطلب POST
    header("Location: add_staff.php");
    exit;
}
?>