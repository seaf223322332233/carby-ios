<?php
// 1. تضمين الحارس وملف الاتصال (للتأكد أن المدير هو من يقوم بالتعديل)
require_once 'auth_admin.php';
require_once 'db_connect.php'; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 2. استلام البيانات
    $staff_id = $_POST['staff_id'];
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password']; // كلمة المرور الجديدة (قد تكون فارغة)
    $role = $_POST['role'];

    // 3. التحقق من البيانات
    if (empty($staff_id) || empty($name) || empty($email) || empty($role)) {
        die("خطأ: بيانات الموظف الأساسية غير كاملة.");
    }
    if ($role !== 'chef' && $role !== 'driver') {
        die("خطأ: دور الموظف غير صالح.");
    }

    try {
        // 4. بناء جملة التحديث (SQL)
        
        // التحقق من كلمة المرور: هل تم إدخال كلمة مرور جديدة؟
        if (!empty($password)) {
            // نعم، قم بإنشاء هاش جديد
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // جملة SQL مع تحديث كلمة المرور
            $sql = "UPDATE users SET 
                        name = ?, 
                        email = ?, 
                        password_hash = ?, 
                        role = ? 
                    WHERE id = ?";
            $params = [$name, $email, $password_hash, $role, $staff_id];
        } else {
            // لا، لا تقم بتحديث كلمة المرور
            // جملة SQL بدون تحديث كلمة المرور
            $sql = "UPDATE users SET 
                        name = ?, 
                        email = ?, 
                        role = ? 
                    WHERE id = ?";
            $params = [$name, $email, $role, $staff_id];
        }
        
        // 5. تنفيذ التحديث
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // 6. إعادة التوجيه إلى صفحة عرض الموظفين
        header("Location: view_staff.php?success=edit");
        exit;

    } catch (PDOException $e) {
        if ($e->errorInfo[1] == 1062) {
            die("<h1>❌ خطأ: البريد الإلكتروني '$email' مستخدم بالفعل لمستخدم آخر.</h1> <a href='javascript:history.back()'>العودة</a>");
        } else {
            die("<h1>❌ حدث خطأ أثناء تحديث البيانات.</h1> <p>" . $e->getMessage() . "</p>");
        }
    }

} else {
    // إذا لم يكن الطلب POST
    header("Location: view_staff.php");
    exit;
}
?>