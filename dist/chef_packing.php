<?php
// إجبار المتصفح على استخدام ترميز UTF-8
header('Content-Type: text/html; charset=utf-8');

// 1. تضمين "حارس الشيف"
require_once 'auth_chef.php'; 

// 2. تضمين ملف الاتصال
require_once 'db_connect.php'; 

$today_date = date('Y-m-d');

// 3. جلب "قائمة التجهيز" (مجمعة حسب العميل) مع التفاصيل (الجرامات)
$packing_list_grouped = [];
try {
    $sql_packing = "SELECT 
                        log.id AS log_id, 
                        u.name AS client_name, 
                        log.client_id,
                        m.name AS meal_name, 
                        m.details, -- *** جلب الجرامات ***
                        log.category,
                        log.status
                    FROM 
                        delivery_log AS log
                    JOIN 
                        users AS u ON log.client_id = u.id
                    JOIN 
                        meals AS m ON log.meal_id = m.id
                    WHERE 
                        log.delivery_date = ? AND log.status IN ('pending', 'prepared')
                    ORDER BY
                        u.name, log.category";
                        
    $stmt_packing = $pdo->prepare($sql_packing);
    $stmt_packing->execute([$today_date]);
    
    $packing_list_raw = $stmt_packing->fetchAll();
    foreach ($packing_list_raw as $item) {
        $client_id = $item['client_id'];
        $packing_list_grouped[$client_id]['client_name'] = $item['client_name'];
        $packing_list_grouped[$client_id]['items'][] = $item;
        if ($item['status'] == 'pending') {
            $packing_list_grouped[$client_id]['status'] = 'pending';
        } elseif (!isset($packing_list_grouped[$client_id]['status'])) {
            $packing_list_grouped[$client_id]['status'] = 'prepared';
        }
    }
    
} catch (PDOException $e) { die("خطأ في جلب قائمة التجهيز: " . $e->getMessage()); }

