<?php
header('Content-Type: text/html; charset=utf-8');
require_once 'auth_admin.php'; 
require_once 'db_connect.php'; 

// جلب الباقات المتاحة للقائمة المنسدلة
$packages = $pdo->query("SELECT * FROM packages ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إضافة عميل</title>
    <link rel="stylesheet" href="admin-unified-style-v2.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
</head>
<body>
    <div class="sidebar"><?php include 'sidebar.php'; ?></div>

    <div class="main-content">
        <header class="top-bar">
            <div class="user-info">إضافة عميل</div>
            <a href="logout.php" class="logout-link">خروج</a>
        </header>

        <main class="content-wrapper">
            
            <div style="margin-bottom:20px;">
                <a href="view_clients.php" class="btn btn-warning">عودة للقائمة</a>
            </div>

            <div class="form-card">
                <h2><i class="fas fa-user-plus"></i> تسجيل مشترك جديد</h2>
                
                <form action="handle_add_client.php" method="POST">
                    
                    <h3 style="color:#555; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:5px;">1. بيانات الدخول</h3>
                    
                    <div class="form-group">
                        <label>اسم العميل (ثلاثي)</label>
                        <input type="text" name="name" required placeholder="محمد أحمد علي">
                    </div>
                    
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                        <div class="form-group">
                            <label>البريد الإلكتروني (اسم المستخدم)</label>
                            <input type="email" name="email" required placeholder="client@example.com">
                        </div>
                        <div class="form-group">
                            <label>كلمة المرور</label>
                            <input type="text" name="password" required placeholder="******">
                        </div>
                    </div>

                    <h3 style="color:#555; margin:25px 0 15px; border-bottom:1px solid #eee; padding-bottom:5px;">2. تفاصيل الاشتراك</h3>

                    <div class="form-group">
                        <label>اختر الباقة</label>
                        <select name="package_id" required style="border:2px solid var(--client-primary);">
                            <option value="">-- اختر الباقة --</option>
                            <?php foreach($packages as $pkg): ?>
                                <option value="<?php echo $pkg['id']; ?>">
                                    <?php echo htmlspecialchars($pkg['name']); ?> 
                                    (<?php echo $pkg['meals_per_day']; ?> وجبات - <?php echo $pkg['protein_weight']; ?>g)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                        <div class="form-group">
                            <label>رقم الجوال</label>
                            <input type="text" name="phone_number" required placeholder="05xxxxxxxx">
                        </div>
                        <div class="form-group">
                            <label>تاريخ انتهاء الاشتراك</label>
                            <input type="date" name="subscription_end_date" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>العنوان (كتابة)</label>
                        <textarea name="address_text" rows="2" placeholder="الحي، الشارع، رقم المنزل..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>رابط الموقع (Google Maps)</label>
                        <input type="url" name="google_maps_link">
                    </div>

                    <button type="submit" class="btn btn-success" style="width:100%; padding:15px; font-size:1.1rem;">إنشاء الحساب وتفعيل الاشتراك</button>
                </form>
            </div>
        </main>
    </div>
</body>
</html>