<?php
// cart.php - سلة المشتريات (تصميم مطابق للهوية + نافذة حذف احترافية + إصلاح الصور)
// ==============================================================

header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');
session_start();

// --- معالج التعديلات ---
if (isset($_GET['action']) && isset($_GET['key'])) {
    $key = $_GET['key'];
    
    if (isset($_SESSION['cart'][$key])) {
        // 1. الوجبة
        if ($_GET['action'] == 'remove') { unset($_SESSION['cart'][$key]); }
        elseif ($_GET['action'] == 'plus') { $_SESSION['cart'][$key]['qty']++; }
        elseif ($_GET['action'] == 'minus') {
            if ($_SESSION['cart'][$key]['qty'] > 1) $_SESSION['cart'][$key]['qty']--;
            else unset($_SESSION['cart'][$key]);
        }

        // 2. الخيارات
        if (isset($_GET['opt_idx'])) {
            $optKey = $_GET['opt_idx'];
            if (isset($_SESSION['cart'][$key]['options'][$optKey])) {
                $option = &$_SESSION['cart'][$key]['options'][$optKey];
                
                if ($_GET['action'] == 'opt_plus') {
                    $option['qty'] = ($option['qty'] ?? 1) + 1;
                } elseif ($_GET['action'] == 'opt_minus') {
                    $currentQty = isset($option['qty']) ? $option['qty'] : 1;
                    if ($currentQty > 1) $option['qty'] = $currentQty - 1;
                    else {
                        unset($_SESSION['cart'][$key]['options'][$optKey]);
                        $_SESSION['cart'][$key]['options'] = array_values($_SESSION['cart'][$key]['options']);
                    }
                } elseif ($_GET['action'] == 'opt_remove') {
                    unset($_SESSION['cart'][$key]['options'][$optKey]);
                    $_SESSION['cart'][$key]['options'] = array_values($_SESSION['cart'][$key]['options']);
                }
                
                // إعادة حساب السعر
                $base_price = (float)$_SESSION['cart'][$key]['base_price'];
                $options_total = 0;
                if (!empty($_SESSION['cart'][$key]['options'])) {
                    foreach ($_SESSION['cart'][$key]['options'] as $opt) {
                        $q = isset($opt['qty']) ? (int)$opt['qty'] : 1;
                        $options_total += ((float)$opt['price'] * $q);
                    }
                }
                $_SESSION['cart'][$key]['unit_price'] = $base_price + $options_total;
                $_SESSION['cart'][$key]['total_price'] = $_SESSION['cart'][$key]['unit_price'] * $_SESSION['cart'][$key]['qty'];
            }
        }
    }
    header("Location: cart.php");
    exit;
}

