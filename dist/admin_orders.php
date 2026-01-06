<?php
// admin_orders.php - لوحة إدارة الطلبات (تصميم مبهر ومحسّن)
// ============================================================================

ob_start();
session_start();
header('Content-Type: text/html; charset=utf-8');
require_once 'auth_admin.php';
require_once 'db_connect.php';

// التحقق من إعداد إخفاء قسم الطلبات
function getSetting(PDO $pdo, string $key, $default = null) {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $default : $v;
    } catch(Exception $e) {
        return $default;
    }
}

$orders_section_enabled = getSetting($pdo, 'orders_section_enabled', '1');
if ($orders_section_enabled !== '1') {
    header("Location: admin_dashboard.php?error=orders_disabled");
    exit;
}

// --- API المعالجة ---
if (isset($_GET['action'])) {
    ob_clean(); header('Content-Type: application/json');
    
    if ($_GET['action'] == 'fetch_orders') {
        $filter = $_GET['filter'] ?? 'pending';
        $sql = "SELECT * FROM individual_orders WHERE status = ? ORDER BY created_at DESC";
        
        if ($filter == 'history') {
            $sql = "SELECT * FROM individual_orders WHERE status IN ('delivered', 'picked_up', 'cancelled') ORDER BY created_at DESC LIMIT 50";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$filter]);
        }
        
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($orders as &$ord) {
            $ord['time_ago'] = time_elapsed_string($ord['created_at']);
            $ord['type_txt'] = (strpos($ord['address'], 'فرع') !== false) ? 'استلام من الفرع' : 'توصيل';
            
            $pay = strtolower($ord['payment_method']);
            if ($pay == 'cash') $ord['pay_txt'] = 'كاش';
            elseif ($pay == 'card') $ord['pay_txt'] = 'شبكة';
            elseif ($pay == 'bank transfer') $ord['pay_txt'] = 'تحويل';
            else $ord['pay_txt'] = $ord['payment_method'];
        }
        echo json_encode(['status' => 'success', 'orders' => $orders]);
        exit;
    }

    if ($_GET['action'] == 'update_status') {
        $stmt = $pdo->prepare("UPDATE individual_orders SET status = ? WHERE id = ?");
        if ($stmt->execute([$_POST['status'], $_POST['id']])) echo json_encode(['status' => 'success']);
        else echo json_encode(['status' => 'error']);
        exit;
    }

    if ($_GET['action'] == 'get_details') {
        $stmt = $pdo->prepare("SELECT i.*, p.name as meal_name FROM individual_order_items i LEFT JOIN products p ON i.meal_id = p.id WHERE i.order_id = ?");
        $stmt->execute([$_GET['id']]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($items as &$item) {
            if(empty($item['meal_name'])) $item['meal_name'] = 'وجبة غير معروفة';
            $item['options_txt'] = '';
            if(!empty($item['options_json'])) {
                $dec = json_decode($item['options_json'], true);
                $opts = [];
                if(isset($dec['size_name'])) $opts[] = $dec['size_name'];
                if(isset($dec['options'])) foreach($dec['options'] as $o) $opts[] = $o['name'];
                $item['options_txt'] = implode(' | ', $opts);
            }
        }
        echo json_encode(['status' => 'success', 'items' => $items]);
        exit;
    }
}

