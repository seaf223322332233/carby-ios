<?php
// admin_order_details.php - تفاصيل الطلب والفاتورة (مع معالجة العنوان)
// ===================================================================

session_start();
require_once 'db_connect.php';

if (!isset($_GET['id'])) { header("Location: admin_orders.php"); exit; }
$oid = $_GET['id'];

// تحديث الحالة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_status'])) {
    $stmt = $pdo->prepare("UPDATE individual_orders SET status = ? WHERE id = ?");
    $stmt->execute([$_POST['new_status'], $oid]);
    header("Location: admin_order_details.php?id=$oid"); exit;
}

// جلب البيانات
$stmt = $pdo->prepare("SELECT * FROM individual_orders WHERE id = ?");
$stmt->execute([$oid]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) die("الطلب غير موجود");

// جلب العناصر
$stmt_items = $pdo->prepare("SELECT i.*, p.name FROM individual_order_items i LEFT JOIN products p ON i.meal_id = p.id WHERE i.order_id = ?");
$stmt_items->execute([$oid]);
$items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

// --- معالجة العنوان (فصل الرابط عن النص) ---
$full_address = $order['address'];
$map_link = '';
$address_text = $full_address;

// استخراج الرابط باستخدام Regex
if (preg_match('/https?:\/\/[^\s]+/', $full_address, $matches)) {
    $map_link = $matches[0];
    // إزالة الرابط من النص الأصلي ليبقى العنوان النصي فقط
    $address_text = str_replace($map_link, '', $full_address);
}
// تنظيف النص
$address_text = trim(str_replace(['موقع:', 'تفاصيل:', 'رابط:'], '', $address_text));
if(empty($address_text)) $address_text = "حسب الموقع المرفق";
// ------------------------------------------

