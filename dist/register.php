<?php
// تضمين ملف الاتصال للتحقق من قاعدة البيانات
require_once 'db_connect.php';

// التحقق مما إذا كان هناك أي مستخدمين في قاعدة البيانات
$stmt = $pdo->query("SELECT COUNT(*) FROM users");
$user_count = $stmt->fetchColumn();

// إذا كان هناك مستخدم واحد أو أكثر، لا تسمح بإنشاء حساب جديد
if ($user_count > 0) {
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><title>خطأ</title><link rel="stylesheet" href="style.css"></head><body>';
    echo '<div class="container" style="max-width: 500px;">';
    echo '<h2 style="color: #dc3545;">❌ خطأ: المدير موجود بالفعل!</h2>';
    echo '<p>لا يمكن إنشاء حسابات جديدة من هذه الصفحة.</p>';
    echo '<a href="login.php" class="btn" style="background-color: #007bff; text-decoration: none;">الانتقال إلى صفحة تسجيل الدخول</a>';
    echo '</div></body></html>';
    exit; // أوقف تنفيذ الكود
}

// إذا كان $user_count يساوي 0، سيستمر الكود ويظهر الفورم أدناه
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إنشاء حساب المدير</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .note {
            background-color: #fffbe6;
            color: #725a0b;
            padding: 10px;
            border: 1px solid #ffe58f;
            border-radius: 4px;
            text-align: center;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>

    <div class="container" style="max-width: 450px;">
        <h2>إنشاء حساب المدير الأول</h2>
        
        <div class="note">
            مرحباً بك! هذا الحساب الذي سيتم إنشاؤه الآن سيكون هو **المدير (Admin)** الرئيسي للموقع.
        </div>

        <?php
        // لعرض رسائل الخطأ (مثل: كلمة المرور غير متطابقة)
        if (isset($_GET['error'])) {
            if ($_GET['error'] == 'password') {
                echo '<div class="error">خطأ: كلمتا المرور غير متطابقتين.</div>';
            }
            if ($_GET['error'] == 'email') {
                echo '<div class="error">خطأ: البريد الإلكتروني مستخدم.</div>';
            }
        }
        ?>

        <form action="handle_register.php" method="POST">

            <div class="form-group">
                <label for="name">الاسم الكامل:</label>
                <input type="text" id="name" name="name" required>
            </div>
            
            <div class="form-group">
                <label for="email">البريد الإلكتروني (لتسجيل الدخول):</label>
                <input type="email" id="email" name="email" required>
            </div>

            <div class="form-group">
                <label for="password">كلمة المرور:</label>
                <input type="password" id="password" name="password" required>
            </div>

            <div class="form-group">
                <label for="password_confirm">تأكيد كلمة المرور:</label>
                <input type="password" id="password_confirm" name="password_confirm" required>
            </div>

            <button type="submit" class="btn">إنشاء حساب المدير</button>

        </form>
    </div>

</body>
</html>