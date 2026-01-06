<?php
// إجبار المتصفح على استخدام ترميز UTF-8
header('Content-Type: text/html; charset=utf-8');

// 1. تضمين "الحارس"
require_once 'auth_preparer.php'; 

// 2. تضمين ملف الاتصال
require_once 'db_connect.php'; 

// 3. جلب سجل الطلبات (الأرشيف)
$archive_data = [];
try {
    $sql_archive = "SELECT 
                        log.delivery_date, 
                        u.name AS client_name,
                        m.name AS meal_name, 
                        m.protein_type,
                        m.protein_weight,
                        log.category,
                        log.status
                    FROM 
                        delivery_log AS log
                    JOIN 
                        meals AS m ON log.meal_id = m.id
                    JOIN
                        users AS u ON log.client_id = u.id
                    WHERE 
                        log.delivery_date < CURDATE() -- (فقط الأيام السابقة)
                    ORDER BY 
                        log.delivery_date DESC, u.name ASC, m.name ASC";
                            
    $stmt_archive = $pdo->prepare($sql_archive);
    $stmt_archive->execute();
    $archive_data = $stmt_archive->fetchAll();
    
} catch (PDOException $e) { die("خطأ في جلب الأرشيف: " . $e->getMessage()); }

// (دوال الترجمة المساعدة)
function translate_category($category) {
    $map = ['fator' => 'فطور', 'ghada' => 'غداء', 'asha' => 'عشاء'];
    return $map[$category] ?? $category;
}
function translate_status($status) {
    $map = ['delivered' => 'تم التوصيل', 'cancelled' => 'ملغي', 'prepared' => 'تم التحضير (لم يوصل)'];
    return $map[$status] ?? $status;
}
function translate_protein_type($type) {
    $map = ['dajaj' => 'دجاج', 'lahm' => 'لحم', 'samak' => 'سمك'];
    return $map[$type] ?? $type;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>سجل الطلبات (الأرشيف) - المجهز</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .preparer-sidebar { background-color: #5f27cd; }
        .preparer-sidebar-header { border-bottom: 1px solid #a36aff; }
    </style>
</head>
<body>

    <div class="sidebar preparer-sidebar">
        <div class="sidebar-header preparer-sidebar-header">
            <h3><i class="fas fa-box-open"></i> تجهيز الطلبات</h3>
        </div>
        <nav class="sidebar-nav">
            <a href="preparer_dashboard.php"><i class="fas fa-clipboard-list"></i> قائمة التجهيز</a>
            <a href="preparer_archive.php" class="active"><i class="fas fa-history"></i> سجل الطلبات</a>
        </nav>
    </div>

    <div class="main-content">
        <header class="top-bar">
             <div class="user-info">
                مرحباً، <?php echo htmlspecialchars($user_name_session, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <a href="logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> تسجيل الخروج</a>
        </header>

        <main class="content-wrapper">
            <div class="form-card"> 
                <h2><i class="fas fa-history"></i> سجل الطلبات السابقة (الأرشيف)</h2>
                <p>يعرض هذا التقرير جميع الأصناف الفردية التي تم تجهيزها في الأيام السابقة.</p>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>العميل</th>
                            <th>الوجبة</th>
                            <th>المواصفات</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($archive_data)): ?>
                            <tr><td colspan="5" style="text-align: center;">لا يوجد بيانات لعرضها في الأرشيف (يتم عرض الطلبات من الأمس وما قبله).</td></tr>
                        <?php else: ?>
                            <?php foreach ($archive_data as $item): ?>
                                <tr>
                                    <td><?php echo $item['delivery_date']; ?></td>
                                    <td><?php echo htmlspecialchars($item['client_name']); ?></td>
                                    <td><?php echo htmlspecialchars($item['meal_name']); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['protein_weight']); ?> جرام</strong> - 
                                        <?php echo translate_protein_type($item['protein_type']); ?>
                                    </td>
                                    <td><?php echo translate_status($item['status']); ?></td>
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