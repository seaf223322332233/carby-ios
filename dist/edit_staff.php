<?php
// إجبار المتصفح على استخدام ترميز UTF-8
header('Content-Type: text/html; charset=utf-8');

// 1. تضمين "الحارس" (المصادقة)
require_once 'auth_admin.php'; 

// 2. تضمين ملف الاتصال
require_once 'db_connect.php'; 

// 3. التحقق من وجود ID الموظف في الرابط
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("خطأ: معرّف الموظف غير موجود أو غير صالح.");
}
$staff_id = $_GET['id'];

// 4. جلب بيانات الموظف الحالية
try {
    $sql = "SELECT id, name, email, role FROM users WHERE id = ? AND (role = 'chef' OR role = 'driver')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$staff_id]);
    $staff = $stmt->fetch();

    if (!$staff) {
        die("خطأ: الموظف غير موجود أو ليس لديه الصلاحية المناسبة.");
    }
} catch (PDOException $e) {
    die("خطأ في جلب بيانات الموظف: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم - تعديل موظف</title>
    <link rel="stylesheet" href="admin-unified-style-v2.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
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
                <h2><i class="fas fa-user-edit"></i> تعديل الموظف: <?php echo htmlspecialchars($staff['name']); ?></h2>

                <form action="handle_edit_staff.php" method="POST">
                    
                    <input type="hidden" name="staff_id" value="<?php echo $staff['id']; ?>">

                    <div class="form-group">
                        <label for="name">الاسم الكامل:</label>
                        <input type="text" id="name" name="name" 
                               value="<?php echo htmlspecialchars($staff['name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="email">البريد الإلكتروني (لتسجيل الدخول):</label>
                        <input type="email" id="email" name="email"
                               value="<?php echo htmlspecialchars($staff['email']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">كلمة المرور الجديدة (اختياري):</label>
                        <input type="password" id="password" name="password">
                        <small>اترك هذا الحقل فارغاً لعدم تغيير كلمة المرور.</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="role">دور الموظف:</label>
                        <select id="role" name="role" required>
                            <option value="">-- اختر الدور --</option>
                            <option value="chef" <?php echo ($staff['role'] == 'chef') ? 'selected' : ''; ?>>
                                شيف (Chef)
                            </option>
                            <option value="driver" <?php echo ($staff['role'] == 'driver') ? 'selected' : ''; ?>>
                                سائق (Driver)
                            </option>
                        </select>
                    </div>

                    <button type="submit" class="btn" style="background-color: #28a745;">
                        <i class="fas fa-save"></i> حفظ التعديلات
                    </button>
                    <a href="view_staff.php" class="btn" style="background-color: #6c757d; margin-top: 10px; text-align: center;">إلغاء</a>
                </form>
            </div>
        </main>
    </div>
</body>
</html>