<?php
// يجب استدعاء lang_handler.php قبل أي header() أو output
require_once 'lang_handler.php';

// إجبار المتصفح على استخدام ترميز UTF-8
header('Content-Type: text/html; charset=utf-8');

require_once 'auth_admin.php'; 
require_once 'db_connect.php'; 

// --- جلب الإحصائيات (للنظام الجديد: الطلبات الفردية) ---
try {
    $today_date = date('Y-m-d');
    
    // 1. مبيعات اليوم (المالية)
    $stmt = $pdo->prepare("SELECT SUM(total_price) FROM individual_orders WHERE order_date = ? AND status != 'cancelled'");
    $stmt->execute([$today_date]);
    $daily_sales = $stmt->fetchColumn() ?: 0;

    // 2. عدد طلبات اليوم
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM individual_orders WHERE order_date = ?");
    $stmt->execute([$today_date]);
    $daily_orders_count = $stmt->fetchColumn();

    // 3. عدد العملاء المسجلين
    $total_clients = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'client'")->fetchColumn();

    // 4. عدد الوجبات في المنيو
    $total_meals = $pdo->query("SELECT COUNT(*) FROM meals")->fetchColumn();

    // 5. جلب "آخر 10 طلبات" (للمتابعة الحية)
    $sql_recent = "SELECT * FROM individual_orders ORDER BY created_at DESC LIMIT 10";
    $recent_orders = $pdo->query($sql_recent)->fetchAll();

} catch (PDOException $e) {
    die("خطأ في جلب البيانات: " . $e->getMessage());
}

// دالة مساعدة لحالة الطلب
function get_status_badge($status) {
    switch ($status) {
        case 'pending': return '<span style="color:#f39c12; font-weight:bold;">قيد الانتظار ⏳</span>';
        case 'prepared': return '<span style="color:#3498db; font-weight:bold;">تم التجهيز 👨‍🍳</span>';
        case 'out_for_delivery': return '<span style="color:#9b59b6; font-weight:bold;">جاري التوصيل 🚗</span>';
        case 'delivered': return '<span style="color:#27ae60; font-weight:bold;">تم التسليم ✅</span>';
        case 'cancelled': return '<span style="color:#e74c3c; font-weight:bold;">ملغي ❌</span>';
        default: return $status;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم - الرئيسية</title>
    <link rel="stylesheet" href="admin_colors.php">
    <link rel="stylesheet" href="admin-unified-style-v2.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
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
            <div class="user-info">مرحباً، المدير</div>
            <div style="display:flex; gap:10px; align-items:center;">
                <?php echo langSwitcher(); ?>
                <a href="logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> خروج</a>
            </div>
        </header>

        <main class="content-wrapper">
            
            <div class="admin-header">
                <h1><i class="fas fa-chart-line"></i> لوحة التحكم الرئيسية</h1>
                <div style="display:flex; align-items:center; gap:12px;">
                    <span style="color:var(--admin-text-secondary); font-weight:700; font-size:0.95rem;">
                        <i class="fas fa-calendar-alt"></i> <?php echo $today_date; ?>
                    </span>
                </div>
            </div>

            <div class="dashboard-grid">
                <div class="stat-box box-sales">
                    <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                    <div class="stat-info">
                        <h3>مبيعات اليوم</h3>
                        <div class="number"><?php echo number_format($daily_sales, 2); ?> <small style="font-size:0.7em; -webkit-text-fill-color:var(--admin-text-secondary);">ر.س</small></div>
                    </div>
                </div>
                
                <div class="stat-box box-orders">
                    <div class="stat-icon"><i class="fas fa-shopping-bag"></i></div>
                    <div class="stat-info">
                        <h3>طلبات اليوم</h3>
                        <div class="number"><?php echo $daily_orders_count; ?> <small style="font-size:0.7em; -webkit-text-fill-color:var(--admin-text-secondary);">طلب</small></div>
                    </div>
                </div>
                
                <div class="stat-box box-clients">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-info">
                        <h3>إجمالي العملاء</h3>
                        <div class="number"><?php echo $total_clients; ?> <small style="font-size:0.7em; -webkit-text-fill-color:var(--admin-text-secondary);">عميل</small></div>
                    </div>
                </div>
                
                <div class="stat-box" style="border-color:rgba(139, 92, 246, 0.2); background:linear-gradient(135deg, #ffffff 0%, #faf5ff 100%);">
                    <div class="stat-icon" style="color:#8b5cf6; background:linear-gradient(135deg, rgba(139, 92, 246, 0.2) 0%, rgba(139, 92, 246, 0.05) 100%); box-shadow:0 4px 12px rgba(139, 92, 246, 0.2);">
                        <i class="fas fa-hamburger"></i>
                    </div>
                    <div class="stat-info">
                        <h3>أصناف المنيو</h3>
                        <div class="number"><?php echo $total_meals; ?> <small style="font-size:0.7em; -webkit-text-fill-color:var(--admin-text-secondary);">صنف</small></div>
                    </div>
                </div>
            </div>

            <div class="admin-card">
                <div class="admin-card-header">
                    <h3><i class="fas fa-clock"></i> آخر 10 طلبات واردة</h3>
                    <a href="admin_orders.php" class="admin-btn admin-btn-secondary admin-btn-sm">
                        <i class="fas fa-external-link-alt"></i> عرض الكل
                    </a>
                </div>
                
                <div class="admin-table-wrapper">
                    <table class="admin-table recent-orders-table">
                        <thead>
                            <tr>
                                <th>رقم الطلب</th>
                                <th>العميل</th>
                                <th>المبلغ</th>
                                <th>طريقة الدفع</th>
                                <th>النوع</th>
                                <th>الحالة</th>
                                <th>التواريخ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_orders)): ?>
                                <tr><td colspan="7" style="text-align:center; padding:30px;">لا توجد طلبات حتى الآن.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_orders as $order): ?>
                                    <tr>
                                        <td><strong>#<?php echo $order['id']; ?></strong></td>
                                        <td>
                                            <?php echo htmlspecialchars($order['customer_name']); ?><br>
                                            <small style="color:#888;"><?php echo htmlspecialchars($order['customer_phone']); ?></small>
                                        </td>
                                        <td><strong><?php echo number_format($order['total_price'], 2); ?></strong></td>
                                        <td>
                                            <?php echo ($order['payment_method']=='cod') ? 'عند الاستلام' : $order['payment_method']; ?>
                                        </td>
                                        <td>
                                            <?php if($order['order_type'] == 'delivery'): ?>
                                                <span style="color:#e67e22;"><i class="fas fa-motorcycle"></i> توصيل</span>
                                            <?php else: ?>
                                                <span style="color:#2980b9;"><i class="fas fa-store"></i> استلام</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo get_status_badge($order['status']); ?></td>
                                        <td style="font-size:0.85rem; color:#666;">
                                            <?php echo date('H:i d/m', strtotime($order['created_at'])); ?>
                                        </td>
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