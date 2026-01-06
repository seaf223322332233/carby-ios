<?php
header('Content-Type: text/html; charset=utf-8');
require_once 'auth_driver.php'; 
require_once 'db_connect.php'; 

$today_date = date('Y-m-d');

// جلب الطلبات الجاهزة
$orders_ready = [];
try {
    $sql_ready = "SELECT log.client_id, u.name AS client_name, cd.phone_number, cd.Maps_link, cd.address_text
                  FROM delivery_log AS log
                  JOIN users AS u ON log.client_id = u.id
                  JOIN client_details AS cd ON log.client_id = cd.user_id
                  WHERE log.delivery_date = ? AND log.status = 'prepared'
                  GROUP BY log.client_id, u.name, cd.phone_number, cd.Maps_link, cd.address_text
                  ORDER BY u.name";     
    $stmt_ready = $pdo->prepare($sql_ready);
    $stmt_ready->execute([$today_date]);
    $orders_ready = $stmt_ready->fetchAll();
} catch (PDOException $e) { die("خطأ: " . $e->getMessage()); }

// جلب الطلبات قيد التوصيل
$orders_delivering = [];
try {
    $sql_delivering = "SELECT log.client_id, u.name AS client_name, cd.phone_number, cd.Maps_link, cd.address_text
                       FROM delivery_log AS log
                       JOIN users AS u ON log.client_id = u.id
                       JOIN client_details AS cd ON log.client_id = cd.user_id
                       WHERE log.delivery_date = ? AND log.status = 'out_for_delivery' AND log.driver_id = ?
                       GROUP BY log.client_id, u.name, cd.phone_number, cd.Maps_link, cd.address_text
                       ORDER BY u.name";
    $stmt_delivering = $pdo->prepare($sql_delivering);
    $stmt_delivering->execute([$today_date, $user_id_session]);
    $orders_delivering = $stmt_delivering->fetchAll();
} catch (PDOException $e) { die("خطأ: " . $e->getMessage()); }

