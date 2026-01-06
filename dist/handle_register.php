<?php
// تضمين ملف الاتصال
require_once 'db_connect.php';

// التحقق من أن الطلب هو POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // --- 1. التحقق الأمني: هل المدير موجود بالفعل؟ ---
    // (يجب تكرار التحقق هنا لضمان الأمان، 
    // حتى لو حاول شخص إرسال الفورم مباشرة)
    $stmt_check = $pdo->query("SELECT COUNT(*) FROM users");
    if ($stmt_check->fetchColumn() > 0) {
        // إذا حاول شخص التسجيل بينما المدير موجود، أعده لصفحة الدخول
        header("Location: login.php?error=admin_exists");
        exit;
    }

    // --- 2. استلام البيانات من الفورم ---
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $password_confirm = $_POST['password_confirm'];
    
    // الدور (Role) محدد مسبقاً
    $role = 'admin'; // هذا هو المفتاح!

    // --- 3. التحقق من تطابق كلمة المرور ---
    if ($password !== $password_confirm) {
        // إذا لم تتطابق، أعده لصفحة التسجيل مع رسالة خطأ
        header("Location: register.php?error=password");
        exit;
    }

    // --- 4. تأمين كلمة المرور ---
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // --- 5. إدخال المدير الجديد في قاعدة البيانات ---
    try {
        // إضافة المستخدم إلى جدول 'users'
        $sql = "INSERT INTO users (name, email, password_hash, role) 
                VALUES (?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $email, $password_hash, $role]);

        // (ملاحظة: لن نضيف أي شيء إلى "client_details" لأن هذا حساب "مدير")

        // عرض رسالة نجاح
        echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><title>تم بنجاح</title><link rel="stylesheet" href="style.css"></head><body>';
        echo '<div class="container" style="max-width: 500px;">';
        echo '<h1>✅ تم إنشاء حساب المدير بنجاح!</h1>';
        echo '<p>يمكنك الآن تسجيل الدخول باستخدام بريدك الإلكتروني وكلمة المرور.</p>';
        echo '<a href="login.php" class="btn" style="background-color: #28a745; text-decoration: none;">الذهاب إلى صفحة تسجيل الدخول</a>';
        echo '</div></body></html>';

    } catch (PDOException $e) {
        // التحقق مما إذا كان الخطأ بسبب تكرار البريد الإلكتروني
        if ($e->errorInfo[1] == 1062) {
            header("Location: register.php?error=email");
            exit;
        } else {
            // أي خطأ آخر
            die("<h1>❌ حدث خطأ في قاعدة البيانات.</h1> <p>" . $e->getMessage() . "</p>");
        }
    }

} else {
    // إذا حاول شخص فتح هذا الملف مباشرة
    header("Location: register.php");
    exit;
}
?>