<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Content-Type: text/html; charset=utf-8");

require_once 'auth_preparer.php';
require_once 'db_connect.php';

$today = date('Y-m-d');

/*
================================================================================
🛎️ نظام الإشعارات (يعتمد على جدول notifications)
--------------------------------------------------------------------------------
✅ جدول مقترح (نفّذه مرة واحدة في قاعدة البيانات إن لم يكن موجوداً):

CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  role VARCHAR(50) NULL,
  title VARCHAR(150) DEFAULT NULL,
  message VARCHAR(255) NOT NULL,
  link VARCHAR(255) DEFAULT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id),
  INDEX (role),
  INDEX (is_read),
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

================================================================================
*/

// 🔔 API: جلب الإشعارات (AJAX)
if (isset($_GET['action']) && $_GET['action'] === 'fetch_notifications') {
    if (session_status() == PHP_SESSION_NONE) session_start();

    $uid  = (int)($_SESSION['user_id'] ?? 0);
    $role = ($_SESSION['role'] ?? 'preparer');

    $limit = 12;

    // unread count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM notifications 
        WHERE is_read = 0 AND (
            user_id = ? OR (user_id IS NULL AND role = ?)
        )
    ");
    $stmt->execute([$uid, $role]);
    $unread = (int)$stmt->fetchColumn();

    // latest list
    $stmt = $pdo->prepare("
        SELECT id, title, message, link, is_read, created_at
        FROM notifications
        WHERE (user_id = ? OR (user_id IS NULL AND role = ?))
        ORDER BY id DESC
        LIMIT $limit
    ");
    $stmt->execute([$uid, $role]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'unread' => $unread,
        'items' => $rows
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ✅ API: تعليم إشعار كمقروء (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_notification_read') {
    if (session_status() == PHP_SESSION_NONE) session_start();

    $uid  = (int)($_SESSION['user_id'] ?? 0);
    $role = ($_SESSION['role'] ?? 'preparer');

    $nid = (int)($_POST['id'] ?? 0);
    if ($nid > 0) {
        $stmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE id = ?
              AND (user_id = ? OR (user_id IS NULL AND role = ?))
        ");
        $stmt->execute([$nid, $uid, $role]);
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ✅ دالة مساعدة لإرسال إشعار حسب role (لجميع المستخدمين بهذا الدور إن وُجد جدول users.role)
function notifyRole($pdo, $role, $message, $title = null, $link = null) {
    try {
        // لو عندك users فيها عمود role
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role = ?");
        $stmt->execute([$role]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if ($ids && count($ids) > 0) {
            $ins = $pdo->prepare("INSERT INTO notifications (user_id, role, title, message, link) VALUES (?, ?, ?, ?, ?)");
            foreach ($ids as $uid) {
                $ins->execute([(int)$uid, $role, $title, $message, $link]);
            }
        } else {
            // fallback: إشعار عام للدور فقط
            $ins = $pdo->prepare("INSERT INTO notifications (user_id, role, title, message, link) VALUES (NULL, ?, ?, ?, ?)");
            $ins->execute([$role, $title, $message, $link]);
        }
    } catch (Exception $e) {
        // تجاهل أي خطأ بصمت (حتى لا يوقف الصفحة)
    }
}


// تسليم للسائق (تحديث الحالة)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_to_driver'])) {
    if (!empty($_POST['print_data'])) {
        $totalIds = 0;

        foreach ($_POST['print_data'] as $json) {
            $data = json_decode($json, true);
            // $data['ids'] هي مصفوفة تحتوي على IDs من جدول delivery_log
            $ids_str = implode(',', array_map('intval', $data['ids']));
            $totalIds += is_array($data['ids']) ? count($data['ids']) : 0;

            $pdo->exec("UPDATE delivery_log SET status = 'out_for_delivery' WHERE id IN ($ids_str)");
        }

        $msg = "تم تسليم الاشتراكات المحددة للسائق.";

        // 🔔 إرسال إشعار للسائق + الأدمن (اختياري)
        $notifText = "تم تسليم {$totalIds} طلب(ات) للسائق بتاريخ {$today}.";
        notifyRole($pdo, 'driver', $notifText, 'طلبات جاهزة للتوصيل', 'driver_dashboard.php');
        notifyRole($pdo, 'admin',  $notifText, 'تحديث التسليم', null);
    }
}


// جلب بيانات الاشتراكات (مجمعة حسب العميل)
$grouped = [];
try {
    $sql = "SELECT log.id, u.name as client, p.name as meal, pkg.name as pkg_name, cd.phone_number, cd.address_text
            FROM delivery_log log
            JOIN users u ON log.client_id = u.id
            LEFT JOIN client_details cd ON u.id = cd.user_id
            LEFT JOIN packages pkg ON cd.package_id = pkg.id
            JOIN products p ON log.meal_id = p.id
            WHERE log.delivery_date = ? AND log.status = 'prepared'
            ORDER BY u.id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$today]);

    foreach ($stmt->fetchAll() as $r) {
        $uid = $r['client']; // التجميع بالاسم لسهولة العرض
        if (!isset($grouped[$uid])) {
            $grouped[$uid] = [
                'type' => 'subscription', // نوع الطلب
                'client' => $r['client'],
                'phone' => $r['phone_number'] ?? '---',
                'address' => $r['address_text'] ?? '---',
                'info' => $r['pkg_name'], // في الاشتراكات نعرض اسم الباقة
                'meals' => [],
                'ids' => [] // لتحديث الحالة لاحقاً
            ];
        }
        $grouped[$uid]['meals'][] = $r['meal'];
        $grouped[$uid]['ids'][] = $r['id'];
    }
} catch (Exception $e) {}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تغليف الاشتراكات</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <style>
        /* 🛎️ Notifications UI */
        .notif-wrap{ position:relative; display:inline-block; margin-inline-start:10px; }
        .notif-btn{
            border:0; background:transparent; cursor:pointer;
            position:relative; font-size:20px; padding:6px 10px;
        }
        .notif-badge{
            position:absolute; top:2px; left:6px;
            background:#e74c3c; color:#fff; font-size:12px;
            padding:2px 6px; border-radius:999px; min-width:18px; text-align:center;
            display:none;
        }
        .notif-dropdown{
            display:none;
            position:absolute; top:40px; left:0;
            width:320px; max-height:420px; overflow:auto;
            background:#fff; border-radius:14px;
            box-shadow:0 15px 35px rgba(0,0,0,.15);
            z-index:9999;
            border:1px solid rgba(0,0,0,.06);
        }
        .notif-head{
            padding:12px 14px; font-weight:800;
            border-bottom:1px solid rgba(0,0,0,.06);
            display:flex; justify-content:space-between; align-items:center;
        }
        .notif-item{
            padding:12px 14px; border-bottom:1px solid rgba(0,0,0,.05);
            cursor:pointer;
        }
        .notif-item:hover{ background:#f7f9fc; }
        .notif-item.unread{ background:#ecf6ff; }
        .notif-title{ font-weight:800; font-size:.92rem; margin-bottom:3px; color:#1f2d3d; }
        .notif-msg{ font-size:.86rem; color:#555; line-height:1.4; }
        .notif-time{ font-size:.75rem; color:#888; margin-top:6px; }
        .notif-empty{ padding:18px; text-align:center; color:#777; }
        .notif-close{ font-size:12px; color:#888; cursor:pointer; }
    </style>

    <script>
        function submitAction(url) {
            const form = document.getElementById('prepForm');
            if (document.querySelectorAll('.row-check:checked').length === 0) {
                alert('اختر طلباً واحداً على الأقل.'); return;
            }
            form.target = (url) ? '_blank' : '_self';
            form.action = (url) ? url : '';
            form.submit();
            if(url) { setTimeout(() => { location.reload(); }, 1000); } // تحديث الصفحة بعد الطباعة
        }

        // 🛎️ Notifications JS
        let notifOpen = false;

        function toggleNotif() {
            notifOpen = !notifOpen;
            document.getElementById('notifDropdown').style.display = notifOpen ? 'block' : 'none';
            if (notifOpen) fetchNotifications();
        }

        function closeNotif() {
            notifOpen = false;
            document.getElementById('notifDropdown').style.display = 'none';
        }

        async function fetchNotifications() {
            try {
                const res = await fetch('preparer_dashboard.php?action=fetch_notifications', {cache:'no-store'});
                const data = await res.json();
                if (!data.ok) return;

                const badge = document.getElementById('notifBadge');
                if (data.unread > 0) {
                    badge.style.display = 'inline-block';
                    badge.textContent = data.unread;
                } else {
                    badge.style.display = 'none';
                }

                const list = document.getElementById('notifList');
                list.innerHTML = '';

                if (!data.items || data.items.length === 0) {
                    list.innerHTML = '<div class="notif-empty">لا توجد إشعارات حالياً.</div>';
                    return;
                }

                data.items.forEach(n => {
                    const div = document.createElement('div');
                    div.className = 'notif-item ' + (parseInt(n.is_read) === 0 ? 'unread' : '');
                    div.onclick = () => openNotification(n.id, n.link);

                    const title = (n.title && n.title.trim() !== '') ? n.title : 'إشعار';
                    div.innerHTML = `
                        <div class="notif-title">${escapeHtml(title)}</div>
                        <div class="notif-msg">${escapeHtml(n.message || '')}</div>
                        <div class="notif-time">${escapeHtml(n.created_at || '')}</div>
                    `;
                    list.appendChild(div);
                });

            } catch (e) {}
        }

        async function openNotification(id, link) {
            try {
                const formData = new FormData();
                formData.append('action', 'mark_notification_read');
                formData.append('id', id);

                await fetch('preparer_dashboard.php', { method:'POST', body: formData });
                fetchNotifications();

                if (link && link.trim() !== '') {
                    window.location.href = link;
                }
            } catch (e) {}
        }

        function escapeHtml(str) {
            return (str+'')
              .replaceAll('&','&amp;')
              .replaceAll('<','&lt;')
              .replaceAll('>','&gt;')
              .replaceAll('"','&quot;')
              .replaceAll("'","&#039;");
        }

        // تحديث تلقائي كل 10 ثواني
        setInterval(() => { fetchNotifications(); }, 10000);

        // إغلاق القائمة عند الضغط خارجها
        document.addEventListener('click', (e) => {
            const wrap = document.getElementById('notifWrap');
            if (!wrap) return;
            if (notifOpen && !wrap.contains(e.target)) closeNotif();
        });
    </script>
</head>
<body>
    <?php include 'preparer_sidebar.php'; ?>

    <div class="main-content">
        <header class="top-bar admin-top-bar" style="display:flex; align-items:center; justify-content:space-between;">
            <div class="user-info">📦 تغليف الاشتراكات</div>

            <div style="display:flex; align-items:center; gap:10px;">
                <div><?php echo $today; ?></div>

                <!-- 🛎️ جرس الإشعارات -->
                <div class="notif-wrap" id="notifWrap">
                    <button class="notif-btn" type="button" onclick="toggleNotif()" aria-label="الإشعارات">
                        <i class="fa-solid fa-bell"></i>
                        <span class="notif-badge" id="notifBadge"></span>
                    </button>

                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-head">
                            <span>الإشعارات</span>
                            <span class="notif-close" onclick="closeNotif()">إغلاق</span>
                        </div>
                        <div id="notifList"></div>
                    </div>
                </div>
            </div>
        </header>

        <div class="content-wrapper">
            <?php if(isset($msg)): ?><div class="alert-message alert-success"><?php echo $msg; ?></div><?php endif; ?>

            <form method="POST" id="prepForm">
                <div class="form-card print-hide" style="display:flex; gap:10px; align-items:center; background:#e3f2fd;">
                    <div style="flex:1;">
                        <input type="checkbox" id="selectAll" onchange="document.querySelectorAll('.row-check').forEach(c=>c.checked=this.checked)">
                        <label for="selectAll" style="font-weight:bold; cursor:pointer;">تحديد الكل</label>
                    </div>
                    <button type="button" onclick="submitAction('print_labels.php')" class="btn" style="background:#34495e;"><i class="fas fa-tags"></i> ملصقات</button>
                    <button type="button" onclick="submitAction('print_manifest.php')" class="btn" style="background:#2c3e50;"><i class="fas fa-file-alt"></i> كشف تسليم</button>
                    <button type="submit" name="send_to_driver" class="btn btn-success"><i class="fas fa-truck"></i> تسليم للسائق</button>
                </div>

                <div class="orders-list">
                    <?php if(empty($grouped)): ?>
                        <div class="card" style="text-align:center; padding:40px;">لا توجد اشتراكات جاهزة للتغليف.</div>
                    <?php else: foreach($grouped as $g):
                        // نضع البيانات كاملة في الـ value كـ JSON لتسهيل الطباعة
                        $json_val = htmlspecialchars(json_encode($g));
                    ?>
                    <div class="card" style="display:flex; align-items:center; gap:15px; padding:15px;">
                        <input type="checkbox" name="print_data[]" value="<?php echo $json_val; ?>" class="row-check" style="width:20px; height:20px;">
                        <div style="flex:1;">
                            <h4 style="margin:0;"><?php echo $g['client']; ?> <span style="font-size:0.8rem; color:#666;">(<?php echo $g['info']; ?>)</span></h4>
                            <div style="color:#d35400; font-weight:bold; margin-top:5px;">
                                <?php echo implode(' + ', $g['meals']); ?>
                            </div>
                            <div style="font-size:0.85rem; color:#777; margin-top:5px;">
                                📱 <?php echo $g['phone']; ?> | 📍 <?php echo $g['address']; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </form>
        </div>
    </div>

    <script>
        // أول تحميل
        fetchNotifications();
    </script>
</body>
</html>