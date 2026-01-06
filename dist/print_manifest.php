<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['print_data'])) { die("لا توجد بيانات."); }
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>كشف التسليم</title>
    <style>
        body { font-family: sans-serif; padding: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #000; padding: 8px; text-align: center; font-size: 12px; }
        th { background: #f0f0f0; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <div class="no-print" style="text-align:center; margin-bottom:20px;"><button onclick="window.print()">طباعة</button></div>
    
    <h2 style="text-align:center;">كشف تسليم السائق (<?php echo $today; ?>)</h2>
    <table>
        <thead>
            <tr>
                <th>#</th><th>العميل</th><th>الجوال</th><th>العنوان</th><th>الطلب</th><th>توقيع الاستلام</th>
            </tr>
        </thead>
        <tbody>
            <?php $i=1; foreach ($_POST['print_data'] as $json): $d = json_decode($json, true); ?>
            <tr>
                <td><?php echo $i++; ?></td>
                <td><?php echo $d['client']; ?></td>
                <td><?php echo $d['phone']; ?></td>
                <td><?php echo $d['address']; ?></td>
                <td><?php echo implode(' + ', $d['meals']); ?></td>
                <td></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div style="display:flex; justify-content:space-between; margin-top:50px; padding:0 50px;">
        <div><strong>توقيع المجهز:</strong> ........................</div>
        <div><strong>توقيع السائق:</strong> ........................</div>
    </div>
</body>
</html>