// دالة لتنظيف رقم الهاتف للواتساب
function format_whatsapp_number($phone) {
    // إزالة أي مسافات أو رموز، وإضافة كود الدولة (مثال للسعودية 966) إذا لم يكن موجوداً
    // يمكنك تعديل هذا حسب دولتك
    $clean_phone = preg_replace('/[^0-9]/', '', $phone);
    if (substr($clean_phone, 0, 1) == '0') {
        $clean_phone = '966' . substr($clean_phone, 1);
    }
    return $clean_phone;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة السائق</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .driver-sidebar { background-color: #117a8b; }
        .driver-sidebar-header { border-bottom: 1px solid #3eb8d0; }
        .delivery-card { border: 1px solid #ddd; border-radius: 8px; margin-bottom: 15px; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .delivery-card-header { padding: 12px 15px; background: #f8f9fa; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        .delivery-card-body { padding: 15px; }
        
        .info-row { margin-bottom: 10px; display: flex; align-items: center; gap: 10px; font-size: 1rem; }
        .info-row i { width: 20px; text-align: center; color: #666; }
        
        .address-box { background: #fffbe6; padding: 10px; border: 1px solid #ffe58f; border-radius: 5px; font-size: 0.9rem; margin-bottom: 10px; color: #856404; }
        
        .btn-action { display: block; width: 100%; padding: 10px; text-align: center; border-radius: 5px; color: white; font-weight: bold; margin-bottom: 5px; }
        .btn-whatsapp { background-color: #25D366; }
        .btn-near { background-color: #17a2b8; }
        .btn-map { background-color: #ffc107; color: #333; }
        .btn-pickup { background-color: #007bff; }
        .btn-delivered { background-color: #28a745; }
        
        .action-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 5px; }
        .full-width { grid-column: span 2; }
    </style>
</head>
<body>
    <div class="sidebar driver-sidebar">
        <div class="sidebar-header driver-sidebar-header"><h3><i class="fas fa-truck"></i> واجهة السائق</h3></div>
        <nav class="sidebar-nav">
            <a href="driver_dashboard.php" class="active"><i class="fas fa-list-alt"></i> طلبات التوصيل</a>
            <a href="driver_archive.php"><i class="fas fa-history"></i> سجل توصيلاتي</a>
        </nav>
    </div>

    <div class="main-content">
        <header class="top-bar"><div class="user-info">مرحباً، <?php echo htmlspecialchars($user_name_session); ?></div><a href="logout.php" style="color:red;">خروج</a></header>
        <main class="content-wrapper">
            
            <?php if(isset($_GET['success'])): ?>
                <div style="background:#d4edda; color:#155724; padding:10px; border-radius:5px; margin-bottom:15px; text-align:center;">
                    <?php if($_GET['success'] == 'near') echo 'تم إرسال إشعار "أنا قريب" للعميل.'; else echo 'تم تحديث الحالة بنجاح!'; ?>
                </div>
            <?php endif; ?>

            <div class="form-card" style="border-top: 4px solid #28a745; margin-bottom: 20px;">
                <h2><i class="fas fa-shipping-fast"></i> قيد التوصيل (<?php echo count($orders_delivering); ?>)</h2>
                <?php if (empty($orders_delivering)): ?><p style="text-align:center; color:#777;">لا توجد طلبات معك.</p><?php else: ?>
                    <?php foreach ($orders_delivering as $order): 
                        $wa_link = "https://wa.me/" . format_whatsapp_number($order['phone_number']);
                    ?>
                        <div class="delivery-card">
                            <div class="delivery-card-header">
                                <strong><?php echo htmlspecialchars($order['client_name']); ?></strong>
                            </div>
                            <div class="delivery-card-body">
                                <div class="info-row">
                                    <i class="fas fa-phone"></i> 
                                    <a href="tel:<?php echo $order['phone_number']; ?>"><?php echo $order['phone_number']; ?></a>
                                </div>
                                
                                <?php if(!empty($order['address_text'])): ?>
                                    <div class="address-box">
                                        <i class="fas fa-pen"></i> <?php echo htmlspecialchars($order['address_text']); ?>
                                    </div>
                                <?php endif; ?>

                                <div class="action-grid">
                                    <a href="<?php echo $wa_link; ?>" target="_blank" class="btn-action btn-whatsapp">
                                        <i class="fab fa-whatsapp"></i> واتساب
                                    </a>
                                    
                                    <?php if(!empty($order['Maps_link'])): ?>
                                        <a href="<?php echo htmlspecialchars($order['Maps_link']); ?>" target="_blank" class="btn-action btn-map">
                                            <i class="fas fa-map-marker-alt"></i> الخريطة
                                        </a>
                                    <?php endif; ?>
                                    
                                    <a href="handle_driver_action.php?action=notify_near&client_id=<?php echo $order['client_id']; ?>&phone=<?php echo format_whatsapp_number($order['phone_number']); ?>" class="btn-action btn-near full-width">
                                        <i class="fas fa-bell"></i> إرسال "أنا قريب منك"
                                    </a>

                                    <a href="handle_driver_action.php?action=deliver&client_id=<?php echo $order['client_id']; ?>" class="btn-action btn-delivered full-width" onclick="return confirm('تأكيد التسليم؟');">
                                        <i class="fas fa-check"></i> تم التسليم
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="form-card" style="border-top: 4px solid #007bff;">
                <h2><i class="fas fa-box"></i> جاهز للاستلام (<?php echo count($orders_ready); ?>)</h2>
                <?php if (empty($orders_ready)): ?><p style="text-align:center; color:#777;">لا توجد طلبات جاهزة.</p><?php else: ?>
                    <?php foreach ($orders_ready as $order): ?>
                        <div class="delivery-card">
                            <div class="delivery-card-header">
                                <strong><?php echo htmlspecialchars($order['client_name']); ?></strong>
                            </div>
                            <div class="delivery-card-body">
                                <?php if(!empty($order['address_text'])): ?>
                                    <div class="address-box"><?php echo htmlspecialchars($order['address_text']); ?></div>
                                <?php endif; ?>
                                <a href="handle_driver_action.php?action=pickup&client_id=<?php echo $order['client_id']; ?>" class="btn-action btn-pickup">
                                    <i class="fas fa-hand-holding"></i> استلام الطلب
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
        </main>
    </div>
</body>
</html> 