// حساب الإجمالي
$cart = $_SESSION['cart'] ?? [];
$grand_total = 0;
foreach($cart as $item) {
    $grand_total += ($item['unit_price'] * $item['qty']); 
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>سلة المشتريات</title>
    
    <link rel="stylesheet" href="client_style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <style>
        :root { --primary: #6c5ce7; --text-dark: #2d3436; --bg-light: #f9f9f9; }
        
        body { 
            background-color: var(--bg-light); 
            padding-top: 80px; 
            padding-bottom: 200px; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
        }

        /* الهيدر */
        .top-nav {
            position: fixed; top: 0; left: 0; width: 100%; z-index: 1000;
            background: linear-gradient(135deg, var(--primary), #8e44ad);
            height: 70px; display: flex; align-items: center; padding: 0 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1); color: white;
        }

        /* كارت المنتج */
        .cart-item {
            background: #fff; border-radius: 16px; padding: 15px; margin: 15px auto;
            display: flex; align-items: flex-start; gap: 15px;
            border: 1px solid #eee; box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            position: relative; overflow: hidden; max-width: 600px;
        }

        .cart-img { 
            width: 90px; height: 90px; border-radius: 12px; object-fit: cover; 
            background: #f0f0f0; border: 1px solid #f1f1f1; flex-shrink: 0;
        }
        
        .item-details { flex: 1; width: 100%; min-width: 0; }
        
        .item-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 5px; }
        .item-name { font-weight: 800; font-size: 1rem; color: #333; line-height: 1.3; }
        .item-size { 
            font-size: 0.75rem; color: var(--primary); background: #f0f0ff; 
            padding: 2px 8px; border-radius: 6px; font-weight: bold; white-space: nowrap;
            margin-top: 4px; display: inline-block;
        }

        /* قسم الخيارات */
        .options-box {
            background: #fdfdfd; border: 1px dashed #e0e0e0; border-radius: 8px;
            padding: 8px; margin: 8px 0; font-size: 0.85rem;
        }
        .opt-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 6px 0; border-bottom: 1px solid #f5f5f5;
        }
        .opt-row:last-child { border-bottom: none; }
        
        .opt-info { display: flex; align-items: center; gap: 5px; color: #555; flex: 1; }
        .opt-price-tag { color: #888; font-size: 0.75rem; }

        .opt-actions { display: flex; align-items: center; gap: 8px; margin-right: 10px; }
        .circle-btn {
            width: 24px; height: 24px; border-radius: 50%; border: none;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 0.75rem; cursor: pointer; text-decoration: none;
            transition: transform 0.2s;
        }
        .circle-btn:active { transform: scale(0.9); }
        .bg-green { background: #2ecc71; }
        .bg-orange { background: #f39c12; }
        .bg-red { background: #e74c3c; }

        /* التحكم بالكمية الرئيسية */
        .main-qty-control {
            display: flex; align-items: center; background: #f8f9fa;
            border-radius: 12px; padding: 4px; border: 1px solid #eee; 
        }
        .mq-btn {
            width: 32px; height: 32px; background: white; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            color: var(--primary); font-weight: bold; text-decoration: none;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .mq-val { width: 30px; text-align: center; font-weight: bold; font-size: 1rem; }

        /* شريط الدفع السفلي */
        .checkout-wrapper {
            position: fixed; bottom: 70px; left: 0; width: 100%; 
            z-index: 900; padding: 0 15px 15px 15px; box-sizing: border-box;
            background: linear-gradient(to top, rgba(249,249,249,1) 80%, rgba(249,249,249,0));
            pointer-events: none;
        }

        .checkout-card {
            background: white; padding: 15px 20px; 
            box-shadow: 0 5px 25px rgba(0,0,0,0.1); border-radius: 16px; 
            display: flex; justify-content: space-between; align-items: center;
            border: 1px solid #eee; pointer-events: auto;
            max-width: 600px; margin: 0 auto;
        }

        .checkout-info { display: flex; flex-direction: column; }
        .checkout-label { font-size: 0.85rem; color: #888; }
        .checkout-total { font-size: 1.3rem; font-weight: 800; color: var(--primary); }
        
        .btn-checkout {
            display: flex; align-items: center; gap: 10px;
            background: var(--primary); color: white; padding: 12px 25px;
            border-radius: 12px; text-decoration: none; font-weight: bold; font-size: 1rem;
            box-shadow: 0 4px 15px rgba(108, 92, 231, 0.4); transition: transform 0.2s;
        }
        .btn-checkout:active { transform: scale(0.96); }

        .trash-main {
            color: #e74c3c; font-size: 1.2rem; cursor: pointer;
            background: #fff5f5; width: 35px; height: 35px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            border: 1px solid #ffecec; transition: 0.2s;
        }
        .trash-main:active { background: #e74c3c; color: white; }

        /* === نافذة الحذف === */
        .confirm-modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.6); z-index: 5000;
            display: none; align-items: center; justify-content: center;
            backdrop-filter: blur(4px);
        }
        .confirm-box {
            background: white; width: 85%; max-width: 320px;
            border-radius: 20px; padding: 25px 20px;
            text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            animation: popUp 0.3s cubic-bezier(0.18, 0.89, 0.32, 1.28);
        }
        @keyframes popUp { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        
        .confirm-icon {
            font-size: 3rem; color: #ff7675; margin-bottom: 15px;
            background: #fff0f0; width: 80px; height: 80px;
            border-radius: 50%; display: inline-flex;
            align-items: center; justify-content: center;
        }
        .confirm-title { margin: 0 0 10px; color: #333; font-size: 1.2rem; }
        .confirm-text { color: #777; margin: 0 0 25px; font-size: 0.95rem; line-height: 1.5; }
        
        .confirm-actions { display: flex; gap: 10px; justify-content: center; }
        .btn-confirm {
            flex: 1; padding: 12px; border-radius: 12px; font-weight: bold; border: none; cursor: pointer; font-size: 1rem;
        }
        .btn-yes { background: #ff7675; color: white; box-shadow: 0 4px 10px rgba(255, 118, 117, 0.3); }
        .btn-no { background: #f1f2f6; color: #555; }
    </style>
</head>
<body>

    <div class="top-nav">
        <a href="menu.php" style="color:white; font-size:1.4rem; padding:10px;"><i class="fas fa-arrow-right"></i></a>
        <h3 style="margin:0 10px; font-weight:800;">سلة المشتريات 🛒</h3>
    </div>

    <?php if(empty($cart)): ?>
        <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:70vh; color:#bbb;">
            <i class="fas fa-shopping-basket" style="font-size:5rem; margin-bottom:20px; color:#eee;"></i>
            <h3 style="color:#555;">السلة فارغة</h3>
            <p>ابدأ بإضافة وجباتك المفضلة الآن!</p>
            <a href="menu.php" style="margin-top:20px; color:var(--primary); font-weight:bold; text-decoration:none; border:2px solid var(--primary); padding:10px 30px; border-radius:50px;">الذهاب للمنيو</a>
        </div>
    <?php else: ?>
        
        <div style="padding-bottom: 20px;">
            <?php foreach($cart as $key => $item): 
                // --- إصلاح الصور (Image Fix) ---
                // نعتمد على الرابط المخزن في الجلسة، إذا كان فارغاً نستخدم صورة بديلة
                $img = !empty($item['image']) ? $item['image'] : 'https://via.placeholder.com/150?text=No+Image';
                
                // في حالة الصور المحلية (التي لا تبدأ بـ http)، نتأكد أنها تشير للمسار الصحيح إذا لم تكن كذلك
                // ملاحظة: الكود السابق في cart_action.php يحفظ الاسم فقط للصور المحلية، لذا قد نحتاج لإضافة 'uploads/' للعرض
                if (strpos($img, 'http') === false && strpos($img, 'uploads/') === false) {
                    $img = 'uploads/' . $img;
                }
            ?>
            <div class="cart-item">
                <img src="<?php echo htmlspecialchars($img); ?>" 
                     class="cart-img" 
                     onerror="this.onerror=null;this.src='https://via.placeholder.com/150?text=Error';">
                
                <div class="item-details">
                    <div class="item-header">
                        <div>
                            <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                            <div class="item-size"><?php echo htmlspecialchars($item['size_name'] ?? 'عادي'); ?></div>
                        </div>
                        <div style="font-weight:bold; color:var(--primary); font-size:1.1rem;">
                            <?php echo number_format($item['unit_price'], 2); ?>
                        </div>
                    </div>

                    <?php if(!empty($item['options'])): ?>
                    <div class="options-box">
                        <?php foreach($item['options'] as $optIdx => $opt): 
                            $optQty = isset($opt['qty']) ? (int)$opt['qty'] : 1;
                        ?>
                        <div class="opt-row">
                            <div class="opt-info">
                                <span>+ <?php echo htmlspecialchars($opt['name']); ?></span>
                                <?php if($optQty > 1): ?>
                                    <span style="background:#333; color:#fff; font-size:0.6rem; padding:1px 5px; border-radius:4px; margin-right:5px;">x<?php echo $optQty; ?></span>
                                <?php endif; ?>
                                <span class="opt-price-tag" style="margin-right:5px;">(<?php echo $opt['price']; ?>)</span>
                            </div>
                            <div class="opt-actions">
                                <a href="cart.php?action=opt_minus&key=<?php echo $key; ?>&opt_idx=<?php echo $optIdx; ?>" class="circle-btn bg-orange"><i class="fas fa-minus"></i></a>
                                <a href="cart.php?action=opt_plus&key=<?php echo $key; ?>&opt_idx=<?php echo $optIdx; ?>" class="circle-btn bg-green"><i class="fas fa-plus"></i></a>
                                <a href="cart.php?action=opt_remove&key=<?php echo $key; ?>&opt_idx=<?php echo $optIdx; ?>" class="circle-btn bg-red"><i class="fas fa-times"></i></a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px;">
                        <div class="trash-main" onclick="showDeleteConfirm('cart.php?action=remove&key=<?php echo $key; ?>')">
                            <i class="fas fa-trash-alt"></i>
                        </div>

                        <div class="main-qty-control">
                            <a href="cart.php?action=minus&key=<?php echo $key; ?>" class="mq-btn"><i class="fas fa-minus"></i></a>
                            <div class="mq-val"><?php echo $item['qty']; ?></div>
                            <a href="cart.php?action=plus&key=<?php echo $key; ?>" class="mq-btn"><i class="fas fa-plus"></i></a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="checkout-wrapper">
            <div class="checkout-card">
                <div class="checkout-info">
                    <span class="checkout-label">الإجمالي (<?php echo count($cart); ?> وجبات)</span>
                    <span class="checkout-total"><?php echo number_format($grand_total, 2); ?> ر.س</span>
                </div>
                <a href="checkout.php" class="btn-checkout">
                    <span>إتمام الشراء</span>
                    <i class="fas fa-check-circle"></i>
                </a>
            </div>
        </div>

    <?php endif; ?>

    <div id="deleteModal" class="confirm-modal-overlay">
        <div class="confirm-box">
            <div class="confirm-icon">
                <i class="fas fa-trash-alt"></i>
            </div>
            <h3 class="confirm-title">حذف الوجبة؟</h3>
            <p class="confirm-text">هل أنت متأكد من رغبتك في إزالة هذه الوجبة من السلة نهائياً؟</p>
            <div class="confirm-actions">
                <button class="btn-confirm btn-no" onclick="closeDeleteConfirm()">تراجع</button>
                <a href="#" id="confirmDeleteBtn" class="btn-confirm btn-yes">نعم، احذف</a>
            </div>
        </div>
    </div>

    <?php if(file_exists('client_footer_nav.php')) include 'client_footer_nav.php'; ?>

    <script>
        // دالة لإظهار نافذة الحذف
        function showDeleteConfirm(deleteUrl) {
            $('#confirmDeleteBtn').attr('href', deleteUrl); 
            $('#deleteModal').css('display', 'flex').hide().fadeIn(200); 
        }

        // دالة لإخفاء النافذة
        function closeDeleteConfirm() {
            $('#deleteModal').fadeOut(200);
        }

        // إغلاق النافذة عند النقر في الخارج
        $('#deleteModal').on('click', function(e) {
            if (e.target === this) {
                closeDeleteConfirm();
            }
        });
    </script>

</body>
</html>