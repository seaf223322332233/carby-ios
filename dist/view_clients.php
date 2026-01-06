<?php
header('Content-Type: text/html; charset=utf-8');
require_once 'auth_admin.php'; 
require_once 'db_connect.php';
require_once 'csrf.php'; 

// تم نقل معالجة الحذف إلى handle_delete_client.php (آمن مع CSRF)

// جلب العملاء مع تفاصيل الباقة
$clients = [];
try {
    $sql = "SELECT 
                u.id, u.name, u.email, 
                cd.phone_number, cd.subscription_end_date, 
                p.name as package_name 
            FROM users u 
            LEFT JOIN client_details cd ON u.id = cd.user_id 
            LEFT JOIN packages p ON cd.package_id = p.id 
            WHERE u.role = 'client' 
            ORDER BY u.id DESC";
    $clients = $pdo->query($sql)->fetchAll();
} catch (PDOException $e) {
    die("خطأ: " . $e->getMessage());
}

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إدارة العملاء</title>
    <link rel="stylesheet" href="admin_colors.php">
    <link rel="stylesheet" href="admin-unified-style-v2.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
</head>
<body>

    <div class="sidebar"><?php include 'sidebar.php'; ?></div>

    <div class="main-content">
        <header class="top-bar">
            <div class="user-info">العملاء</div>
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
                    <?php
                    $errors = [
                        'csrf' => 'خطأ في التحقق من الأمان. يرجى المحاولة مرة أخرى.',
                        'invalid' => 'معرّف غير صالح.',
                        'notfound' => 'العميل غير موجود.',
                        'server' => 'حدث خطأ في الخادم.'
                    ];
                    $errorMsg = $errors[$_GET['error']] ?? 'حدث خطأ غير متوقع.';
                    echo '<i class="fas fa-exclamation-circle"></i> ' . htmlspecialchars($errorMsg);
                    ?>
                </div>
            <?php endif; ?>

            <div class="form-card" style="padding:0; overflow:hidden;">
                
                <div style="padding:20px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #eee;">
                    <h2><i class="fas fa-users"></i> قائمة المشتركين</h2>
                    <a href="add_client.php" class="btn btn-success">
                        <i class="fas fa-user-plus"></i> إضافة عميل جديد
                    </a>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>الاسم</th>
                            <th>الجوال</th>
                            <th>الباقة</th>
                            <th>حالة الاشتراك</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($clients)): ?>
                            <tr><td colspan="5" style="text-align:center; padding:30px;">لا يوجد عملاء حالياً.</td></tr>
                        <?php else: ?>
                            <?php foreach ($clients as $client): 
                                $is_active = ($client['subscription_end_date'] >= $today);
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($client['name']); ?></strong><br>
                                    <small style="color:#888;"><?php echo htmlspecialchars($client['email']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($client['phone_number']); ?></td>
                                <td>
                                    <span style="background:#e3f2fd; color:#0d47a1; padding:2px 8px; border-radius:10px; font-size:0.85rem;">
                                        <?php echo htmlspecialchars($client['package_name'] ?? 'لا توجد باقة'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($is_active): ?>
                                        <span style="color:green; font-weight:bold;">ساري (<?php echo $client['subscription_end_date']; ?>)</span>
                                    <?php else: ?>
                                        <span style="color:red; font-weight:bold;">منتهي</span>
                                    <?php endif; ?>
                                </td>
                                <td class="action-buttons">
                                    <a href="edit_client.php?id=<?php echo $client['id']; ?>" class="btn-edit"><i class="fas fa-edit"></i></a>
                                    <form method="POST" action="handle_delete_client.php" style="display:inline;" onsubmit="return confirm('حذف العميل نهائياً؟');">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="delete_id" value="<?php echo $client['id']; ?>">
                                        <button type="submit" class="btn-delete" style="border:none; background:none; cursor:pointer; color:#e74c3c;"><i class="fas fa-trash"></i></button>
                                    </form>
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