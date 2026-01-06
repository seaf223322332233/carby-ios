<?php 
header('Content-Type: text/html; charset=utf-8');
$id = $_GET['id'] ?? 0; 
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نجاح</title>
    <link rel="stylesheet" href="client_style.css?v=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body style="display:flex; align-items:center; justify-content:center; height:100vh; background:#fff;">
    
    <div style="text-align:center; padding:20px;">
        <div class="success-icon-box"><i class="fas fa-check"></i></div>
        <h2 style="color:var(--primary);">تم الطلب بنجاح!</h2>
        <p style="color:#777;">رقم طلبك هو <strong>#<?php echo $id; ?></strong></p>
        <br>
        <a href="my_orders.php" class="btn">متابعة الطلب</a>
        <br><br>
        <a href="client_dashboard.php" style="color:#999; font-size:0.9rem;">العودة للرئيسية</a>
    </div>

</body>
</html>