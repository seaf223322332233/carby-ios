<?php
// إجبار المتصفح على استخدام ترميز UTF-8
header('Content-Type: text/html; charset=utf-8');
require_once 'auth_admin.php'; 
require_once 'db_connect.php'; 

// التحقق من وجود ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("خطأ: معرّف الفرع غير موجود.");
}
$branch_id = (int)$_GET['id'];

// جلب بيانات الفرع
try {
    $stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
    $stmt->execute([$branch_id]);
    $branch = $stmt->fetch();
    if (!$branch) { die("الفرع غير موجود."); }
} catch (PDOException $e) { die("خطأ: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تعديل الفرع</title>
    <link rel="stylesheet" href="admin-unified-style-v2.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
</head>
<body>

    <div class="sidebar">
        <?php include 'sidebar_menu.php'; // أو انسخ كود القائمة هنا ?>
       <?php include 'sidebar.php'; ?>
    </div>

    <div class="main-content">
        <header class="top-bar">
            <div class="user-info">لوحة التحكم</div>
            <a href="logout.php" class="logout-link">خروج</a>
        </header>

        <main class="content-wrapper">
            <div class="form-card">
                <h2><i class="fas fa-edit"></i> تعديل الفرع: <?php echo htmlspecialchars($branch['name']); ?></h2>

                <form action="handle_edit_branch.php" method="POST">
                    <input type="hidden" name="branch_id" value="<?php echo $branch['id']; ?>">

                    <div class="form-group">
                        <label>اسم الفرع</label>
                        <input type="text" name="name" value="<?php echo htmlspecialchars($branch['name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>العنوان كتابة</label>
                        <input type="text" name="address" value="<?php echo htmlspecialchars($branch['address']); ?>" required>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label>رقم التواصل</label>
                            <input type="text" name="phone_number" value="<?php echo htmlspecialchars($branch['phone_number']); ?>">
                        </div>
                        <div class="form-group">
                            <label>ساعات العمل</label>
                            <input type="text" name="working_hours" value="<?php echo htmlspecialchars($branch['working_hours']); ?>">
                        </div>
                        <div class="form-group">
                            <label>رابط الخريطة (Google Maps)</label>
                            <input type="url" name="google_maps_link" value="<?php echo htmlspecialchars($branch['google_maps_link']); ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn" style="background-color: #28a745;">
                        <i class="fas fa-save"></i> حفظ التعديلات
                    </button>
                    <a href="view_branches.php" class="btn" style="background-color: #6c757d; margin-top: 10px; text-align: center;">إلغاء</a>
                </form>
            </div>
        </main>
    </div>
</body>
</html>