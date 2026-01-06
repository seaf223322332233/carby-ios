<?php
header('Content-Type: text/html; charset=utf-8');
require_once 'auth_client.php'; 
require_once 'db_connect.php'; 

if (!isset($_GET['log_id'])) { header("Location: client_history.php"); exit; }
$log_id = (int)$_GET['log_id'];
$client_id = $_SESSION['user_id'];

// 1. جلب تفاصيل الطلب الحالي وتفاصيل الباقة للعميل
$current_order = [];
$allowed_meals = [];

try {
    // جلب الطلب للتحقق
    $stmt = $pdo->prepare("SELECT log.*, m.name as old_meal_name 
                           FROM delivery_log log 
                           JOIN meals m ON log.meal_id = m.id 
                           WHERE log.id = ? AND log.client_id = ? AND log.status = 'pending'");
    $stmt->execute([$log_id, $client_id]);
    $current_order = $stmt->fetch();

    if (!$current_order) { die("لا يمكن تعديل هذا الطلب (قد يكون تم تحضيره أو غير موجود). <a href='client_history.php'>عودة</a>"); }

    // جلب قيود الباقة للعميل
    $stmt_pkg = $pdo->prepare("SELECT p.protein_weight, p.protein_type 
                               FROM client_details cd 
                               JOIN packages p ON cd.package_id = p.id 
                               WHERE cd.user_id = ?");
    $stmt_pkg->execute([$client_id]);
    $pkg = $stmt_pkg->fetch();

    // جلب الوجبات البديلة (نفس التصنيف ونفس شروط الباقة)
    if ($pkg) {
        $weight = $pkg['protein_weight'];
        $type_code = $pkg['protein_type'];
        $category = $current_order['category']; // فطور، غداء، عشاء

        // تحليل أنواع البروتين المسموحة
        $allowed_proteins = ($type_code == 'dajaj') ? ['dajaj'] : (($type_code == 'dajaj_lahm') ? ['dajaj', 'lahm'] : ['dajaj', 'lahm', 'samak']);
        $in_query = "'" . implode("','", $allowed_proteins) . "'";

        $sql_meals = "SELECT * FROM meals 
                      WHERE category = ? 
                      AND protein_weight = ? 
                      AND protein_type IN ($in_query)
                      AND id != ?"; // استبعاد الوجبة الحالية
        
        $stmt_m = $pdo->prepare($sql_meals);
        $stmt_m->execute([$category, $weight, $current_order['meal_id']]);
        $allowed_meals = $stmt_m->fetchAll();
    }

} catch (Exception $e) { die("خطأ: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>تغيير الوجبة</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .meal-option-card {
            background: #fff; border: 1px solid #eee; border-radius: 12px; padding: 10px;
            display: flex; align-items: center; gap: 15px; margin-bottom: 10px; cursor: pointer;
            transition: 0.2s;
        }
        .meal-option-card:hover { border-color: var(--client-primary); background: #f9f9ff; }
        .meal-option-card input { display: none; }
        .meal-option-card input:checked + .content { color: var(--client-primary); font-weight: bold; }
        .meal-img { width: 60px; height: 60px; border-radius: 10px; object-fit: cover; }
    </style>
</head>
<body>
    <div class="main-content" style="margin:0;">
        <header class="top-bar client-top-bar">
            <div class="user-welcome">تغيير الوجبة</div>
            <a href="client_history.php" class="logout-link"><i class="fas fa-arrow-left"></i></a>
        </header>

        <div class="content-wrapper">
            <div class="alert-message alert-info">
                أنت تقوم بتغيير وجبة يوم <strong><?php echo $current_order['delivery_date']; ?></strong>
                <br>الوجبة الحالية: <strong><?php echo htmlspecialchars($current_order['old_meal_name']); ?></strong>
            </div>

            <h3 style="margin-bottom:15px;">اختر الوجبة البديلة:</h3>
            
            <form action="handle_change_meal.php" method="POST">
                <input type="hidden" name="log_id" value="<?php echo $log_id; ?>">
                
                <?php if (empty($allowed_meals)): ?>
                    <p style="text-align:center; color:#777;">لا توجد وجبات بديلة متاحة في باقتك لهذا التصنيف.</p>
                <?php else: ?>
                    <?php foreach ($allowed_meals as $meal): 
                         $img = !empty($meal['image_url']) ? $meal['image_url'] : 'uploads/meals/placeholder.png';
                    ?>
                    <label class="meal-option-card">
                        <input type="radio" name="new_meal_id" value="<?php echo $meal['id']; ?>" required>
                        <img src="<?php echo $img; ?>" class="meal-img">
                        <div class="content">
                            <div><?php echo htmlspecialchars($meal['name']); ?></div>
                        </div>
                        <div style="margin-right:auto;"><i class="far fa-circle"></i></div>
                    </label>
                    <?php endforeach; ?>
                    
                    <button type="submit" class="btn btn-primary" style="width:100%; margin-top:20px;">تأكيد التغيير</button>
                <?php endif; ?>
            </form>
        </div>
    </div>
    
    <script>
        // تغيير أيقونة الاختيار عند الضغط
        document.querySelectorAll('input[type=radio]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.querySelectorAll('.fa-check-circle').forEach(i => i.className = 'far fa-circle');
                this.parentElement.querySelector('i').className = 'fas fa-check-circle';
                this.parentElement.querySelector('i').style.color = 'var(--client-primary)';
            });
        });
    </script>
</body>
</html>