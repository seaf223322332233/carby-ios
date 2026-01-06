<?php
header('Content-Type: text/html; charset=utf-8');
require_once 'auth_admin.php'; 
require_once 'db_connect.php'; 

// إضافة تصنيف
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_cat'])) {
    $name = trim($_POST['name']);
    if(!empty($name)) {
        $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->execute([$name]);
        header("Location: manage_categories.php?success=1"); exit;
    }
}

// حذف تصنيف
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
    header("Location: manage_categories.php?success=deleted"); exit;
}

$cats = $pdo->query("SELECT * FROM categories ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إدارة التصنيفات</title>
    <link rel="stylesheet" href="admin_colors.php">
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
            <div class="user-info">إدارة التصنيفات</div>
            <a href="logout.php" class="logout-link">خروج</a>
        </header>

        <main class="content-wrapper">
            <div class="form-card">
                <h2><i class="fas fa-tags"></i> إضافة تصنيف جديد</h2>
                <form method="POST" style="display:flex; gap:10px;">
                    <input type="text" name="name" placeholder="اسم التصنيف (مثال: دجاج، حلويات...)" required style="flex:1;">
                    <button type="submit" name="add_cat" class="btn btn-primary">إضافة</button>
                </form>
            </div>

            <div class="grid-3-col" style="margin-top:20px;">
                <?php foreach($cats as $cat): ?>
                <div class="card" style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;"><?php echo htmlspecialchars($cat['name']); ?></h3>
                    <a href="manage_categories.php?delete=<?php echo $cat['id']; ?>" class="btn-delete" onclick="return confirm('حذف هذا التصنيف؟ سيتم حذف جميع المنتجات بداخله!');"><i class="fas fa-trash"></i></a>
                </div>
                <?php endforeach; ?>
            </div>
        </main>
    </div>
</body>
</html>