<?php
// التأكد من وجود بيانات للطباعة
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['print_data'])) {
    die("
        <div style='font-family: sans-serif; text-align: center; margin-top: 50px;'>
            <h3>⚠️ لا توجد بيانات لطباعتها!</h3>
            <p>الرجاء العودة وتحديد الطلبات أولاً.</p>
            <a href='preparer_dashboard.php' style='padding:10px 20px; background:#333; color:#fff; text-decoration:none; border-radius:5px;'>عودة</a>
        </div>
    ");
}

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>طباعة الملصقات</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* --- إعدادات الصفحة العامة --- */
        body { 
            font-family: 'Tajawal', sans-serif; 
            background-color: #f4f4f4; 
            margin: 0; 
            padding: 20px;
        }

        /* حاوية العرض (فليكس لتنسيق الشاشة) */
        .labels-container {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            justify-content: center;
            align-items: flex-start;
        }

        /* --- تصميم الملصق (Sticker) --- */
        .sticker {
            width: 380px;   /* العرض المثالي للملصق */
            min-height: 240px;
            background: #fff;
            border: 2px solid #000;
            padding: 15px;
            box-sizing: border-box;
            position: relative;
            display: flex;
            flex-direction: column;
            margin: 10px;
            page-break-inside: avoid; /* يمنع انقسام الملصق بين صفحتين */
            float: right; /* للطباعة الصحيحة بالعربي */
        }

        /* رأس الملصق */
        .sticker-head {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 8px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .brand-name { font-weight: 900; font-size: 1.1rem; }
        .print-date { font-size: 0.75rem; font-weight: bold; }

        /* صفوف المعلومات */
        .info-row {
            display: flex;
            margin-bottom: 6px;
            font-size: 0.95rem;
            border-bottom: 1px dotted #ddd;
            padding-bottom: 2px;
        }
        .label-text { font-weight: bold; width: 60px; flex-shrink: 0; }
        .value-text { font-weight: 800; flex: 1; }

        /* صندوق الوجبات (المهم) */
        .meals-box {
            border: 2px solid #000;
            background-color: #fdfdfd;
            padding: 8px;
            margin: 10px 0;
            font-weight: 900; /* خط عريض جداً للوجبة */
            font-size: 1.1rem;
            text-align: center;
            line-height: 1.4;
        }

        /* تذييل */
        .sticker-foot {
            margin-top: auto;
            border-top: 1px dashed #000;
            padding-top: 5px;
            text-align: center;
            font-size: 0.8rem;
            font-weight: bold;
        }

        /* --- إعدادات الطباعة --- */
        @media print {
            body { background-color: #fff; padding: 0; margin: 0; }
            .no-print { display: none !important; }
            .labels-container { display: block; } /* إلغاء الفليكس للطباعة المتتالية */
            .sticker { 
                border: 2px solid #000 !important;
                box-shadow: none !important;
                margin: 5px;
                page-break-after: auto; /* السماح بتتابع الملصقات */
            }
        }

        /* أزرار التحكم */
        .control-bar {
            text-align: center; margin-bottom: 30px; background: #fff; padding: 15px;
            border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .btn {
            padding: 10px 25px; border-radius: 5px; text-decoration: none; 
            font-weight: bold; cursor: pointer; border: none; margin: 0 5px; font-size: 1rem;
        }
        .btn-print { background: #2c3e50; color: white; }
        .btn-back { background: #ddd; color: #333; }
    </style>
</head>
<body onload="window.print()">

    <div class="control-bar no-print">
        <button onclick="window.print()" class="btn btn-print">🖨️ طباعة الملصقات</button>
        <a href="preparer_dashboard.php" class="btn btn-back">رجوع للوحة</a>
    </div>

    <div class="labels-container">
        <?php 
        foreach ($_POST['print_data'] as $json_data): 
            // 1. فك التشفير
            $data = json_decode($json_data, true);
            
            // 2. تحليل البيانات الذكي (لحل مشكلة الأسماء المفقودة)
            // نبحث عن الاسم في عدة مفاتيح محتملة
            $client_name = $data['client_name'] ?? $data['client'] ?? 'عميل';
            
            // نبحث عن الباقة (لحل مشكلة "خاص")
            // إذا كانت القيمة "خاص" أو غير موجودة، نحاول إيجاد مفتاح آخر أو نتركها فارغة
            $package_name = $data['package'] ?? $data['pkg_name'] ?? $data['info'] ?? '';
            if($package_name == 'خاص' || empty($package_name)) {
                $package_name = 'اشتراك'; // اسم افتراضي بدلاً من "خاص"
            }

            $phone = $data['phone'] ?? $data['phone_number'] ?? '';
            $address = $data['address'] ?? $data['address_text'] ?? '';
            
            // الوجبات
            $meals_text = is_array($data['meals']) ? implode(' + ', $data['meals']) : $data['meals'];
            if (empty($meals_text)) continue; // تخطي إذا لا توجد وجبة
        ?>
        
        <div class="sticker">
            
            <div class="sticker-head">
                <span class="brand-name">وجباتي الصحية 🥗</span>
                <span class="print-date"><?php echo $today; ?></span>
            </div>

            <div class="sticker-content">
                <div class="info-row">
                    <span class="label-text">العميل:</span>
                    <span class="value-text"><?php echo htmlspecialchars($client_name); ?></span>
                </div>
                
                <div class="info-row">
                    <span class="label-text">الجوال:</span>
                    <span class="value-text" dir="ltr" style="text-align:right;"><?php echo htmlspecialchars($phone); ?></span>
                </div>

                <div class="info-row">
                    <span class="label-text">الباقة:</span>
                    <span class="value-text"><?php echo htmlspecialchars($package_name); ?></span>
                </div>
                
                <div class="info-row" style="border-bottom:none;">
                    <span class="label-text">العنوان:</span>
                    <span class="value-text" style="font-size:0.85rem;"><?php echo htmlspecialchars($address); ?></span>
                </div>

                <div class="meals-box">
                    <?php echo $meals_text; ?>
                </div>
            </div>

            <div class="sticker-foot">
                صحة وعافية ❤️
            </div>

        </div>
        <?php endforeach; ?>
    </div>

</body>
</html>