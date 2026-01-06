<?php
// إجبار المتصفح على استخدام ترميز UTF-8
header('Content-Type: text/html; charset=utf-8');

require_once 'auth_admin.php'; 
require_once 'db_connect.php'; 

// جلب جميع التصنيفات
$categories = [];
try {
    $stmt = $pdo->query("SELECT * FROM categories ORDER BY id DESC");
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    die("خطأ في قاعدة البيانات: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إدارة التصنيفات</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin-unified-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    <style>
        .category-img { width: 60px; height: 60px; object-fit: cover; border-radius: 8px; border: 1px solid #eee; }
        .alert-message { padding: 15px; margin-bottom: 20px; border-radius: 8px; font-weight: 600; text-align: center; }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>

    <div class="sidebar">
        <?php include 'sidebar.php'; // أو انسخ الكود الموحد ?>
    </div>

    <div class="main-content">
        <header class="top-bar">
            <div class="user-info">مرحباً، المدير</div>
            <a href="logout.php" class="logout-link">خروج</a>
        </header>

        <main class="content-wrapper">
            
            <?php if (isset($_GET['success'])): ?>
                <div class="alert-message alert-success">
                    <i class="fas fa-check-circle"></i> تمت العملية بنجاح!
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
                <div class="alert-message alert-error">
                    <i class="fas fa-exclamation-triangle"></i> حدث خطأ، يرجى المحاولة مرة أخرى.
                </div>
            <?php endif; ?>

            <div class="form-card">
                <h2><i class="fas fa-tags"></i> إضافة تصنيف جديد</h2>
                <form action="handle_categories.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="form-group">
                        <label>اسم التصنيف (مثال: دجاج، مشويات، حلويات)</label>
                        <input type="text" name="name" required placeholder="أدخل اسم التصنيف">
                    </div>
                    
                    <div class="form-group">
                        <label>وصف مختصر (اختياري)</label>
                        <textarea name="description" rows="2" placeholder="وصف يظهر للعميل..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>صورة التصنيف (اختياري)</label>
                        <input type="file" name="image" accept="image/*">
                    </div>
                    
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus"></i> حفظ التصنيف
                    </button>
                </form>
            </div>

            <div class="form-card" style="margin-top: 30px;">
                <h2><i class="fas fa-list"></i> التصنيفات الحالية (<?php echo count($categories); ?>)</h2>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>الصورة</th>
                            <th>الاسم</th>
                            <th>الوصف</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($categories)): ?>
                            <tr><td colspan="4" style="text-align:center; padding:20px;">لا توجد تصنيفات مضافة.</td></tr>
                        <?php else: ?>
                            <?php foreach ($categories as $cat): 
                                $img = !empty($cat['image_url']) ? $cat['image_url'] : 'uploads/meals/placeholder.png';
                            ?>
                            <tr>
                                <td>
                                    <img src="<?php echo htmlspecialchars($img); ?>" class="category-img">
                                </td>
                                <td><strong><?php echo htmlspecialchars($cat['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($cat['description']); ?></td>
                                <td class="action-buttons">
                                    <a href="edit_category.php?id=<?php echo $cat['id']; ?>" class="btn-edit">
                                        <i class="fas fa-edit"></i> تعديل
                                    </a>
                                    <a href="handle_categories.php?action=delete&id=<?php echo $cat['id']; ?>" class="btn-delete" onclick="return confirm('هل أنت متأكد من حذف هذا التصنيف؟');">
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