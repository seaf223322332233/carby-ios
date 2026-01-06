<?php
// client_history.php - سجل الطلبات والاشتراكات (التصميم الجديد)
// ==========================================================

header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');

require_once 'auth_client.php'; 
require_once 'db_connect.php'; 

$client_id = $_SESSION['user_id'];
$placeholder = 'uploads/meals/placeholder.png'; 

// 1. جلب سجل وجبات الاشتراك (المنتهية فقط)
$history_logs = [];

// أ) وجبات الاشتراك السابقة
try {
    $sql = "SELECT log.delivery_date as date, log.status, log.category, 
                   p.name as item_name, p.image as item_image, 
                   'subscription' as type
            FROM delivery_log log 
            JOIN products p ON log.meal_id = p.id 
            WHERE log.client_id = ? 
            AND log.status IN ('delivered', 'cancelled', 'prepared') 
            ORDER BY log.delivery_date DESC LIMIT 30";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$client_id]);
    $sub_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $sub_history = []; }

// ب) طلبات المنيو السابقة
try {
    $sql2 = "SELECT created_at as date, status, 
                    CONCAT('طلب منيو #', id) as item_name, 
                    '' as item_image, total_price, 
                    'menu' as type 
             FROM individual_orders 
             WHERE user_id = ? 
             AND status IN ('delivered', 'cancelled') 
             ORDER BY created_at DESC LIMIT 30";
    $stmt2 = $pdo->prepare($sql2);
    $stmt2->execute([$client_id]);
    $menu_history = $stmt2->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $menu_history = []; }

// دمج المصفوفتين وترتيبهما حسب التاريخ
$history_logs = array_merge($sub_history, $menu_history);

// دالة ترتيب مخصصة (الأحدث أولاً)
usort($history_logs, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

// دوال مساعدة للعرض
function getStatusLabel($status) {
    switch($status) {
        case 'delivered': return ['text'=>'تم التوصيل', 'class'=>'status-success'];
        case 'cancelled': return ['text'=>'ملغي', 'class'=>'status-danger'];
        case 'prepared': return ['text'=>'تم التجهيز', 'class'=>'status-info'];
        default: return ['text'=>$status, 'class'=>''];
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>سجل الطلبات</title>
    
    <link rel="stylesheet" href="client_style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        /* تنسيقات خاصة بالسجل */
        .history-card {
            background: #fff; border-radius: 16px; padding: 15px;
            margin-bottom: 15px; display: flex; align-items: center; gap: 15px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.03); border: 1px solid #f9f9f9;
            transition: 0.2s;
        }
        .history-card:active { transform: scale(0.98); }
        
        .h-img { width: 50px; height: 50px; border-radius: 10px; object-fit: cover; background: #f0f0f0; }
        .h-icon-box { width: 50px; height: 50px; border-radius: 10px; background: #e3f2fd; color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        
        .h-info { flex: 1; }
        .h-title { font-weight: bold; font-size: 0.95rem; color: #333; margin-bottom: 3px; }
        .h-date { font-size: 0.75rem; color: #888; display: flex; align-items: center; gap: 5px; }
        
        .h-status { font-size: 0.7rem; padding: 4px 10px; border-radius: 20px; font-weight: bold; }
        .status-success { background: #e8f5e9; color: #2e7d32; }
        .status-danger { background: #ffebee; color: #c62828; }
        .status-info { background: #e3f2fd; color: #1565c0; }

        .type-badge { font-size: 0.65rem; padding: 2px 6px; border-radius: 4px; margin-left: 5px; }
        .type-sub { background: #f3e5f5; color: #8e24aa; }
        .type-menu { background: #fff3e0; color: #ef6c00; }
    </style>
</head>
<body>

    <div class="app-header" style="height: 120px;"></div>
    <div class="top-nav">
        <div class="user-welcome">
            <a href="client_dashboard.php" style="color:white; font-size:1.5rem;"><i class="fas fa-arrow-right"></i></a>
            <h3>السجل والأرشيف 📂</h3>
        </div>
    </div>

    <div class="content-wrapper" style="margin-top: 20px;">
        
        <?php if (empty($history_logs)): ?>
            <div class="empty-state">
                <i class="fas fa-history" style="font-size:4rem; color:#eee; margin-bottom:20px; display:block;"></i>
                <h3 style="color:#999; font-size:1rem;">السجل فارغ</h3>
                <p style="color:#ccc; font-size:0.85rem;">لم تقم بأي طلبات مكتملة حتى الآن.</p>
            </div>
        <?php else: ?>
            
            <div style="padding-bottom: 80px;">
                <?php foreach ($history_logs as $log): 
                    $st = getStatusLabel($log['status']);
                    $date_str = date('Y/m/d', strtotime($log['date']));
                    $time_str = date('h:i A', strtotime($log['date']));
                ?>
                <div class="history-card">
                    <?php if($log['type'] == 'sub'): ?>
                        <?php 
                            $img = !empty($log['item_image']) ? 'uploads/'.$log['item_image'] : $placeholder; 
                        ?>
                        <img src="<?php echo $img; ?>" class="h-img">
                    <?php else: ?>
                        <div class="h-icon-box"><i class="fas fa-shopping-bag"></i></div>
                    <?php endif; ?>

                    <div class="h-info">
                        <div class="h-title">
                            <?php echo htmlspecialchars($log['item_name']); ?>
                            <?php if($log['type'] == 'menu'): ?>
                                <span style="font-size:0.8rem; color:var(--primary);"> (<?php echo $log['total_price']; ?> ر.س)</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="h-date">
                            <?php if($log['type']=='sub'): ?>
                                <span class="type-badge type-sub">اشتراك</span>
                            <?php else: ?>
                                <span class="type-badge type-menu">طلب خارجي</span>
                            <?php endif; ?>
                            <span><i class="far fa-clock"></i> <?php echo $date_str; ?></span>
                        </div>
                    </div>

                    <div class="h-status <?php echo $st['class']; ?>">
                        <?php echo $st['text']; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </div>

   <nav class="bottom-nav">
        <a href="client_dashboard.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'client_dashboard.php' ? 'active' : ''; ?>">
            <i class="fas fa-home"></i> <span>الرئيسية</span>
        </a>
        
        <a href="client_package.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'client_package.php' ? 'active' : ''; ?>">
            <i class="far fa-calendar-alt"></i> <span>باقتي</span>
        </a>
        
        <div style="position:relative;">
            <a href="menu.php" class="nav-fab"><i class="fas fa-plus"></i></a>
        </div>
        
        <a href="my_orders.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'my_orders.php' ? 'active' : ''; ?>">
            <i class="fas fa-shopping-bag"></i> <span>طلباتي</span>
        </a>
        
        <a href="client_profile.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'client_profile.php' ? 'active' : ''; ?>">
            <i class="fas fa-user"></i> <span>حسابي</span>
        </a>
    </nav>

</body>
</html>