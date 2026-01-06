<?php
// إجبار المتصفح على استخدام ترميز UTF-8
header('Content-Type: text/html; charset=utf-8');

// 1. تضمين "حارس الشيف"
require_once 'auth_chef.php'; 

// 2. تضمين ملف الاتصال
require_once 'db_connect.php'; 

// 3. جلب سجل الطلبات (الأرشيف)
// (هذا يجلب ملخص الكميات للأيام السابقة)
$archive_data = [];
try {
    // *** هذا هو الاستعلام المُصحح (لا يحتوي على 'meal_components') ***
    $sql_archive = "SELECT 
                        log.delivery_date, 
                        m.protein_type, 
                        m.protein_weight, 
                        COUNT(log.id) AS total_orders
                    FROM 
                        delivery_log AS log
                    JOIN 
                        meals AS m ON log.meal_id = m.id
                    WHERE 
                        log.delivery_date < CURDATE() -- (أهم شرط: فقط الأيام السابقة)
                    GROUP BY 
                        log.delivery_date, m.protein_type, m.protein_weight
                    ORDER BY 
                        log.delivery_date DESC, total_orders DESC"; // الأحدث أولاً
                            
    $stmt_archive = $pdo->prepare($sql_archive);
    $stmt_archive->execute();
    $archive_data = $stmt_archive->fetchAll();
    
} catch (PDOException $e) { die("خطأ في جلب الأرشيف: " . $e->getMessage()); }

// (دوال مساعدة للترجمة)
function translate_protein_type($type) {
    $map = ['dajaj' => 'دجاج', 'lahm' => 'لحم', 'samak' => 'سمك'];
    return $map[$type] ?? $type;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>سجل الطلبات (الأرشيف) - الشيف</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .chef-sidebar { background-color: #d35400; }
        .chef-sidebar-header { border-bottom: 1px solid #f0a273; }
        .chef-top-bar { background-color: #fdfefe; }
    </style>
</head>
<body>

    <div class="sidebar chef-sidebar">
        <div class="sidebar-header chef-sidebar-header">
            <h3><i class="fas fa-utensils"></i> واجهة الشيف</h3>
        </div>
        <nav class="sidebar-nav">
            <a href="chef_dashboard.php"><i class="fas fa-chart-pie"></i> ملخص التحضير</a>
            <a href="preparer_dashboard.php"><i class="fas fa-box-open"></i> تجهيز الطلبات</a>
            <a href="chef_archive.php" class="active"><i class="fas fa-history"></i> سجل الطلبات</a>
        </nav>
    </div>

    <div class="main-content">
        <header class="top-bar chef-top-bar">
            <div class="user-info">
                مرحباً، <?php echo htmlspecialchars($user_name_session, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <a href="logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> تسجيل الخروج</a>
        </header>

        <main class="content-wrapper">
            <div class="form-card"> 
                <h2><i class="fas fa-history"></i> سجل الطلبات السابقة (الأرشيف)</h2>
                <p>يعرض هذا التقرير ملخص الكميات الإجمالي للوجبات التي تم إنشاؤها في الأيام السابقة.</p>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>تاريخ الطلب</th>
                            <th>مواصفات الوجبة</th>
                            <th>الكمية الإجمالية</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($archive_data)): ?>
                            <tr><td colspan="3" style="text-align: center;">لا يوجد بيانات لعرضها في الأرشيف حتى الآن.</td></tr>
                        <?php else: ?>
                            <?php foreach ($archive_data as $item): ?>
                                <tr>
                                    <td><?php echo $item['delivery_date']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['protein_weight']); ?> جرام</strong>
                                        - (<?php echo translate_protein_type($item['protein_type']); ?>)
                                    </td>
                                    <td><strong><?php echo $item['total_orders']; ?></strong></td>
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