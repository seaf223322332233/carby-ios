<?php
// إجبار المتصفح على استخدام ترميز UTF-8
header('Content-Type: text/html; charset=utf-8');

// 1. تضمين ملفات الحماية والاتصال
require_once 'auth_admin.php'; 
require_once 'db_connect.php'; 

// --- 2. معالجة إضافة فرع جديد ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_branch'])) {
    $name = trim($_POST['name']);
    $address = trim($_POST['address']);
    $map = trim($_POST['google_maps_link']); // الاسم الصحيح
    $phone = trim($_POST['phone_number']);
    $hours = trim($_POST['working_hours']);

    if (!empty($name) && !empty($address)) {
        try {
            $sql = "INSERT INTO branches (name, address, google_maps_link, phone_number, working_hours) VALUES (?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$name, $address, $map, $phone, $hours]);
            
            header("Location: view_branches.php?success=add");
            exit;
        } catch (PDOException $e) {
            die("<h1>❌ حدث خطأ أثناء إضافة الفرع:</h1><p>" . $e->getMessage() . "</p>");
        }
    } else {
        header("Location: view_branches.php?error=empty");
        exit;
    }
}

// --- 3. معالجة حذف فرع ---
if (isset($_GET['delete_id'])) {
    $id = (int)$_GET['delete_id'];
    try {
        // حذف الفرع
        $stmt = $pdo->prepare("DELETE FROM branches WHERE id = ?");
        $stmt->execute([$id]);
        
        header("Location: view_branches.php?success=delete");
        exit;
    } catch (PDOException $e) {
        // غالباً بسبب ارتباطه بطلبات
        die("<h1>❌ لا يمكن حذف هذا الفرع:</h1><p>هناك طلبات مرتبطة به. (الخطأ: " . $e->getMessage() . ")</p>");
    }
}

// --- 4. جلب الفروع للعرض ---
try {
    $branches = $pdo->query("SELECT * FROM branches ORDER BY id DESC")->fetchAll();
} catch (PDOException $e) {
    $branches = [];
    echo "<div style='background:red; color:white; padding:10px; text-align:center;'>تنبيه: جدول الفروع غير موجود أو به مشكلة.</div>";
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الفروع</title>
    
    <link rel="stylesheet" href="admin-unified-style-v2.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        /* تنسيقات إضافية للصفحة */
        .grid-3-col { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
        @media (max-width: 768px) { .grid-3-col { grid-template-columns: 1fr; } }
        
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
                مرحباً، <?php echo htmlspecialchars($admin_name ?? 'المدير'); ?>
            </div>
            <a href="logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> تسجيل الخروج</a>
        </header>

        <main class="content-wrapper">
            
            <?php if (isset($_GET['success'])): ?>
                <div class="alert-message alert-success">
                    <i class="fas fa-check-circle"></i> تمت العملية بنجاح!
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
                <div class="alert-message alert-error">
                    <i class="fas fa-exclamation-triangle"></i> يرجى ملء الحقول المطلوبة (الاسم والعنوان).
                </div>
            <?php endif; ?>

            <div class="form-card">
                <h2><i class="fas fa-store"></i> إضافة فرع جديد</h2>
                
                <form action="view_branches.php" method="POST">
                    <div class="form-group">
                        <label>اسم الفرع:</label>
                        <input type="text" name="name" required placeholder="مثال: فرع الملقا - الرياض">
                    </div>
                    <div class="form-group">
                        <label>العنوان كتابة:</label>
                        <input type="text" name="address" required placeholder="الحي، الشارع، رقم المبنى...">
                    </div>
                    
                    <div class="grid-3-col">
                        <div class="form-group">
                            <label>رقم التواصل:</label>
                            <input type="text" name="phone_number" placeholder="05xxxxxxxx">
                        </div>
                        <div class="form-group">
                            <label>ساعات العمل:</label>
                            <input type="text" name="working_hours" placeholder="مثال: 8 صباحاً - 12 ليلاً">
                        </div>
                        <div class="form-group">
                            <label>رابط الموقع (Google Maps):</label>
                            <input type="url" name="google_maps_link" placeholder="https://goo.gl/maps/...">
                        </div>
                    </div>
                    
                    <button type="submit" name="add_branch" class="btn">
                        <i class="fas fa-plus-circle"></i> حفظ الفرع
                    </button>
                </form>
            </div>

            <div class="form-card" style="margin-top: 30px;">
                <h2><i class="fas fa-list-alt"></i> الفروع الحالية</h2>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>الفرع</th>
                            <th>العنوان / التواصل</th>
                            <th>ساعات العمل</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($branches)): ?>
                            <tr><td colspan="4" style="text-align:center; padding:20px;">لا توجد فروع مضافة حتى الآن.</td></tr>
                        <?php else: ?>
                            <?php foreach ($branches as $branch): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($branch['name']); ?></strong>
                                    <br>
                                    <?php if(!empty($branch['google_maps_link'])): ?>
                                        <a href="<?php echo htmlspecialchars($branch['google_maps_link']); ?>" target="_blank" style="font-size:0.85rem; color:#007bff; text-decoration:none;">
                                            <i class="fas fa-map-marker-alt"></i> عرض الموقع
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($branch['address']); ?>
                                    <br>
                                    <small style="color:#666;">
                                        <i class="fas fa-phone"></i> <?php echo htmlspecialchars($branch['phone_number']); ?>
                                    </small>
                                </td>
                                <td><?php echo htmlspecialchars($branch['working_hours']); ?></td>
                                <td class="action-buttons">
                                    <a href="edit_branch.php?id=<?php echo $branch['id']; ?>" class="btn-edit">
                                        <i class="fas fa-edit"></i> تعديل
                                    </a>
                                    <a href="view_branches.php?delete_id=<?php echo $branch['id']; ?>" class="btn-delete" onclick="return confirm('هل أنت متأكد من حذف هذا الفرع نهائياً؟')">
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