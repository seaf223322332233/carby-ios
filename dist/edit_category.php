<?php
header('Content-Type: text/html; charset=utf-8');
require_once 'auth_admin.php'; 
require_once 'db_connect.php'; 

if (!isset($_GET['id'])) { header("Location: view_categories.php"); exit; }
$id = (int)$_GET['id'];

$stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
$stmt->execute([$id]);
$cat = $stmt->fetch();

if (!$cat) { die("التصنيف غير موجود."); }
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تعديل التصنيف</title>
    <link rel="stylesheet" href="admin-unified-style-v2.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
</head>
<body>
    <div class="sidebar">
        <?php include 'sidebar.php'; ?>
    </div>

    <div class="main-content">
        <header class="top-bar">
            <div class="user-info">تعديل التصنيف</div>
            <a href="logout.php" class="logout-link">خروج</a>
        </header>

        <main class="content-wrapper">
            <div class="form-card">
                <h2><i class="fas fa-edit"></i> تعديل: <?php echo htmlspecialchars($cat['name']); ?></h2>
                
                <form action="handle_categories.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                    <input type="hidden" name="old_image" value="<?php echo htmlspecialchars($cat['image_url']); ?>">
                    
                    <div class="form-group">
                        <label>اسم التصنيف</label>
                        <input type="text" name="name" value="<?php echo htmlspecialchars($cat['name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>الوصف</label>
                        <textarea name="description" rows="2"><?php echo htmlspecialchars($cat['description']); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>تغيير الصورة</label>
                        <?php if(!empty($cat['image_url'])): ?>
                            <img src="<?php echo $cat['image_url']; ?>" style="width:100px; border-radius:10px; margin-bottom:10px; display:block;">
                        <?php endif; ?>
                        <input type="file" name="image" accept="image/*">
                    </div>
                    
                    <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
                    <a href="view_categories.php" class="btn" style="background:#666;">إلغاء</a>
                </form>
            </div>
        </main>
    </div>
</body>
</html>