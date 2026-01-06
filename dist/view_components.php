<?php
// إجبار المتصفح على استخدام ترميز UTF-8
header('Content-Type: text/html; charset=utf-8');

// 1. تضمين "الحارس" (المصادقة)
require_once 'auth_admin.php'; 

// 2. تضمين ملف الاتصال
require_once 'db_connect.php'; 

// 3. معالجة إضافة مكون جديد (إذا تم إرسال الفورم)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_component'])) {
    $name = trim($_POST['name']);
    $unit = $_POST['unit'];
    if (!empty($name) && !empty($unit)) {
        try {
            $sql_insert = "INSERT INTO components (name, unit) VALUES (?, ?)";
            $stmt_insert = $pdo->prepare($sql_insert);
            $stmt_insert->execute([$name, $unit]);
            header("Location: view_components.php?success=add"); // أعد تحميل الصفحة مع رسالة نجاح
            exit;
        } catch (PDOException $e) {
            // (خطأ: المكون موجود مسبقاً)
            header("Location: view_components.php?error=duplicate");
            exit;
        }
    }
}

// 4. معالجة حذف مكون (إذا تم الضغط على زر الحذف)
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    try {
        $sql_delete = "DELETE FROM components WHERE id = ?";
        $stmt_delete = $pdo->prepare($sql_delete);
        $stmt_delete->execute([$delete_id]);
        header("Location: view_components.php?success=delete");
        exit;
    } catch (PDOException $e) {
        // (قد يفشل الحذف إذا كان المكون مستخدماً في وجبة)
        header("Location: view_components.php?error=in_use");
        exit;
    }
}