function time_elapsed_string($datetime) {
    $diff = (new DateTime)->diff(new DateTime($datetime));
    if ($diff->d > 0) return $diff->d . ' يوم';
    if ($diff->h > 0) return $diff->h . ' ساعة';
    if ($diff->i > 0) return $diff->i . ' دقيقة';
    return 'الآن';
}
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الطلبات - لوحة التحكم</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="admin_colors.php">
    <link rel="stylesheet" href="admin-unified-style-v2.css">
    <style>
        :root {
            --primary: #6c5ce7;
            --primary-dark: #5b4cc4;
            --primary-light: #a29bfe;
            --gradient: linear-gradient(135deg, #6c5ce7 0%, #4b3cff 100%);
            --bg: #f6f7ff;
            --surface: #ffffff;
            --text-main: #1f2937;
            --text-sec: #6b7280;
            --border: rgba(15, 23, 42, 0.08);
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
        }

        body {
            background: var(--bg);
            font-family: 'Tajawal', sans-serif;
            margin: 0;
            padding: 0;
            color: var(--text-main);
        }

        /* === Layout === */
        .admin-main-content {
            margin-right: 280px;
            min-height: 100vh;
            padding: var(--admin-spacing-lg);
        }

        /* === Header === */
        .page-header {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--admin-radius-lg);
            box-shadow: var(--admin-shadow-sm);
            padding: var(--admin-spacing-lg);
            margin-bottom: var(--admin-spacing-lg);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: var(--admin-spacing-md);
            flex-wrap: wrap;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: var(--admin-spacing-md);
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 1000;
            background: var(--gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .live-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(16, 185, 129, 0.1);
            padding: 8px 16px;
            border-radius: 50px;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .live-dot {
            width: 10px;
            height: 10px;
            background: var(--success);
            border-radius: 50%;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(1.1); }
        }

        .live-text {
            font-weight: 800;
            font-size: 0.9rem;
            color: var(--success);
        }

        .header-actions {
            display: flex;
            gap: var(--admin-spacing-sm);
        }

        .btn-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: var(--surface);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            color: var(--text-sec);
            font-size: 1.1rem;
        }

        .btn-icon:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(108, 92, 231, 0.3);
        }

        /* === Stats Cards === */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--admin-spacing-md);
            margin-bottom: var(--admin-spacing-lg);
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--admin-radius-md);
            padding: var(--admin-spacing-lg);
            box-shadow: var(--admin-shadow-sm);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 4px;
            height: 100%;
            background: var(--primary);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--admin-shadow);
            border-color: var(--primary-light);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--admin-spacing-sm);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: rgba(108, 92, 231, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 1.5rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 1000;
            color: var(--text-main);
            line-height: 1;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-sec);
            font-weight: 700;
            margin-top: var(--admin-spacing-xs);
        }

        /* === Tabs === */
        .tabs-container {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--admin-radius-md);
            box-shadow: var(--admin-shadow-sm);
            padding: var(--admin-spacing-md);
            margin-bottom: var(--admin-spacing-lg);
            display: flex;
            gap: var(--admin-spacing-sm);
            flex-wrap: wrap;
        }

        .tab-btn {
            background: transparent;
            border: 1px solid transparent;
            padding: 12px 24px;
            border-radius: 12px;
            cursor: pointer;
            color: var(--text-sec);
            font-weight: 700;
            font-size: 0.95rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            position: relative;
        }

        .tab-btn:hover {
            background: rgba(108, 92, 231, 0.05);
            color: var(--text-main);
        }

        .tab-btn.active {
            background: var(--gradient);
            color: white;
            border-color: transparent;
            box-shadow: 0 4px 12px rgba(108, 92, 231, 0.3);
        }

        .tab-btn .badge {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 800;
        }

        .tab-btn:not(.active) .badge {
            background: var(--danger);
            color: white;
        }

        /* === Orders Grid === */
        .orders-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: var(--admin-spacing-lg);
        }

        /* === Order Card === */
        .order-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--admin-radius-md);
            padding: var(--admin-spacing-lg);
            transition: all 0.3s;
            position: relative;
            display: flex;
            flex-direction: column;
            gap: var(--admin-spacing-md);
            box-shadow: var(--admin-shadow-sm);
            overflow: hidden;
        }

        .order-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            left: 0;
            height: 4px;
            background: var(--gradient);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .order-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--admin-shadow-hover);
            border-color: var(--primary-light);
        }

        .order-card:hover::before {
            opacity: 1;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: var(--admin-spacing-md);
            border-bottom: 1px dashed var(--border);
        }

        .order-ref {
            font-weight: 1000;
            font-size: 1.25rem;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .order-time {
            font-size: 0.85rem;
            background: rgba(108, 92, 231, 0.1);
            padding: 6px 12px;
            border-radius: 8px;
            color: var(--primary);
            font-weight: 700;
        }

        .card-body {
            display: flex;
            flex-direction: column;
            gap: var(--admin-spacing-sm);
            flex: 1;
        }

        .info-row {
            display: flex;
            align-items: center;
            gap: var(--admin-spacing-sm);
            font-size: 0.95rem;
            color: var(--text-main);
        }

        .icon-box {
            width: 36px;
            height: 36px;
            background: rgba(108, 92, 231, 0.1);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 1rem;
            flex-shrink: 0;
        }

        .info-label {
            font-weight: 700;
            color: var(--text-main);
            flex: 1;
        }

        .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: var(--admin-spacing-md);
            margin-top: auto;
            border-top: 1px dashed var(--border);
        }

        .price-tag {
            font-weight: 1000;
            font-size: 1.5rem;
            background: var(--gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .card-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--admin-spacing-sm);
            margin-top: var(--admin-spacing-sm);
        }

        .btn {
            border: none;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 0.9rem;
            font-weight: 800;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.2s;
            font-family: 'Tajawal', sans-serif;
        }

        .btn-main {
            background: var(--gradient);
            color: white;
            box-shadow: 0 4px 12px rgba(108, 92, 231, 0.3);
        }

        .btn-main:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(108, 92, 231, 0.4);
        }

        .btn-sec {
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text-main);
        }

        .btn-sec:hover {
            background: rgba(108, 92, 231, 0.05);
            border-color: var(--primary-light);
            color: var(--primary);
        }

        .btn-success {
            background: var(--success);
            color: white;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover {
            background: #059669;
            transform: translateY(-2px);
        }

        /* === Empty State === */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: var(--text-sec);
            grid-column: 1/-1;
        }

        .empty-state i {
            font-size: 4rem;
            opacity: 0.2;
            margin-bottom: var(--admin-spacing-md);
        }

        .empty-state h3 {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-main);
            margin-bottom: var(--admin-spacing-sm);
        }

        /* === Toast === */
        #toast-container {
            position: fixed;
            bottom: 30px;
            left: 30px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .toast {
            background: var(--surface);
            border-right: 5px solid var(--success);
            padding: 16px 24px;
            border-radius: 12px;
            min-width: 320px;
            box-shadow: var(--admin-shadow);
            display: flex;
            align-items: flex-start;
            gap: 16px;
            animation: slideIn 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            transition: all 0.3s;
            transform: translateX(0);
            opacity: 1;
        }

        .toast.hide {
            transform: translateX(-100%);
            opacity: 0;
        }

        .toast-icon {
            font-size: 1.5rem;
            color: var(--success);
            margin-top: 2px;
        }

        .toast-content {
            flex: 1;
        }

        .toast-title {
            font-weight: 800;
            font-size: 1rem;
            margin-bottom: 4px;
            display: block;
            color: var(--text-main);
        }

        .toast-msg {
            font-size: 0.9rem;
            color: var(--text-sec);
            line-height: 1.4;
        }

        @keyframes slideIn {
            from {
                transform: translateX(-50px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* === Modal === */
        .modal {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background: white;
            width: 95%;
            max-width: 500px;
            border-radius: var(--admin-radius-lg);
            padding: 0;
            box-shadow: var(--admin-shadow-hover);
            overflow: hidden;
            animation: popIn 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        @keyframes popIn {
            from {
                transform: scale(0.9);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 20px 25px;
            background: linear-gradient(135deg, rgba(108, 92, 231, 0.1) 0%, rgba(75, 60, 255, 0.05) 100%);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-weight: 1000;
            font-size: 1.3rem;
            background: var(--gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .close-icon {
            cursor: pointer;
            color: var(--text-sec);
            transition: 0.2s;
            font-size: 1.3rem;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
        }

        .close-icon:hover {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .modal-body {
            padding: 25px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .item-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid rgba(15, 23, 42, 0.05);
            align-items: center;
        }

        .item-row:last-child {
            border-bottom: none;
        }

        .item-name {
            font-weight: 800;
            color: var(--text-main);
            font-size: 1rem;
        }

        .item-meta {
            font-size: 0.85rem;
            color: var(--text-sec);
            margin-top: 4px;
        }

        .item-qty {
            font-weight: 800;
            background: rgba(108, 92, 231, 0.1);
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.9rem;
            color: var(--primary);
        }

        @media (max-width: 992px) {
            .admin-main-content {
                margin-right: 0;
            }
        }

        @media (max-width: 768px) {
            .orders-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="admin-main-content">
    <audio id="alertSound" src="https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3"></audio>

    <div class="page-header">
        <div class="header-left">
            <h1 class="page-title">
                <i class="fas fa-clipboard-list"></i>
                إدارة الطلبات
            </h1>
            <div class="live-indicator">
                <div class="live-dot"></div>
                <span class="live-text">مباشر</span>
            </div>
        </div>
        <div class="header-actions">
            <button class="btn-icon" onclick="location.reload()" title="تحديث">
                <i class="fas fa-sync-alt"></i>
            </button>
            <a href="admin_dashboard.php" class="btn-icon" title="الرئيسية">
                <i class="fas fa-home"></i>
            </a>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon"><i class="fas fa-inbox"></i></div>
            </div>
            <div class="stat-value" id="stat-pending">0</div>
            <div class="stat-label">طلبات جديدة</div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon"><i class="fas fa-fire"></i></div>
            </div>
            <div class="stat-value" id="stat-prepared">0</div>
            <div class="stat-label">قيد التجهيز</div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon"><i class="fas fa-motorcycle"></i></div>
            </div>
            <div class="stat-value" id="stat-delivery">0</div>
            <div class="stat-label">جاري التوصيل</div>
        </div>
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            </div>
            <div class="stat-value" id="stat-completed">0</div>
            <div class="stat-label">مكتملة</div>
        </div>
    </div>

    <div class="tabs-container">
        <div class="tab-btn active" onclick="switchTab('pending', this)">
            <i class="fas fa-inbox"></i>
            جديدة
            <span class="badge" id="c-pending">0</span>
        </div>
        <div class="tab-btn" onclick="switchTab('prepared', this)">
            <i class="fas fa-fire"></i>
            قيد التجهيز
        </div>
        <div class="tab-btn" onclick="switchTab('out_for_delivery', this)">
            <i class="fas fa-motorcycle"></i>
            جاري التوصيل
        </div>
        <div class="tab-btn" onclick="switchTab('history', this)">
            <i class="fas fa-history"></i>
            الأرشيف
        </div>
    </div>

    <div id="orders-container" class="orders-grid">
        <div class="empty-state">
            <i class="fas fa-spinner fa-spin"></i>
            <h3>جاري التحميل...</h3>
        </div>
    </div>
</div>

<div id="toast-container"></div>

<div id="detailsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-title">تفاصيل الطلب <span id="mId" style="color:var(--primary)"></span></div>
            <div class="close-icon" onclick="$('#detailsModal').fadeOut()">
                <i class="fas fa-times"></i>
            </div>
        </div>
        <div class="modal-body">
            <div id="mItemsList" style="margin-bottom:20px;"></div>
            <div style="background:rgba(108, 92, 231, 0.05); padding:20px; border-radius:12px; border:1px solid rgba(108, 92, 231, 0.1);">
                <div style="font-size:0.85rem; color:var(--text-sec); font-weight:800; margin-bottom:8px; text-transform:uppercase;">العنوان / الاستلام</div>
                <div id="mAddressText" style="font-weight:800; color:var(--text-main); font-size:1rem; margin-bottom:20px; line-height:1.5;">--</div>
                <div style="display:flex; gap:12px;">
                    <a id="mMapBtn" href="#" target="_blank" class="btn btn-sec" style="flex:1; display:none;">
                        <i class="fas fa-map-marked-alt"></i> الموقع
                    </a>
                    <a id="mCallBtn" href="#" class="btn btn-success" style="flex:1;">
                        <i class="fas fa-phone"></i> اتصال
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    let currentTab = 'pending';
    let lastOrderCount = 0;

    function showToast(title, message) {
        const toastHTML = `
            <div class="toast">
                <div class="toast-icon"><i class="fas fa-check-circle"></i></div>
                <div class="toast-content">
                    <span class="toast-title">${title}</span>
                    <span class="toast-msg">${message}</span>
                </div>
            </div>
        `;
        const $toast = $(toastHTML);
        $('#toast-container').append($toast);
        
        setTimeout(() => {
            $toast.addClass('hide');
            setTimeout(() => $toast.remove(), 300);
        }, 4000);
    }

    function fetchOrders() {
        $.ajax({
            url: 'admin_orders.php',
            type: 'GET',
            data: { action: 'fetch_orders', filter: currentTab },
            dataType: 'json',
            success: function(res) {
                if(res.status === 'success') {
                    renderOrders(res.orders);
                    updateStats();
                    if(currentTab === 'pending' && res.orders.length > lastOrderCount && lastOrderCount !== 0) {
                        document.getElementById('alertSound').play().catch(()=>{});
                        showToast('طلب جديد!', 'وصل طلب جديد للقائمة');
                    }
                    if(currentTab === 'pending') lastOrderCount = res.orders.length;
                }
            }
        });
    }

    function updateStats() {
        ['pending', 'prepared', 'out_for_delivery'].forEach(status => {
            $.ajax({
                url: 'admin_orders.php',
                type: 'GET',
                data: { action: 'fetch_orders', filter: status },
                dataType: 'json',
                success: function(res) {
                    if(res.status === 'success') {
                        const count = res.orders.length;
                        if(status === 'pending') $('#stat-pending').text(count);
                        else if(status === 'prepared') $('#stat-prepared').text(count);
                        else if(status === 'out_for_delivery') $('#stat-delivery').text(count);
                    }
                }
            });
        });
        
        $.ajax({
            url: 'admin_orders.php',
            type: 'GET',
            data: { action: 'fetch_orders', filter: 'history' },
            dataType: 'json',
            success: function(res) {
                if(res.status === 'success') {
                    $('#stat-completed').text(res.orders.length);
                }
            }
        });
    }

    function renderOrders(orders) {
        const container = $('#orders-container');
        container.empty();
        
        if(orders.length === 0) {
            container.html(`
                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <h3>لا توجد طلبات</h3>
                    <p>لا توجد طلبات في هذا القسم حالياً</p>
                </div>
            `);
            return;
        }
        
        orders.forEach(ord => {
            let actionBtn = '';
            if(currentTab == 'pending')
                actionBtn = `<button class="btn btn-main" onclick="updSt(${ord.id},'prepared')" style="width:100%; margin-top:12px;"><i class="fas fa-fire"></i> بدء التجهيز</button>`;
            else if(currentTab == 'prepared')
                actionBtn = `<button class="btn btn-main" onclick="updSt(${ord.id},'out_for_delivery')" style="width:100%; margin-top:12px;"><i class="fas fa-motorcycle"></i> بدء التوصيل</button>`;
            else if(currentTab == 'out_for_delivery')
                actionBtn = `<button class="btn btn-success" onclick="updSt(${ord.id},'delivered')" style="width:100%; margin-top:12px;"><i class="fas fa-check"></i> تأكيد التسليم</button>`;

            let typeColor = ord.type_txt === 'استلام من الفرع' ? '#f59e0b' : '#3b82f6';
            let typeIcon = ord.type_txt === 'استلام من الفرع' ? 'fa-store' : 'fa-truck';

            container.append(`
                <div class="order-card">
                    <div class="card-header">
                        <span class="order-ref">#${ord.id}</span>
                        <span class="order-time">${ord.time_ago}</span>
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <div class="icon-box"><i class="fas fa-user"></i></div>
                            <span class="info-label">${ord.customer_name}</span>
                        </div>
                        <div class="info-row">
                            <div class="icon-box"><i class="fas fa-wallet"></i></div>
                            <span class="info-label">${ord.pay_txt}</span>
                        </div>
                        <div class="info-row">
                            <div class="icon-box" style="color:${typeColor}; background:${typeColor}20;"><i class="fas ${typeIcon}"></i></div>
                            <span class="info-label" style="color:${typeColor};">${ord.type_txt}</span>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="price-tag">${parseFloat(ord.total_price).toFixed(2)} ر.س</div>
                    </div>
                    <div class="card-actions">
                        <button class="btn btn-sec" onclick="showModalDetails('${ord.id}', \`${ord.address}\`, '${ord.customer_phone}')">
                            <i class="far fa-eye"></i> تفاصيل
                        </button>
                        <a href="admin_order_details.php?id=${ord.id}" class="btn btn-sec">
                            <i class="fas fa-file-invoice"></i> فاتورة
                        </a>
                    </div>
                    ${actionBtn}
                </div>
            `);
        });
    }

    function showModalDetails(id, fullAddress, phone) {
        $('#mId').text('#' + id);
        $('#mCallBtn').attr('href', 'tel:' + phone);
        let mapLink = '#';
        let addressText = fullAddress;
        let urlRegex = /(https?:\/\/[^\s]+)/g;
        let match = fullAddress.match(urlRegex);
        if (match) {
            mapLink = match[0];
            addressText = fullAddress.replace(mapLink, '').trim();
            $('#mMapBtn').attr('href', mapLink).show();
        } else {
            $('#mMapBtn').hide();
        }
        addressText = addressText.replace(/موقع:|رابط:|تفاصيل:/g, '').trim();
        if(addressText === '') addressText = 'الموقع مرفق بالخريطة';
        $('#mAddressText').text(addressText);

        $.get('admin_orders.php?action=get_details', {id: id}, function(res){
            let h = '';
            res.items.forEach(i => {
                h += `
                    <div class="item-row">
                        <div>
                            <div class="item-name">${i.meal_name}</div>
                            <div class="item-meta">${i.options_txt || 'بدون إضافات'}</div>
                        </div>
                        <div class="item-qty">x${i.quantity}</div>
                    </div>
                `;
            });
            $('#mItemsList').html(h);
            $('#detailsModal').fadeIn().css('display','flex');
        }, 'json');
    }

    function updSt(id, st) {
        if(confirm('تأكيد تغيير الحالة؟')) {
            $.post('admin_orders.php?action=update_status', {id:id, status:st}, function(res){
                if(res.status === 'success') {
                    showToast('تم بنجاح', 'تم تحديث حالة الطلب');
                    fetchOrders();
                    updateStats();
                }
            },'json');
        }
    }
    
    function switchTab(t, b) {
        currentTab = t;
        $('.tab-btn').removeClass('active');
        $(b).addClass('active');
        fetchOrders();
    }

    setInterval(fetchOrders, 5000);
    fetchOrders();
    updateStats();
</script>
</body>
</html>
