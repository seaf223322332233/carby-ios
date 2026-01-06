<?php
// إجبار المتصفح على استخدام ترميز UTF-8
header('Content-Type: text/html; charset=utf-8');
// تضمين "الحارس" (المصادقة)
require_once 'auth_admin.php'; 
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم - إضافة موظف</title>
    <link rel="stylesheet" href="admin-unified-style-v2.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    <style>
        .alert-message { padding: 15px; margin-bottom: 20px; border-radius: 8px; font-weight: 600; text-align: center; }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="sidebar-header">
            <h3><i class="fas fa-cogs"></i> لوحة التحكم</h3>
        </div>
        <?php include 'sidebar.php'; ?>
    </div>

    <div class="main-content">
        <header class="top-bar">
            <div class="user-info">
                مرحباً، <?php echo htmlspecialchars($admin_name, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <a href="logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> تسجيل الخروج</a>
        </header>

        <main class="content-wrapper">
            <div class="form-card">
                <h2><i class="fas fa-user-shield"></i> إضافة موظف جديد</h2>

                <?php if (isset($_GET['success'])): ?>
                    <div class="alert-message alert-success">
                        <i class="fas fa-check-circle"></i> تم إضافة الموظف بنجاح!
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['error'])): ?>
                    <div class="alert-message alert-error">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <?php 
                        // عرض رسالة الخطأ الصحيحة
                        if ($_GET['error'] == 'email') {
                            echo 'هذا البريد الإلكتروني مستخدم بالفعل.';
                        } elseif ($_GET['error'] == 'role') {
                            echo 'الرجاء اختيار دور صحيح للموظف.';
                        } elseif ($_GET['error'] == 'empty') {
                            echo 'الرجاء ملء جميع الحقول الإجبارية.';
                        } else {
                            echo 'حدث خطأ غير معروف.';
                        }
                        ?>
                    </div>
                <?php endif; ?>
                <form action="handle_add_staff.php" method="POST">
                    
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
                        <label for="role">دور الموظف:</label>
                        <select id="role" name="role" required>
                            <option value="">-- اختر الدور --</option>
                            <option value="chef">شيف (للملخص المجمع)</option>
                            <option value="preparer">مجهز طلبات (للملصقات)</option>
                            <option value="driver">سائق (للتوصيل)</option>
                        </select>
                    </div>

                    <button type="submit" class="btn">إضافة الموظف</button>
                </form>
            </div>
        </main>
    </div>
</body>
</html>