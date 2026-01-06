<?php
// client_package_details.php - تفاصيل الباقة وإتمام الاشتراك
// ============================================================

header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');

require_once 'db_connect.php'; 

// التحقق من الجلسة
if (session_status() == PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$client_id = $_SESSION['user_id'];
$error_msg = '';
$success_msg = '';

// 1. التحقق من وجود ID الباقة
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: client_browse_packages.php"); exit;
}

$pkg_id = (int)$_GET['id'];

// 2. جلب بيانات الباقة
$stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND is_active = 1");
$stmt->execute([$pkg_id]);
$pkg = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pkg) { die("الباقة غير موجودة أو غير مفعلة"); }

// 3. جلب قيود التصنيفات (للعرض)
$stmt_limits = $pdo->prepare("
    SELECT c.name, l.allowed_count 
    FROM package_category_limits l 
    JOIN categories c ON l.category_id = c.id 
    WHERE l.package_id = ?
");
$stmt_limits->execute([$pkg_id]);
$limits = $stmt_limits->fetchAll(PDO::FETCH_ASSOC);

// --- معالجة طلب الاشتراك (عند الضغط على الزر) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_subscription'])) {
    
    $start_date_str = $_POST['start_date'];
    
    if (empty($start_date_str)) {
        $error_msg = "يرجى تحديد تاريخ البدء.";
    } else {
        try {
            // حساب تاريخ الانتهاء
            $start_date = new DateTime($start_date_str);
            $today = new DateTime();
            $today->setTime(0,0,0); // تصفير الوقت للمقارنة الصحيحة

            if ($start_date < $today) {
                throw new Exception("تاريخ البدء لا يمكن أن يكون في الماضي.");
            }

            // مدة الباقة بالأيام
            $duration = (int)$pkg['duration_days'];
            
            // استنساخ تاريخ البدء لحساب النهاية
            $end_date = clone $start_date;
            $end_date->modify("+$duration days");

            // تنسيق التواريخ للقاعدة
            $start_fmt = $start_date->format('Y-m-d');
            $end_fmt = $end_date->format('Y-m-d');

            // تحديث جدول client_details (أو إنشاء سجل جديد في جدول subscriptions إذا كنت تستخدمه)
            // هنا سنفترض التحديث المباشر كما هو متبع في لوحة التحكم الحالية
            $stmt_update = $pdo->prepare("
                UPDATE client_details 
                SET package_id = ?, 
                    subscription_start_date = ?, 
                    subscription_end_date = ?
                WHERE user_id = ?
            ");
            $stmt_update->execute([$pkg_id, $start_fmt, $end_fmt, $client_id]);

            // (اختياري) تصفير عدادات الاستهلاك السابقة إذا كنت تستخدم جدول client_category_usage
            // $pdo->prepare("DELETE FROM client_category_usage WHERE subscription_id = ?")->execute([$client_id]);

            $success_msg = "تم الاشتراك بنجاح! سيتم تفعيل باقتك فوراً.";
            
            // تحويل للرئيسية بعد ثانيتين
            header("refresh:2;url=client_dashboard.php");

        } catch (Exception $e) {
            $error_msg = $e->getMessage();
        }
    }
}

// تحويل أيام الإجازة لنص مقروء
$off_days_ar = [];
if (!empty($pkg['off_days'])) {
    $days_map = ['Saturday'=>'السبت', 'Sunday'=>'الأحد', 'Monday'=>'الاثنين', 'Tuesday'=>'الثلاثاء', 'Wednesday'=>'الأربعاء', 'Thursday'=>'الخميس', 'Friday'=>'الجمعة'];
    foreach(explode(',', $pkg['off_days']) as $d) {
        if(isset($days_map[$d])) $off_days_ar[] = $days_map[$d];
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>تأكيد الاشتراك - <?php echo htmlspecialchars($pkg['name']); ?></title>
    
    <link rel="stylesheet" href="client_style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">

    <style>
        body { background-color: #f8f9fa; font-family: 'Tajawal', sans-serif; padding-bottom: 80px; }

        .checkout-header {
            background: #fff; padding: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            display: flex; align-items: center; justify-content: space-between;
            position: sticky; top: 0; z-index: 100;
        }
        .checkout-title { margin: 0; font-size: 1.1rem; color: #333; }

        .checkout-container { padding: 20px; max-width: 600px; margin: 0 auto; }

        /* Package Summary Card */
        .pkg-summary {
            background: #fff; border-radius: 20px; overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.03); margin-bottom: 25px;
        }
        .pkg-hero {
            height: 120px; background-size: cover; background-position: center; position: relative;
        }
        .pkg-hero::after {
            content: ''; position: absolute; top:0; left:0; width:100%; height:100%;
            background: linear-gradient(to bottom, transparent, rgba(0,0,0,0.7));
        }
        .pkg-hero-title {
            position: absolute; bottom: 15px; right: 20px; color: white;
            font-size: 1.3rem; font-weight: 800; text-shadow: 0 2px 5px rgba(0,0,0,0.5);
        }
        .pkg-info-row {
            display: flex; justify-content: space-between; padding: 15px 20px;
            border-bottom: 1px solid #f9f9f9; align-items: center;
        }
        .pkg-info-label { color: #777; font-size: 0.9rem; display: flex; align-items: center; gap: 8px; }
        .pkg-info-val { font-weight: bold; color: #333; font-size: 0.95rem; }

        /* Limits Box */
        .limits-box {
            background: #fffcf5; border: 1px dashed #f39c12; border-radius: 12px;
            padding: 15px; margin: 20px;
        }
        .limits-header { color: #d35400; font-weight: bold; margin-bottom: 10px; font-size: 0.9rem; }
        .limit-pill {
            display: inline-block; background: #fff; color: #d35400; border: 1px solid #f39c12;
            padding: 4px 10px; border-radius: 6px; font-size: 0.8rem; margin: 3px; font-weight: bold;
        }

        /* Form Section */
        .form-section { background: #fff; padding: 20px; border-radius: 20px; box-shadow: 0 5px 20px rgba(0,0,0,0.02); }
        .date-input {
            width: 100%; padding: 15px; border: 2px solid #eee; border-radius: 12px;
            font-family: inherit; font-size: 1rem; margin-top: 10px; outline: none; transition: 0.3s;
        }
        .date-input:focus { border-color: #8e44ad; }

        /* Total & Button */
        .total-area { margin-top: 30px; text-align: center; }
        .total-price { font-size: 2rem; font-weight: 900; color: #8e44ad; }
        .total-label { color: #999; font-size: 0.9rem; }

        .btn-confirm {
            background: linear-gradient(135deg, #8e44ad 0%, #9b59b6 100%);
            color: white; width: 100%; padding: 18px; border: none; border-radius: 15px;
            font-size: 1.1rem; font-weight: bold; margin-top: 20px; cursor: pointer;
            box-shadow: 0 10px 20px rgba(142, 68, 173, 0.3); transition: transform 0.2s;
        }
        .btn-confirm:active { transform: scale(0.98); }

        .alert-box { padding: 15px; border-radius: 10px; margin-bottom: 20px; text-align: center; }
        .alert-error { background: #fee; color: #c0392b; border: 1px solid #fcc; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }

    </style>
</head>
<body>

    <div class="checkout-header">
        <h1 class="checkout-title">إتمام الاشتراك 📝</h1>
        <a href="client_browse_packages.php" style="color:#777;"><i class="fas fa-times" style="font-size:1.2rem;"></i></a>
    </div>

    <div class="checkout-container">

        <?php if($error_msg): ?>
            <div class="alert-box alert-error"><?php echo $error_msg; ?></div>
        <?php endif; ?>
        
        <?php if($success_msg): ?>
            <div class="alert-box alert-success">
                <i class="fas fa-check-circle" style="font-size:2rem; display:block; margin-bottom:10px;"></i>
                <?php echo $success_msg; ?>
            </div>
        <?php else: ?>

        <form method="POST">
            
            <div class="pkg-summary">
                <div class="pkg-hero" style="background-image: url('<?php echo !empty($pkg['image_url']) ? $pkg['image_url'] : 'uploads/packages/default.png'; ?>');">
                    <div class="pkg-hero-title"><?php echo htmlspecialchars($pkg['name']); ?></div>
                </div>
                
                <div class="pkg-info-row">
                    <div class="pkg-info-label"><i class="far fa-clock"></i> مدة الاشتراك</div>
                    <div class="pkg-info-val"><?php echo $pkg['duration_days']; ?> يوم</div>
                </div>
                
                <div class="pkg-info-row">
                    <div class="pkg-info-label"><i class="fas fa-utensils"></i> الوجبات اليومية</div>
                    <div class="pkg-info-val"><?php echo $pkg['meals_per_day']; ?> وجبات</div>
                </div>

                <div class="pkg-info-row">
                    <div class="pkg-info-label"><i class="fas fa-weight-hanging"></i> حجم الوجبة</div>
                    <div class="pkg-info-val">
                        <?php echo $pkg['fixed_weight_label'] ? $pkg['fixed_weight_label'] . ' جرام (ثابت)' : 'اختياري (مفتوح)'; ?>
                    </div>
                </div>

                <?php if(!empty($off_days_ar)): ?>
                <div class="pkg-info-row">
                    <div class="pkg-info-label"><i class="fas fa-pause-circle"></i> أيام التوقف</div>
                    <div class="pkg-info-val" style="color:#e74c3c;">
                        <?php echo implode('، ', $off_days_ar); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($limits)): ?>
                <div class="limits-box">
                    <div class="limits-header"><i class="fas fa-info-circle"></i> سياسة الاستخدام العادل للباقة:</div>
                    <div>
                        <?php foreach($limits as $lim): ?>
                            <span class="limit-pill">
                                <?php echo $lim['name']; ?>: <?php echo $lim['allowed_count']; ?> وجبات
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="form-section">
                <label style="font-weight:bold; color:#333; display:block; margin-bottom:5px;">
                    <i class="far fa-calendar-alt" style="color:#8e44ad;"></i> متى تريد أن تبدأ؟
                </label>
                <input type="date" name="start_date" class="date-input" required min="<?php echo date('Y-m-d'); ?>">
                <div style="font-size:0.8rem; color:#777; margin-top:8px;">
                    * سيتم احتساب تاريخ الانتهاء تلقائياً بناءً على مدة الباقة.
                </div>
            </div>

            <div class="total-area">
                <div class="total-label">إجمالي المبلغ المطلوب</div>
                <div class="total-price"><?php echo number_format($pkg['price'], 2); ?> <span style="font-size:1rem; color:#777;">ر.س</span></div>
                
                <button type="submit" name="confirm_subscription" class="btn-confirm">
                    تأكيد الاشتراك والدفع <i class="fas fa-check"></i>
                </button>
                <div style="margin-top:15px; font-size:0.8rem; color:#aaa;">
                    بالضغط على تأكيد، أنت توافق على شروط وأحكام الباقة.
                </div>
            </div>

        </form>
        <?php endif; ?>

    </div>

    <?php include 'client_footer_nav.php'; ?>

</body>
</html>