$statuses = ['pending'=>'بانتظار المراجعة', 'prepared'=>'قيد التجهيز', 'out_for_delivery'=>'جاري التوصيل', 'delivered'=>'تم التسليم', 'cancelled'=>'ملغي'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>طلب #<?php echo $oid; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin-unified-style.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #6c5ce7; --bg: #f3f4f6; }
        body { background: var(--bg); font-family: 'Tajawal', sans-serif; margin: 0; padding: 0; display: flex; min-height: 100vh; }
        
        /* Layout */
        .sidebar-wrapper { width: 260px; background: #fff; border-left: 1px solid #ddd; flex-shrink: 0; }
        .main-content { flex-grow: 1; padding: 30px; overflow-y: auto; }
        
        .container { max-width:150%px; margin: 0 auto; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        
        /* Header */
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-bottom: 2px solid #f3f4f6; padding-bottom: 20px; }
        .order-title { font-size: 1.5rem; font-weight: 800; color: #2d3436; }
        .actions-bar { display: flex; gap: 10px; }
        .btn { padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-family: inherit; font-weight: bold; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-size: 0.9rem; }
        .btn-print { background: #e2e8f0; color: #2d3436; }
        .btn-save { background: var(--primary); color: white; }
        .btn-map { background: #e0f2fe; color: #0284c7; }
        
        .section-box { margin-bottom: 30px; }
        .section-title { font-size: 1.1rem; font-weight: bold; margin-bottom: 15px; color: var(--primary); display: flex; align-items: center; gap: 10px; }
        
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .info-item label { display: block; color: #a0aec0; font-size: 0.85rem; margin-bottom: 5px; }
        .info-item div { font-weight: 600; color: #2d3436; font-size: 1rem; }
        
        .items-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .items-table th { text-align: right; color: #a0aec0; padding: 10px; border-bottom: 1px solid #eee; font-size: 0.9rem; }
        .items-table td { padding: 15px 10px; border-bottom: 1px solid #f9f9f9; font-weight: 600; }
        
        .total-box { background: #f8fafc; padding: 20px; border-radius: 10px; margin-top: 20px; }
        .total-row { display: flex; justify-content: space-between; margin-bottom: 10px; }
        .final-total { font-size: 1.3rem; font-weight: 800; color: var(--primary); border-top: 2px dashed #cbd5e0; padding-top: 10px; margin-top: 10px; }
        
        .status-form { background: #fffbe6; border: 1px solid #fef3c7; padding: 15px; border-radius: 8px; display: flex; gap: 10px; align-items: center; margin-bottom: 20px; }
        .form-select { padding: 8px; border-radius: 6px; border: 1px solid #d1d5db; font-family: inherit; flex: 1; }

        @media print {
            body { display: block; background: white; }
            .sidebar-wrapper, .actions-bar, .status-form { display: none !important; }
            .main-content { padding: 0; }
            .container { box-shadow: none; padding: 0; max-width: 100%; }
        }
    </style>
</head>
<body>

    <div class="sidebar-wrapper">
        <?php include 'sidebar.php'; ?>
    </div>

    <div class="main-content">
        <div class="container">
            
            <div class="page-header">
                <div>
                    <div class="order-title">طلب #<?php echo $order['id']; ?></div>
                    <div style="color:#888;"><?php echo $order['created_at']; ?></div>
                </div>
                <div class="actions-bar">
                    <button onclick="window.print()" class="btn btn-print"><i class="fas fa-print"></i> طباعة</button>
                    <a href="admin_orders.php" class="btn" style="background:#edf2f7; color:#333;">عودة</a>
                </div>
            </div>

            <div class="status-form">
                <label><strong>حالة الطلب:</strong></label>
                <form method="POST" style="display:flex; gap:10px; flex:1;">
                    <select name="new_status" class="form-select">
                        <?php foreach($statuses as $key => $val): ?>
                            <option value="<?php echo $key; ?>" <?php echo $order['status']==$key?'selected':''; ?>><?php echo $val; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-save">حفظ</button>
                </form>
            </div>

            <div class="section-box">
                <div class="section-title"><i class="fas fa-user-circle"></i> بيانات العميل</div>
                <div class="info-grid">
                    <div class="info-item">
                        <label>الاسم الكريم</label>
                        <div><?php echo htmlspecialchars($order['customer_name']); ?></div>
                    </div>
                    <div class="info-item">
                        <label>رقم الجوال</label>
                        <div><?php echo $order['customer_phone']; ?></div>
                    </div>
                    
                    <div class="info-item" style="grid-column: 1 / -1;">
                        <label>العنوان / طريقة الاستلام</label>
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span><?php echo nl2br(htmlspecialchars($address_text)); ?></span>
                            
                            <?php if(!empty($map_link)): ?>
                                <a href="<?php echo $map_link; ?>" target="_blank" class="btn btn-map" style="padding: 5px 15px; font-size: 0.8rem;">
                                    <i class="fas fa-map-marker-alt"></i> فتح الموقع
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="section-box">
                <div class="section-title"><i class="fas fa-shopping-basket"></i> محتوى الطلب</div>
                <table class="items-table">
                    <thead><tr><th>المنتج</th><th>الكمية</th><th>السعر</th><th>المجموع</th></tr></thead>
                    <tbody>
                        <?php foreach($items as $item): 
                            $opts_display = '';
                            if(!empty($item['options_json'])) {
                                $dec = json_decode($item['options_json'], true);
                                if(isset($dec['size_name'])) $opts_display .= $dec['size_name'] . ' ';
                                if(isset($dec['options'])) {
                                    $names = array_column($dec['options'], 'name');
                                    $opts_display .= (!empty($names) ? '(+' . implode(', ', $names) . ')' : '');
                                }
                            }
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['name'] ?? $item['meal_name']); ?> <small style="color:#888; display:block;"><?php echo $opts_display; ?></small></td>
                            <td>x<?php echo $item['quantity']; ?></td>
                            <td><?php echo $item['price']; ?></td>
                            <td><?php echo number_format($item['price'] * $item['quantity'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="total-box">
                <div class="total-row"><span>طريقة الدفع</span><strong><?php echo $order['payment_method']; ?></strong></div>
                <div class="total-row final-total"><span>الإجمالي</span><span><?php echo number_format($order['total_price'], 2); ?> ر.س</span></div>
            </div>

        </div>
    </div>

</body>
</html>