// 5. جلب جميع المكونات لعرضها في الجدول
try {
    $components = $pdo->query("SELECT * FROM components ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    die("خطأ في جلب المكونات: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>لوحة التحكم - إدارة المكونات</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin-unified-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    <style>
        /* (إضافة تنسيقات الرسائل) */
        .alert-message { padding: 15px; margin-bottom: 20px; border-radius: 8px; font-weight: 600; text-align: center; }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        /* *** إصلاح التنسيق: جعل الفورم عمودياً ومنظماً *** */
        .add-component-form {
            display: grid;
            grid-template-columns: 1fr 1fr auto; /* (مكون | وحدة | زر) */
            gap: 15px;
            align-items: flex-end; /* لمحاذاة الزر مع الحقول */
        }
        
        /* في الشاشات الصغيرة، اجعل الفورم عمودياً بالكامل */
        @media (max-width: 768px) {
            .add-component-form {
                grid-template-columns: 1fr; /* كل حقل في سطر */
            }
        }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="sidebar-header">
            <h3><i class="fas fa-cogs"></i> لوحة التحكم</h3>
        </div>
       <nav class="sidebar-nav">
    <?php 
        // جلب اسم الملف الحالي
        $currentPage = basename($_SERVER['PHP_SELF']); 
        
        // تحديد الصفحات "التابعة" (لتمييز القائمة الرئيسية)
        $isMealSection = in_array($currentPage, ['view_meals.php', 'add_meal.php', 'edit_meal.php']);
        $isClientSection = in_array($currentPage, ['view_clients.php', 'add_client.php', 'edit_client.php']);
        $isStaffSection = in_array($currentPage, ['view_staff.php', 'add_staff.php', 'edit_staff.php']);
        $isPackageSection = in_array($currentPage, ['view_packages.php', 'edit_package.php']);
        $isComponentSection = in_array($currentPage, ['view_components.php']);
    ?>
    
    <a href="admin_dashboard.php" class="<?php echo ($currentPage == 'admin_dashboard.php') ? 'active' : ''; ?>">
        <i class="fas fa-chart-line"></i> التقارير السريعة
    </a>
    <a href="meal_reports.php" class="<?php echo ($currentPage == 'meal_reports.php') ? 'active' : ''; ?>">
        <i class="fas fa-pizza-slice"></i> تقارير الوجبات
    </a> 
    
    <hr style="border-color: #4a637c;">
    
    <a href="view_packages.php" class="<?php echo ($isPackageSection) ? 'active' : ''; ?>">
        <i class="fas fa-box-open"></i> إدارة الباقات
    </a>
    <a href="view_components.php" class="<?php echo ($isComponentSection) ? 'active' : ''; ?>">
        <i class="fas fa-carrot"></i> إدارة المكونات
    </a>
    <a href="view_meals.php" class="<?php echo ($isMealSection) ? 'active' : ''; ?>">
        <i class="fas fa-list-alt"></i> إدارة الوجبات
    </a>
    <a href="add_meal.php" class="<?php echo ($currentPage == 'add_meal.php') ? 'active' : ''; ?>">
        <i class="fas fa-utensils"></i> إضافة وجبة
    </a>
    
    <hr style="border-color: #4a637c;">
    
    <a href="view_clients.php" class="<?php echo ($isClientSection) ? 'active' : ''; ?>">
        <i class="fas fa-users"></i> إدارة العملاء
    </a> 
    <a href="add_client.php" class="<?php echo ($currentPage == 'add_client.php') ? 'active' : ''; ?>">
        <i class="fas fa-user-plus"></i> إضافة عميل
    </a>
    
    <hr style="border-color: #4a637c;">
    
    <a href="view_staff.php" class="<?php echo ($isStaffSection) ? 'active' : ''; ?>">
        <i class="fas fa-users-cog"></i> إدارة الموظفين
    </a>
    <a href="add_staff.php" class="<?php echo ($currentPage == 'add_staff.php') ? 'active' : ''; ?>">
        <i class="fas fa-user-shield"></i> إضافة موظف
    </a>
</nav>
    </div>

    <div class="main-content">
        <header class="top-bar">
            <div class="user-info">
                مرحباً، <?php echo htmlspecialchars($admin_name, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <a href="logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> تسجيل الخروج</a>
        </header>

        <main class="content-wrapper">
            <?php if (isset($_GET['success'])): ?>
                <div class="alert-message alert-success">تمت العملية بنجاح!</div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
                <div class="alert-message alert-error">
                    <?php 
                    if ($_GET['error'] == 'duplicate') echo 'خطأ: هذا المكون موجود مسبقاً.';
                    elseif ($_GET['error'] == 'in_use') echo 'خطأ: لا يمكن حذف هذا المكون لأنه مستخدم في وجبة واحدة على الأقل.';
                    else echo 'حدث خطأ.';
                    ?>
                </div>
            <?php endif; ?>

            <div class="form-card">
                <h2><i class="fas fa-carrot"></i> إضافة مكون جديد (مادة خام)</h2>
                
                <form action="view_components.php" method="POST" class="add-component-form">
                    <div class="form-group">
                        <label for="name">اسم المكون (مثل: أرز، دجاج)</label>
                        <input type="text" id="name" name="name" required>
                    </div>
                    <div class="form-group">
                        <label for="unit">وحدة القياس</label>
                        <select id="unit" name="unit" required>
                            <option value="جرام">جرام (g)</option>
                            <option value="مل">مل (ml)</option>
                            <option value="قطعة">قطعة</option>
                        </select>
                    </div>
                    <button type="submit" name="add_component" class="btn">إضافة</button>
                </form>
            </div>

            <div class="form-card" style="margin-top: 20px;">
                <h2><i class="fas fa-list-ul"></i> المكونات الحالية</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>اسم المكون</th>
                            <th>وحدة القياس</th>
                            <th>إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($components)): ?>
                            <tr><td colspan="3" style="text-align: center;">لم تقم بإضافة أي مكونات بعد.</td></tr>
                        <?php else: ?>
                            <?php foreach ($components as $comp): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($comp['name']); ?></td>
                                    <td><?php echo htmlspecialchars($comp['unit']); ?></td>
                                    <td class="action-buttons">
                                        <a href="view_components.php?delete_id=<?php echo $comp['id']; ?>" class="btn-delete" onclick="return confirm('هل أنت متأكد؟ سيؤدي هذا لحذف المكون من جميع الوجبات المرتبط بها.');">
                                            <i class="fas fa-trash"></i> حذف
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>