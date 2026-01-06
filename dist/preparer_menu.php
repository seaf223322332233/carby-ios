<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Content-Type: text/html; charset=utf-8");
require_once 'auth_preparer.php'; 
require_once 'db_connect.php'; 

$today = date('Y-m-d');

// تسليم للسائق
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_to_driver'])) {
    if (!empty($_POST['print_data'])) {
        foreach ($_POST['print_data'] as $json) {
            $data = json_decode($json, true);
            // $data['ids'] هنا تحتوي على ID الطلب (individual_orders.id)
            $id = intval($data['ids'][0]); 
            $pdo->exec("UPDATE individual_orders SET status = 'out_for_delivery' WHERE id = $id");
        }
        $msg = "تم تسليم طلبات المنيو للسائق.";
    }
}

// جلب طلبات المنيو الجاهزة
$menu_orders = [];
try {
    $sql = "SELECT io.id, io.customer_name, io.customer_phone, 
            GROUP_CONCAT(CONCAT(p.name, ' (x', i.quantity, ')') SEPARATOR ' + ') as meals_str
            FROM individual_orders io
            JOIN individual_order_items i ON io.id = i.order_id
            JOIN products p ON i.meal_id = p.id
            WHERE DATE(io.created_at) = ? AND io.status = 'prepared'
            GROUP BY io.id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$today]);
    
    foreach ($stmt->fetchAll() as $r) {
        $menu_orders[] = [
            'type' => 'menu', // نوع الطلب
            'client' => $r['customer_name'],
            'phone' => $r['customer_phone'],
            'address' => 'طلب خارجي (توصيل)', // أو العنوان إن وجد
            'info' => 'طلب #' . $r['id'],
            'meals' => [$r['meals_str']],
            'ids' => [$r['id']]
        ];
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تغليف المنيو</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script>
        function submitAction(url) {
            const form = document.getElementById('menuPrepForm');
            if (document.querySelectorAll('.row-check:checked').length === 0) {
                alert('اختر طلباً واحداً على الأقل.'); return;
            }
            form.target = (url) ? '_blank' : '_self';
            form.action = (url) ? url : '';
            form.submit();
            if(url) { setTimeout(() => { location.reload(); }, 1000); }
        }
    </script>
</head>
<body>
    <?php include 'preparer_sidebar.php'; ?>

    <div class="main-content">
        <header class="top-bar admin-top-bar">
            <div class="user-info" style="color:#9b59b6;">🍔 تغليف المنيو</div>
            <div><?php echo $today; ?></div>
        </header>

        <div class="content-wrapper">
            <?php if(isset($msg)): ?><div class="alert-message alert-success"><?php echo $msg; ?></div><?php endif; ?>

            <form method="POST" id="menuPrepForm">
                <div class="form-card print-hide" style="display:flex; gap:10px; align-items:center; background:#f3e5f5;">
                    <div style="flex:1;">
                        <input type="checkbox" id="selectAllMenu" onchange="document.querySelectorAll('.row-check').forEach(c=>c.checked=this.checked)">
                        <label for="selectAllMenu" style="font-weight:bold; cursor:pointer; color:#8e44ad;">تحديد الكل</label>
                    </div>
                    <button type="button" onclick="submitAction('print_labels.php')" class="btn" style="background:#8e44ad;"><i class="fas fa-tags"></i> ملصقات</button>
                    <button type="button" onclick="submitAction('print_manifest.php')" class="btn" style="background:#2c3e50;"><i class="fas fa-file-alt"></i> كشف</button>
                    <button type="submit" name="send_to_driver" class="btn btn-success"><i class="fas fa-truck"></i> تسليم</button>
                </div>

                <div class="orders-list">
                    <?php if(empty($menu_orders)): ?>
                        <div class="card" style="text-align:center; padding:40px;">لا توجد طلبات منيو جاهزة.</div>
                    <?php else: foreach($menu_orders as $o): 
                        $json_val = htmlspecialchars(json_encode($o));
                    ?>
                    <div class="card" style="display:flex; align-items:center; gap:15px; padding:15px; border-left:5px solid #9b59b6;">
                        <input type="checkbox" name="print_data[]" value="<?php echo $json_val; ?>" class="row-check" style="width:20px; height:20px;">
                        <div style="flex:1;">
                            <h4 style="margin:0;"><?php echo $o['client']; ?> <span style="font-size:0.8rem; color:#8e44ad;">(#<?php echo $o['ids'][0]; ?>)</span></h4>
                            <div style="color:#333; font-weight:bold; margin-top:5px;"><?php echo implode('', $o['meals']); ?></div>
                            <div style="font-size:0.85rem; color:#777; margin-top:5px;">📱 <?php echo $o['phone']; ?></div>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </form>
        </div>
    </div>
</body>
</html>