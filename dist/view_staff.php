<?php
// إجبار المتصفح على استخدام ترميز UTF-8
header('Content-Type: text/html; charset=utf-8');

// 1. تضمين "الحارس" (المصادقة)
require_once 'auth_admin.php'; 

// 2. تضمين ملف الاتصال لجلب البيانات
require_once 'db_connect.php'; // يجلب $pdo

// 3. جلب جميع الموظفين (الشيفات والسائقين)
try {
    $sql = "SELECT id, name, email, role 
            FROM users 
            WHERE role = 'chef' OR role = 'driver' 
            ORDER BY role, name";
            
    $stmt = $pdo->query($sql);
    $staff_list = $stmt->fetchAll();
    
} catch (PDOException $e) {
    die("خطأ في جلب الموظفين: " . $e->getMessage());
}

// 4. دالة مساعدة لترجمة الدور
function translate_role($role) {
    switch ($role) {
        case 'chef': return 'شيف (مطبخ)';
        case 'driver': return 'سائق (توصيل)';
        default: return $role;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم - إدارة الموظفين</title>
    <link rel="stylesheet" href="admin-unified-style-v2.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
</head>
<body>

    <div class="sidebar">
        <div class="sidebar-header">
            <h3><i class="fas fa-cogs"></i> لوحة التحكم</h3>
        </div>
<?php include 'sidebar.php'; ?>    </div>

    <div class="main-content">

        <header class="top-bar">
            <div class="user-info">
                مرحباً، 
                <?php echo htmlspecialchars($admin_name, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <a href="logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> تسجيل الخروج</a>
        </header>

        <main class="content-wrapper">
            <div class="form-card"> 
                <h2><i class="fas fa-users-cog"></i> إدارة الموظفين (<?php echo count($staff_list); ?>)</h2>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>الاسم</th>
                            <th>البريد الإلكتروني (للدخول)</th>
                            <th>الدور (الوظيفة)</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (count($staff_list) === 0): 
                        ?>
                            <tr>
                                <td colspan="4" style="text-align: center;">لا يوجد موظفون مضافون حتى الآن.</td>
                            </tr>
                        <?php 
                        else: 
                            foreach ($staff_list as $staff): 
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($staff['name']); ?></td>
                                <td><?php echo htmlspecialchars($staff['email']); ?></td>
                                <td><?php echo translate_role($staff['role']); ?></td>
                                <td class="action-buttons">
                                    <a href="edit_staff.php?id=<?php echo $staff['id']; ?>" class="btn-edit">
                                        <i class="fas fa-edit"></i> تعديل
                                    </a>
                                    <a href="delete_staff.php?id=<?php echo $staff['id']; ?>" class="btn-delete" 
                                       onclick="return confirm('هل أنت متأكد من حذف هذا الموظف؟');">
                                        <i class="fas fa-trash"></i> حذف
                                    </a>
                                </td>
                            </tr>
                        <?php 
                            endforeach; 
                        endif; 
                        ?>
                    </tbody>
                </table>
            </div>
        </main>

    </div> </body>
</html>