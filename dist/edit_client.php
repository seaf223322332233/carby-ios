<?php
header('Content-Type: text/html; charset=utf-8');
require_once 'auth_admin.php'; 
require_once 'db_connect.php';
require_once 'csrf.php';

if (!isset($_GET['id'])) { 
    header("Location: view_clients.php?error=invalid");
    exit;
}
$user_id = (int)$_GET['id'];

try {
    // جلب بيانات العميل مع subscription_status
    $sql = "SELECT u.id, u.name, u.email, 
                   cd.phone_number, cd.invoice_id, cd.subscription_end_date, 
                   cd.Maps_link, cd.address_text, cd.package_id, cd.subscription_status
            FROM users AS u 
            LEFT JOIN client_details AS cd ON u.id = cd.user_id
            WHERE u.id = ? AND u.role = 'client'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) { 
        header("Location: view_clients.php?error=notfound");
        exit;
    }
    
    $packages = $pdo->query("SELECT id, name FROM packages ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { 
    header("Location: view_clients.php?error=server");
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تعديل العميل</title>
    <link rel="stylesheet" href="admin_colors.php">
    <link rel="stylesheet" href="admin-unified-style-v2.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
</head>
<body>
    <div class="sidebar"><?php include 'sidebar.php'; ?></div>
    
    <div class="main-content">
        <header class="top-bar">
            <div class="user-info">
                <i class="fas fa-user-edit"></i> تعديل العميل
            </div>
            <a href="logout.php" class="logout-link">
                <i class="fas fa-sign-out-alt"></i> خروج
            </a>
        </header>

        <main class="content-wrapper">
            <div class="card" style="padding: 0;">
                <div style="padding: 24px; border-bottom: 1px solid var(--admin-border);">
                    <h2 style="margin: 0; font-size: 1.5rem; font-weight: 900; display: flex; align-items: center; gap: 12px;">
                        <i class="fas fa-user-edit" style="color: var(--restaurant-primary);"></i>
                        تعديل العميل: <?php echo htmlspecialchars($client['name']); ?>
                    </h2>
                </div>

                <form action="handle_edit_client.php" method="POST" style="padding: 24px;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="user_id" value="<?php echo $client['id']; ?>">
                    
                    <!-- بيانات المستخدم الأساسية -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-user-circle"></i> البيانات الشخصية
                        </h3>
                        
                        <div style="margin-bottom: 20px;">
                            <label>الاسم <span style="color: var(--restaurant-accent);">*</span></label>
                            <input type="text" name="name" class="control" 
                                   value="<?php echo htmlspecialchars($client['name']); ?>" required>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label>البريد الإلكتروني <span style="color: var(--restaurant-accent);">*</span></label>
                            <input type="email" name="email" class="control" 
                                   value="<?php echo htmlspecialchars($client['email']); ?>" required>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label>كلمة المرور (اتركها فارغة إذا لم تريد تغييرها)</label>
                            <input type="password" name="password" class="control" 
                                   placeholder="كلمة المرور الجديدة">
                        </div>
                    </div>

                    <!-- معلومات الاتصال -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-phone"></i> معلومات الاتصال
                        </h3>

                        <div style="margin-bottom: 20px;">
                            <label>الجوال <span style="color: var(--restaurant-accent);">*</span></label>
                            <input type="text" name="phone_number" class="control" 
                                   value="<?php echo htmlspecialchars($client['phone_number'] ?? ''); ?>" required>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label>الفاتورة</label>
                            <input type="text" name="invoice_id" class="control" 
                                   value="<?php echo htmlspecialchars($client['invoice_id'] ?? ''); ?>">
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label>العنوان (كتابة)</label>
                            <textarea name="address_text" class="control" rows="3" style="min-height: 80px; resize: vertical;"><?php echo htmlspecialchars($client['address_text'] ?? ''); ?></textarea>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label>رابط الخريطة (Google Maps)</label>
                            <input type="url" name="google_maps_link" class="control" 
                                   value="<?php echo htmlspecialchars($client['Maps_link'] ?? ''); ?>" 
                                   placeholder="https://maps.google.com/...">
                        </div>
                    </div>

                    <!-- الباقة والاشتراك -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-box"></i> الباقة والاشتراك
                        </h3>

                        <div style="margin-bottom: 20px;">
                            <label>الباقة</label>
                            <select name="package_id" class="control" id="package_select">
                                <option value="">-- بدون باقة (إيقاف الباقة) --</option>
                                <?php foreach ($packages as $pkg): ?>
                                    <option value="<?php echo $pkg['id']; ?>" 
                                        <?php echo ($client['package_id'] == $pkg['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($pkg['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small style="display: block; margin-top: 6px; color: var(--admin-text-secondary); font-size: 0.85rem;">
                                <i class="fas fa-info-circle"></i> 
                                اختر "بدون باقة" لإيقاف الباقة الحالية
                            </small>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label>حالة الاشتراك</label>
                            <select name="subscription_status" class="control">
                                <option value="none" <?php echo (($client['subscription_status'] ?? 'none') == 'none') ? 'selected' : ''; ?>>
                                    -- بدون اشتراك --
                                </option>
                                <option value="active" <?php echo (($client['subscription_status'] ?? '') == 'active') ? 'selected' : ''; ?>>
                                    نشط
                                </option>
                                <option value="paused" <?php echo (($client['subscription_status'] ?? '') == 'paused') ? 'selected' : ''; ?>>
                                    متوقف
                                </option>
                                <option value="expired" <?php echo (($client['subscription_status'] ?? '') == 'expired') ? 'selected' : ''; ?>>
                                    منتهي
                                </option>
                                <option value="pending_payment" <?php echo (($client['subscription_status'] ?? '') == 'pending_payment') ? 'selected' : ''; ?>>
                                    في انتظار الدفع
                                </option>
                            </select>
                            <small style="display: block; margin-top: 6px; color: var(--admin-text-secondary); font-size: 0.85rem;">
                                <i class="fas fa-info-circle"></i> 
                                اختر "متوقف" لإيقاف الاشتراك مؤقتاً
                            </small>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label>تاريخ انتهاء الاشتراك <span style="color: var(--restaurant-accent);">*</span></label>
                            <input type="date" name="subscription_end_date" class="control" 
                                   value="<?php echo htmlspecialchars($client['subscription_end_date'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <!-- أزرار الإجراءات -->
                    <div style="display: flex; gap: 16px; margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--admin-border);">
                        <button type="submit" class="btn btnPrimary">
                            <i class="fas fa-save"></i> حفظ التعديلات
                        </button>
                        <a href="view_clients.php" class="btn btnGhost">
                            <i class="fas fa-times"></i> إلغاء
                        </a>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <style>
        .form-section {
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--admin-border);
        }

        .form-section:last-of-type {
            border-bottom: none;
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--admin-text-main);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--restaurant-primary);
        }

        .section-title i {
            color: var(--restaurant-primary);
            background: rgba(217, 119, 6, 0.1);
            padding: 8px;
            border-radius: 8px;
        }
    </style>
</body>
</html>