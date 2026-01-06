<?php
header('Content-Type: text/html; charset=utf-8');
require_once 'auth_admin.php';
require_once 'db_connect.php';

$rows = [];
try {
    $rows = $pdo->query("
        SELECT cd.user_id, u.name, cd.package_id, p.name AS package_name,
               cd.subscription_status, cd.subscription_start_date, cd.subscription_end_date,
               cd.total_meals_allowed, cd.daily_limit, cd.subscription_days,
               cd.options_visible_override, cd.options_selectable_override,
               p.options_visible_count, p.options_selectable_count
        FROM client_details cd
        JOIN users u ON u.id = cd.user_id
        LEFT JOIN packages p ON p.id = cd.package_id
        ORDER BY cd.user_id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $rows = [];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>إدارة اشتراكات الباقات</title>
<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="admin-unified-style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<style>
.card{background:#fff;border:1px solid #eee;border-radius:14px;padding:16px;margin:14px 0;box-shadow:0 2px 10px rgba(0,0,0,.03)}
.h{font-weight:900;font-size:1.1rem;margin:0 0 8px}
.meta{color:#6b7280;font-weight:800;line-height:1.8}
.badge{display:inline-block;padding:6px 12px;border-radius:999px;background:#111827;color:#fff;font-weight:900;font-size:.85rem}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
</style>
</head>
<body>

<div class="sidebar"><?php include 'sidebar.php'; ?></div>
<div class="main-content">
<header class="top-bar"><h2>إدارة اشتراكات الباقات</h2></header>

<main class="content-wrapper">
<?php if(empty($rows)): ?>
  <div class="card">لا توجد بيانات</div>
<?php else: ?>
  <?php foreach($rows as $r): 
    $visible = ($r['options_visible_override'] !== null) ? (int)$r['options_visible_override'] : (int)$r['options_visible_count'];
    $select  = ($r['options_selectable_override'] !== null) ? (int)$r['options_selectable_override'] : (int)$r['options_selectable_count'];
  ?>
    <div class="card">
      <div class="h">
        <?php echo htmlspecialchars($r['name']); ?>
        <span class="badge"><?php echo htmlspecialchars($r['subscription_status']); ?></span>
      </div>
      <div class="meta">
        الباقة: <?php echo htmlspecialchars($r['package_name'] ?: '-'); ?> |
        العميل: #<?php echo (int)$r['user_id']; ?><br>
        البداية: <?php echo htmlspecialchars($r['subscription_start_date'] ?: '-'); ?> |
        النهاية: <?php echo htmlspecialchars($r['subscription_end_date'] ?: '-'); ?><br>
        الرصيد (وجبات): <?php echo (int)($r['total_meals_allowed'] ?? 0); ?> |
        حد يومي: <?php echo (int)($r['daily_limit'] ?? 0); ?> |
        مدة: <?php echo (int)($r['subscription_days'] ?? 0); ?> يوم<br>
        الخيارات: يظهر <?php echo $visible; ?> | يختار <?php echo $select; ?><br>
        <a href="subscription_edit.php?id=<?php echo (int)$r['user_id']; ?>" 
           style="display:inline-block;margin-top:10px;padding:8px 16px;background:#6c5ce7;color:#fff;border-radius:8px;text-decoration:none;font-weight:bold;">
          <i class="fas fa-edit"></i> تعديل الاشتراك
        </a>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
</main>
</div>

</body>
</html>