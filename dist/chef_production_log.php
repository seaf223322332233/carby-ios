<?php
header('Content-Type: text/html; charset=utf-8');
require_once 'auth_chef.php'; 
require_once 'db_connect.php'; 

// الفلاتر (افتراضياً: اليوم)
$filter_date = $_GET['date'] ?? date('Y-m-d');

// جلب ما تم طبخه (Status = prepared OR out_for_delivery OR delivered)
$cooked_log = [];
try {
    $sql_log = "
        SELECT 'اشتراك' as type, u.name as client, m.name as meal, m.protein_weight, m.protein_type, log.delivery_date as date_time
        FROM delivery_log log JOIN users u ON log.client_id=u.id JOIN meals m ON log.meal_id=m.id
        WHERE log.delivery_date = ? AND log.status != 'pending' AND log.status != 'cancelled'
        
        UNION ALL
        
        SELECT 'منيو' as type, ord.customer_name as client, m.name as meal, item.selected_weight as protein_weight, m.protein_type, ord.created_at as date_time
        FROM individual_orders ord JOIN individual_order_items item ON ord.id=item.order_id JOIN meals m ON item.meal_id=m.id
        WHERE DATE(ord.order_date) = ? AND ord.status != 'pending' AND ord.status != 'cancelled'
        
        ORDER BY date_time DESC
    ";
    $stmt = $pdo->prepare($sql_log);
    $stmt->execute([$filter_date, $filter_date]);
    $cooked_log = $stmt->fetchAll();

    // حساب إجمالي ما تم طبخه في هذا اليوم
    $total_cooked_weight = 0;
    foreach ($cooked_log as $row) {
        $total_cooked_weight += $row['protein_weight'];
    }

} catch (Exception $e) { }

function translate_protein($type) {
    $map = ['dajaj' => 'دجاج', 'lahm' => 'لحم', 'samak' => 'سمك'];
    return $map[$type] ?? $type;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>سجل الإنتاج</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .chef-sidebar { background-color: #d35400; }
        .log-table { width: 100%; border-collapse: collapse; background: #fff; }
        .log-table th, .log-table td { padding: 12px; border-bottom: 1px solid #eee; text-align: right; }
        .log-table th { background: #f9f9f9; }
        .summary-bar { background: #34495e; color: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        @media print { .sidebar, .top-bar, form { display: none !important; } .main-content { margin: 0; } }
    </style>
</head>
<body>

    <div class="sidebar chef-sidebar">
        <div class="sidebar-header"><h3><i class="fas fa-utensils"></i> المطبخ</h3></div>
        <nav class="sidebar-nav">
            <a href="chef_dashboard.php"><i class="fas fa-fire"></i> المهام الحالية (To Do)</a>
            <a href="chef_production_log.php" class="active"><i class="fas fa-clipboard-check"></i> سجل الإنتاج</a>
        </nav>
    </div>

    <div class="main-content">
        <header class="top-bar">
            <div class="user-info">سجل الإنتاج</div>
            <a href="logout.php" class="logout-link">خروج</a>
        </header>

        <main class="content-wrapper">
            
            <form method="GET" style="margin-bottom: 20px; display:flex; gap:10px; align-items:center;">
                <label>عرض سجل يوم:</label>
                <input type="date" name="date" value="<?php echo $filter_date; ?>" style="padding:8px; border:1px solid #ccc; border-radius:5px;">
                <button type="submit" class="btn" style="padding:8px 15px;">عرض</button>
                <button type="button" onclick="window.print()" class="btn" style="background:#555; margin-right:auto;"><i class="fas fa-print"></i> طباعة السجل</button>
            </form>

            <div class="summary-bar">
                <span><i class="fas fa-calendar-day"></i> التاريخ: <?php echo $filter_date; ?></span>
                <span><i class="fas fa-weight-hanging"></i> إجمالي ما تم طبخه: <strong><?php echo number_format($total_cooked_weight / 1000, 2); ?> كيلو</strong></span>
                <span><i class="fas fa-check"></i> العدد: <?php echo count($cooked_log); ?> وجبة</span>
            </div>

            <div class="form-card" style="padding:0; overflow:hidden;">
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>النوع</th>
                            <th>العميل</th>
                            <th>الوجبة</th>
                            <th>الوزن / الصنف</th>
                            <th>الوقت</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cooked_log)): ?>
                            <tr><td colspan="5" style="text-align:center; padding:20px;">لا يوجد إنتاج مسجل لهذا اليوم.</td></tr>
                        <?php else: ?>
                            <?php foreach ($cooked_log as $row): ?>
                                <tr>
                                    <td><?php echo $row['type']; ?></td>
                                    <td><?php echo htmlspecialchars($row['client']); ?></td>
                                    <td><?php echo htmlspecialchars($row['meal']); ?></td>
                                    <td>
                                        <strong><?php echo $row['protein_weight']; ?>g</strong> 
                                        <?php echo translate_protein($row['protein_type']); ?>
                                    </td>
                                    <td style="direction:ltr; text-align:right;">
                                        <?php echo date('h:i A', strtotime($row['date_time'])); ?>
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