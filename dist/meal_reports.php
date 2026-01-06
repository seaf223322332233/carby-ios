<?php
// إجبار المتصفح على استخدام ترميز UTF-8
header('Content-Type: text/html; charset=utf-8');

// 1. تضمين "الحارس" (المصادقة)
require_once 'auth_admin.php'; 

// 2. تضمين ملف الاتصال لجلب البيانات
require_once 'db_connect.php'; // يجلب $pdo

// 3. تحديد التواريخ
$today_date = date('Y-m-d');
$week_start_date = date('Y-m-d', strtotime('last saturday')); // بداية هذا الأسبوع
$month_start_date = date('Y-m-01'); // بداية هذا الشهر

// 4. جلب الإحصائيات
try {
    // --- التقرير الأول: تقرير "اليوم" ---
    $sql_daily = "SELECT m.name, m.category, COUNT(log.id) AS total
                  FROM delivery_log AS log
                  JOIN meals AS m ON log.meal_id = m.id
                  WHERE log.delivery_date = ?
                  GROUP BY log.meal_id, m.name, m.category
                  ORDER BY total DESC";
    $stmt_daily = $pdo->prepare($sql_daily);
    $stmt_daily->execute([$today_date]);
    $report_daily = $stmt_daily->fetchAll();

    // --- التقرير الثاني: تقرير "الأسبوع" ---
    $sql_weekly = "SELECT m.name, m.category, COUNT(log.id) AS total
                   FROM delivery_log AS log
                   JOIN meals AS m ON log.meal_id = m.id
                   WHERE log.delivery_date >= ? 
                   GROUP BY log.meal_id, m.name, m.category
                   ORDER BY total DESC";
    $stmt_weekly = $pdo->prepare($sql_weekly);
    $stmt_weekly->execute([$week_start_date]);
    $report_weekly = $stmt_weekly->fetchAll();

    // --- التقرير الثالث: تقرير "الشهر" ---
    $sql_monthly = "SELECT m.name, m.category, COUNT(log.id) AS total
                    FROM delivery_log AS log
                    JOIN meals AS m ON log.meal_id = m.id
                    WHERE log.delivery_date >= ?
                    GROUP BY log.meal_id, m.name, m.category
                    ORDER BY total DESC";
    $stmt_monthly = $pdo->prepare($sql_monthly);
    $stmt_monthly->execute([$month_start_date]);
    $report_monthly = $stmt_monthly->fetchAll();

} catch (PDOException $e) {
    die("خطأ في جلب التقارير: " . $e->getMessage());
}

// دالة مساعدة للترجمة
function translate_category($category) {
    $map = ['fator' => 'فطور', 'ghada' => 'غداء', 'asha' => 'عشاء'];
    return $map[$category] ?? $category;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم - تقارير الوجبات</title>
    
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin-unified-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
        }
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
            </header>

        <main class="content-wrapper">
            <h2><i class="fas fa-pizza-slice"></i> تقارير الوجبات (حسب عدد الطلبات)</h2>
            
            <div class="reports-grid">
                
                <div class="form-card">
                    <h3><i class="fas fa-calendar-day"></i> تقرير اليوم (<?php echo $today_date; ?>)</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>الوجبة</th>
                                <th>التصنيف</th>
                                <th>العدد</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report_daily)): ?>
                                <tr><td colspan="3" style="text-align: center;">لا توجد بيانات لهذا اليوم.</td></tr>
                            <?php else: ?>
                                <?php foreach ($report_daily as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                                        <td><?php echo translate_category($item['category']); ?></td>
                                        <td><strong><?php echo $item['total']; ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="form-card">
                    <h3><i class="fas fa-calendar-week"></i> تقرير الأسبوع (يبدأ من <?php echo $week_start_date; ?>)</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>الوجبة</th>
                                <th>التصنيف</th>
                                <th>العدد</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report_weekly)): ?>
                                <tr><td colspan="3" style="text-align: center;">لا توجد بيانات لهذا الأسبوع.</td></tr>
                            <?php else: ?>
                                <?php foreach ($report_weekly as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                                        <td><?php echo translate_category($item['category']); ?></td>
                                        <td><strong><?php echo $item['total']; ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="form-card">
                    <h3><i class="fas fa-calendar-alt"></i> تقرير الشهر (يبدأ من <?php echo $month_start_date; ?>)</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>الوجبة</th>
                                <th>التصنيف</th>
                                <th>العدد</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report_monthly)): ?>
                                <tr><td colspan="3" style="text-align: center;">لا توجد بيانات لهذا الشهر.</td></tr>
                            <?php else: ?>
                                <?php foreach ($report_monthly as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                                        <td><?php echo translate_category($item['category']); ?></td>
                                        <td><strong><?php echo $item['total']; ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </main>
    </div>
</body>
</html>