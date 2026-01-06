<?php
// إجبار المتصفح على استخدام ترميز UTF-8
header('Content-Type: text/html; charset=utf-8');

// 1. تضمين الحارس وملف الاتصال
require_once 'auth_driver.php'; 
require_once 'db_connect.php'; 

// 2. جلب سجل التوصيل الخاص *بهذا السائق فقط*
$archive_data = [];
try {
    $sql_archive = "SELECT 
                        log.delivery_date, 
                        log.delivered_at,
                        u.name AS client_name,
                        m.name AS meal_name, 
                        log.category
                    FROM 
                        delivery_log AS log
                    JOIN 
                        users AS u ON log.client_id = u.id
                    JOIN 
                        meals AS m ON log.meal_id = m.id
                    WHERE 
                        log.driver_id = ? AND log.status = 'delivered'
                    ORDER BY 
                        log.delivered_at DESC
                    LIMIT 50"; // عرض آخر 50 عملية توصيل
                            
    $stmt_archive = $pdo->prepare($sql_archive);
    $stmt_archive->execute([$user_id_session]); // $user_id_session هو ID السائق
    $archive_data = $stmt_archive->fetchAll();
    
} catch (PDOException $e) { die("خطأ في جلب الأرشيف: " . $e->getMessage()); }

function translate_category($category) {
    $map = ['fator' => 'فطور', 'ghada' => 'غداء', 'asha' => 'عشاء'];
    return $map[$category] ?? $category;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>سجل توصيلاتي</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .driver-sidebar { background-color: #117a8b; }
        .driver-sidebar-header { border-bottom: 1px solid #3eb8d0; }
        .driver-top-bar { background-color: #fdfefe; }
    </style>
</head>
<body>

    <div class="sidebar driver-sidebar">
        <div class="sidebar-header driver-sidebar-header">
            <h3><i class="fas fa-truck"></i> واجهة السائق</h3>
        </div>
        <nav class="sidebar-nav">
            <a href="driver_dashboard.php"><i class="fas fa-list-alt"></i> طلبات التوصيل</a>
            <a href="driver_archive.php" class="active"><i class="fas fa-history"></i> سجل توصيلاتي</a>
        </nav>
    </div>

    <div class="main-content">
        <header class="top-bar driver-top-bar">
            <div class="user-info">
                مرحباً، <?php echo htmlspecialchars($user_name_session, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <a href="logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> تسجيل الخروج</a>
        </header>

        <main class="content-wrapper">
            <div class="form-card"> 
                <h2><i class="fas fa-history"></i> سجل الطلبات التي قمت بتوصيلها</h2>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>تاريخ الطلب</th>
                            <th>وقت التسليم</th>
                            <th>العميل</th>
                            <th>الوجبة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($archive_data)): ?>
                            <tr><td colspan="4" style="text-align: center;">لم تقم بتوصيل أي طلبات بعد.</td></tr>
                        <?php else: ?>
                            <?php foreach ($archive_data as $item): ?>
                                <tr>
                                    <td><?php echo $item['delivery_date']; ?></td>
                                    <td>
                                        <?php echo date('h:i A', strtotime($item['delivered_at'])); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['client_name']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($item['meal_name']); ?>
                                        <small>(<?php echo translate_category($item['category']); ?>)</small>
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