function translate_category($category) {
    $map = ['fator' => 'فطور', 'ghada' => 'غداء', 'asha' => 'عشاء'];
    return $map[$category] ?? $category;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>لوحة تحكم الشيف - تجهيز الطلبات</title>
    
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        .chef-sidebar { background-color: #d35400; }
        .chef-sidebar-header { border-bottom: 1px solid #f0a273; }
        .chef-top-bar { background-color: #fdfefe; }
        
        .packing-item-card {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 15px;
            background-color: var(--card-bg);
            opacity: 1;
            /* تجنب كسر الصفحة عند الطباعة */
            page-break-inside: avoid; 
        }
        .packing-item-card.is-prepared { opacity: 0.6; background-color: #f4f7f6; }
        .packing-item-header { display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; background-color: #f8f9fa; border-bottom: 1px solid var(--border-color); }
        .packing-item-header h3 { margin: 0; font-size: 1.3rem; }
        .packing-item-body { padding: 20px; }
        .packing-item-body ul { padding-right: 20px; margin: 0; }
        .packing-item-body li { font-size: 1.1rem; margin-bottom: 8px; }
        
        /* *** تنسيقات طباعة الملصقات *** */
        .meal-details-print {
            font-size: 0.9rem;
            color: #555;
            padding-right: 20px;
            white-space: pre-wrap; /* للحفاظ على تنسيق الجرامات */
        }
        
        @media print {
            body > * {
                display: none;
            }
            
            /* إظهار كل بطاقات الملصقات */
            .packing-list-container, .packing-list-container * {
                display: block;
                visibility: visible;
            }
            .packing-list-container {
                position: absolute;
                top: 0;
                right: 0;
                width: 100%;
            }
            
            /* تنسيق الملصق الفردي */
            .packing-item-card {
                width: 90%;
                margin: 20px auto;
                border: 2px dashed #333;
                page-break-inside: avoid;
            }
            
            /* إخفاء الأزرار عند طباعة الكل */
            .packing-item-header .btn {
                display: none;
            }
            
            /* إخفاء زر "طباعة الكل" عند طباعة ملصق فردي */
            .print-all-button {
                display: none;
            }
            
            /* إظهار الملصق الفردي فقط عند طباعته */
            body.printing-single .packing-item-card:not(.print-this-label) {
                display: none;
            }
        }
    </style>
</head>
<body>

    <div class="sidebar chef-sidebar">
        <div class="sidebar-header chef-sidebar-header">
            <h3><i class="fas fa-utensils"></i> واجهة المطبخ</h3>
        </div>
        <nav class="sidebar-nav">
            <a href="chef_dashboard.php"><i class="fas fa-chart-pie"></i> ملخص التحضير</a>
            <a href="chef_packing.php" class="active"><i class="fas fa-box-open"></i> تجهيز الطلبات</a>
        </nav>
    </div>

    <div class="main-content">
        <header class="top-bar chef-top-bar">
            </header>

        <main class="content-wrapper">
            <div class="form-card">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h2><i class="fas fa-box-open"></i> تجهيز الطلبات وطباعة الملصقات</h2>
                    <button onclick="window.print();" class="btn print-all-button" style="background-color: var(--dark-bg); font-size: 0.9rem; padding: 8px 15px;">
                        <i class="fas fa-print"></i> طباعة كل الطلبات
                    </button>
                </div>
                
                <div class="packing-list-container">
                    <?php if (empty($packing_list_grouped)): ?>
                        <div style="text-align: center; padding: 20px;">لا توجد طلبات لعرضها.</div>
                    <?php else: ?>
                        <?php foreach ($packing_list_grouped as $client_id => $order): ?>
                            <div class="packing-item-card <?php echo ($order['status'] == 'prepared') ? 'is-prepared' : ''; ?>" 
                                 id="label-<?php echo $client_id; ?>"> <div class="packing-item-header">
                                    <h3>
                                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($order['client_name']); ?>
                                    </h3>
                                    
                                    <div>
                                        <button onclick="printLabel('<?php echo $client_id; ?>');" class="btn" style="background-color: #ffc107; color: #333; font-size: 0.9rem; padding: 8px 12px;">
                                            <i class="fas fa-sticky-note"></i> طباعة ملصق
                                        </button>
                                        
                                        <?php if ($order['status'] == 'pending'): ?>
                                            <a href="chef_mark_prepared.php?client_id=<?php echo $client_id; ?>&return_to=packing" class="btn" style="background-color: var(--success); font-size: 0.9rem; padding: 8px 12px;">
                                                <i class="fas fa-check"></i> تم التحضير
                                            </a>
                                        <?php else: ?>
                                            <span style="color: var(--success); font-weight: 600; margin-right: 10px;"><i class="fas fa-check-circle"></i> جاهز</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="packing-item-body">
                                    <ul>
                                        <?php foreach ($order['items'] as $item): ?>
                                            <li>
                                                <strong><?php echo translate_category($item['category']); ?>:</strong> 
                                                <?php echo htmlspecialchars($item['meal_name']); ?>
                                                
                                                <?php if (!empty($item['details'])): ?>
                                                    <div class="meal-details-print"><?php echo htmlspecialchars($item['details']); ?></div>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        function printLabel(clientId) {
            var cardId = 'label-' + clientId;
            var cardToPrint = document.getElementById(cardId);
            
            if (cardToPrint) {
                // إضافة كلاسات خاصة للطباعة
                document.body.classList.add('printing-single');
                cardToPrint.classList.add('print-this-label');
                
                // بدء الطباعة
                window.print();
                
                // إزالة الكلاسات بعد الطباعة (أو إلغاؤها)
                // (قد تحتاج لوقت أطول إذا كانت الطباعة بطيئة)
                setTimeout(function() {
                    document.body.classList.remove('printing-single');
                    cardToPrint.classList.remove('print-this-label');
                }, 1000);
            }
        }
    </script>
</body>
</html>