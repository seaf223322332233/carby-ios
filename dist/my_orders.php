<?php
// my_orders.php - نسخة تطبيق احترافية (UI حديث + Dark Mode مرتبط + Notifications من جدول notifications (بدون تعديل DB) + API آمن)
// =====================================================================================================================

ob_start();
header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');

if (session_status() == PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

require_once 'db_connect.php';

$client_id = (int)$_SESSION['user_id'];
$today     = date('Y-m-d');

// ======================================================================
// Helpers
// ======================================================================
function safeStr($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function statusMeta($status, $isPickup = false){
    $status = (string)$status;

    $map = [
        'pending'          => ['txt'=>'قيد المراجعة',   'cls'=>'st-pending', 'icon'=>'fa-clock'],
        'planned'          => ['txt'=>'مجدول',          'cls'=>'st-pending', 'icon'=>'fa-calendar-check'],
        'cooking'          => ['txt'=>'جاري التجهيز',   'cls'=>'st-prep',    'icon'=>'fa-fire'],
        'prepared'         => ['txt'=>'تم التجهيز',     'cls'=>'st-prep',    'icon'=>'fa-fire'],
        'out_for_delivery' => ['txt'=>'خرج للتوصيل',    'cls'=>'st-dlv',     'icon'=>'fa-motorcycle'],
        'ready_for_pickup' => ['txt'=>'جاهز للاستلام',  'cls'=>'st-dlv',     'icon'=>'fa-store'],
        'delivered'        => ['txt'=>'مكتمل',          'cls'=>'st-done',    'icon'=>'fa-check'],
        'picked_up'        => ['txt'=>'تم الاستلام',    'cls'=>'st-done',    'icon'=>'fa-check'],
        'cancelled'        => ['txt'=>'ملغي',           'cls'=>'st-cancel',  'icon'=>'fa-times'],
    ];

    $m = $map[$status] ?? ['txt'=>'قيد المعالجة', 'cls'=>'st-pending', 'icon'=>'fa-circle-notch'];
    if ($isPickup && $status === 'out_for_delivery') {
        $m = ['txt'=>'جاهز بالفرع', 'cls'=>'st-dlv', 'icon'=>'fa-store'];
    }
    return $m;
}

function getStep($status) {
    if ($status == 'pending' || $status == 'planned') return 1;
    if ($status == 'prepared' || $status == 'cooking') return 2;
    if ($status == 'out_for_delivery' || $status == 'ready_for_pickup') return 3;
    if ($status == 'delivered' || $status == 'picked_up') return 4;
    if ($status == 'cancelled') return 0;
    return 1;
}

// ======================================================================
// API 1: Secure order details - ?get_details=1&order_id=XX
// ======================================================================
if (isset($_GET['get_details']) && isset($_GET['order_id'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $oid = (int)$_GET['order_id'];

    try {
        // أمان: الطلب لازم يكون لنفس المستخدم (user_id موجود عندك)
        // ملاحظة: بعض قواعد البيانات قد تحتوي address أو address_text. نرجعهم الاثنين ونستخدم fallback في JS.
        $stmt = $pdo->prepare("SELECT * FROM individual_orders WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$oid, $client_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            echo json_encode(['status'=>'error','message'=>'الطلب غير موجود أو غير مصرح']); exit;
        }

        $stmt_items = $pdo->prepare("
            SELECT i.*, p.name as meal_name, p.image
            FROM individual_order_items i
            LEFT JOIN products p ON i.meal_id = p.id
            WHERE i.order_id = ?
            ORDER BY i.id ASC
        ");
        $stmt_items->execute([$oid]);
        $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

        // تحديد نوع الطلب Pickup
        $addrA = (string)($order['address'] ?? '');
        $addrB = (string)($order['address_text'] ?? '');
        $addr  = $addrA ?: $addrB;

        $is_pickup = false;
        if (!empty($order['order_type'])) {
            $is_pickup = (strtolower((string)$order['order_type']) === 'pickup');
        } else {
            // fallback by text
            $is_pickup = (mb_stripos($addr, 'فرع') !== false || mb_stripos($addr, 'استلام') !== false);
        }

        foreach ($items as &$item) {
            if (empty($item['meal_name'])) $item['meal_name'] = "وجبة (مؤرشفة)";
            $item['size_txt'] = '';
            $item['opts_txt'] = '';

            if (!empty($item['options_json'])) {
                $decoded = json_decode($item['options_json'], true);
                if (is_array($decoded)) {
                    if (!empty($decoded['size_name'])) $item['size_txt'] = (string)$decoded['size_name'];
                    if (!empty($decoded['options']) && is_array($decoded['options'])) {
                        $opt_names = array_column($decoded['options'], 'name');
                        $opt_names = array_filter($opt_names);
                        $item['opts_txt'] = implode(' + ', $opt_names);
                    }
                }
            }

            $img = (string)($item['image'] ?? '');
            if ($img && strpos($img, 'http') !== 0) $img = 'uploads/'.$img;
            if (!$img) $img = 'uploads/logo.png';
            $item['image'] = $img;
        }

        echo json_encode([
            'status'    => 'success',
            'order'     => $order,
            'items'     => $items,
            'is_pickup' => $is_pickup
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode(['status'=>'error','message'=>'حدث خطأ غير متوقع']); exit;
    }
}

// ======================================================================
// API 2: Notifications poll - ?notif_poll=1 (بدون تعديل DB)
// notifications: id, client_id, message, created_at, is_read
// ======================================================================
if (isset($_GET['notif_poll'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $out = ['status'=>'success', 'unread_count'=>0, 'unread'=>[], 'latest'=>[]];

    try {
        $stmtC = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE client_id = ? AND is_read = 0");
        $stmtC->execute([$client_id]);
        $out['unread_count'] = (int)$stmtC->fetchColumn();

        $stmtU = $pdo->prepare("
            SELECT id, message, created_at, is_read
            FROM notifications
            WHERE client_id = ? AND is_read = 0
            ORDER BY id DESC
            LIMIT 5
        ");
        $stmtU->execute([$client_id]);
        $out['unread'] = $stmtU->fetchAll(PDO::FETCH_ASSOC);

        $stmtL = $pdo->prepare("
            SELECT id, message, created_at, is_read
            FROM notifications
            WHERE client_id = ?
            ORDER BY id DESC
            LIMIT 10
        ");
        $stmtL->execute([$client_id]);
        $out['latest'] = $stmtL->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($out);
        exit;

    } catch (Exception $e) {
        echo json_encode(['status'=>'error','message'=>'failed']);
        exit;
    }
}

// ======================================================================
// API 3: mark notification read - ?notif_read=ID
// ======================================================================
if (isset($_GET['notif_read'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $nid = (int)$_GET['notif_read'];
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND client_id = ?");
        $stmt->execute([$nid, $client_id]);
        echo json_encode(['status'=>'success']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status'=>'error']);
        exit;
    }
}

// ======================================================================
// Normal Page Data
// ======================================================================

// 1) Subscription meal (today)
$sub_meal = null;
$meal_source = '';
try {
    $sql_log = "SELECT log.*, p.name as meal_name, p.image_url, p.image
                FROM delivery_log log
                JOIN products p ON log.meal_id = p.id
                WHERE log.client_id = ? AND log.delivery_date = ?
                LIMIT 1";
    $stmt = $pdo->prepare($sql_log);
    $stmt->execute([$client_id, $today]);
    $sub_meal = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($sub_meal) {
        $meal_source = 'log';
    } else {
        // fallback: plan
        $w = date('w');
        $week_start = ($w == 6) ? date('Y-m-d') : date('Y-m-d', strtotime('last saturday'));
        $day_name = date('l');

        $sql_plan = "SELECT p.name as meal_name, p.image_url, p.image
                     FROM plan_days pd
                     JOIN plan_day_items pdi ON pd.id = pdi.plan_day_id
                     JOIN products p ON pdi.meal_id = p.id
                     WHERE pd.client_id = ? AND pd.week_start_date = ? AND pd.day_name = ?
                     LIMIT 1";
        $stmt2 = $pdo->prepare($sql_plan);
        $stmt2->execute([$client_id, $week_start, $day_name]);
        $sub_meal = $stmt2->fetch(PDO::FETCH_ASSOC);

        if ($sub_meal) {
            $meal_source = 'plan';
            $sub_meal['status'] = 'pending';
        }
    }
} catch (Exception $e) { $sub_meal = null; }

// 2) Menu orders (أفضل: user_id مباشرة)
$menu_orders = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM individual_orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 15");
    $stmt->execute([$client_id]);
    $menu_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $menu_orders = []; }

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
    <title>طلباتي</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary:#6c5ce7; --primary2:#8e44ad; --accent:#00d2d3;

            --bg:#f7f8ff; --surface:#ffffff;
            --text-main:#1f2937; --text-sub:#6b7280;

            --card-shadow:0 14px 40px rgba(17,24,39,.08);
            --soft-shadow:0 10px 25px rgba(17,24,39,.06);
            --border:rgba(17,24,39,.07);

            --glass:rgba(255,255,255,.70);
            --glass2:rgba(255,255,255,.45);

            --danger:#ff7675; --success:#22c55e; --warning:#f59e0b; --info:#38bdf8;
        }
        body[data-theme="dark"]{
            --bg:#0b1220; --surface:#111b2f;
            --text-main:#f8fafc; --text-sub:#94a3b8;
            --card-shadow:0 18px 55px rgba(0,0,0,.45);
            --soft-shadow:0 14px 35px rgba(0,0,0,.35);
            --border:rgba(255,255,255,.08);
            --glass:rgba(17,27,47,.75);
            --glass2:rgba(17,27,47,.55);
        }

        *{ box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
        body{
            margin:0; font-family:'Tajawal',sans-serif;
            background:var(--bg); color:var(--text-main);
            padding-bottom:115px; overflow-x:hidden;
        }
        a{ text-decoration:none; color:inherit; }
        .container{ max-width:720px; margin:0 auto; padding:0 16px 18px; }

        /* Topbar */
        .topbar{
            position:sticky; top:0; z-index:1200;
            padding:16px 14px 14px;
            background: color-mix(in srgb, var(--bg) 92%, transparent);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-bottom:1px solid var(--border);
        }
        .toprow{ display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .backbtn,.action-btn{
            width:46px; height:46px; border-radius:16px;
            display:flex; align-items:center; justify-content:center;
            background:var(--surface); border:1px solid var(--border);
            box-shadow:var(--soft-shadow);
            position:relative;
        }
        .backbtn:active,.action-btn:active{ transform:scale(.98); }
        .titlebox{ flex:1; padding:2px 8px; }
        .maintitle{ margin:0; font-size:1.12rem; font-weight:900; }
        .subtitle{ margin-top:4px; font-size:.80rem; font-weight:800; color:var(--text-sub); }
        .actions{ display:flex; gap:10px; }
        .notif-dot{
            position:absolute; top:9px; right:9px;
            width:11px; height:11px; background:var(--danger);
            border-radius:50%; border:2px solid var(--surface);
            display:none;
        }

        /* Sections */
        .section{ margin-top:16px; }
        .section-head{
            display:flex; justify-content:space-between; align-items:center;
            margin:10px 2px 12px;
        }
        .section-title{
            margin:0; font-size:1.02rem; font-weight:900;
            display:flex; align-items:center; gap:10px;
        }
        .section-link{ color:var(--primary); font-weight:900; font-size:.85rem; }

        .card{
            background:var(--surface);
            border:1px solid var(--border);
            box-shadow:var(--card-shadow);
            border-radius:26px;
            padding:16px;
            overflow:hidden;
            position:relative;
        }
        .card-soft{ box-shadow:var(--soft-shadow); }

        /* Subscription meal card */
        .meal-head{
            display:flex; gap:14px; align-items:center;
            padding-bottom:14px;
            border-bottom:1px dashed color-mix(in srgb, var(--border) 70%, transparent);
            margin-bottom:14px;
        }
        .meal-img{
            width:82px; height:82px; border-radius:20px;
            object-fit:cover;
            border:1px solid var(--border);
            background: color-mix(in srgb, var(--surface) 70%, #000 30%);
            flex-shrink:0;
        }
        .meal-name{ font-weight:900; font-size:1.02rem; }
        .meal-chiprow{ margin-top:8px; display:flex; gap:8px; flex-wrap:wrap; }
        .chip{
            display:inline-flex; align-items:center; gap:7px;
            padding:7px 10px; border-radius:999px;
            border:1px solid var(--border);
            background:var(--glass2);
            font-weight:900; font-size:.74rem;
        }
        .chip i{ color:var(--primary); }

        /* Tracker */
        .tracker{ position:relative; padding:10px 8px 2px; }
        .track-line,.track-fill{
            position:absolute; top:20px; right:10%;
            width:80%; height:5px; border-radius:999px;
        }
        .track-line{ background: color-mix(in srgb, var(--text-sub) 18%, transparent); }
        .track-fill{ background: var(--success); width:0%; transition:.6s ease; }
        .steps{ display:flex; justify-content:space-between; position:relative; z-index:1; }
        .step{ width:25%; text-align:center; }
        .step .c{
            width:36px; height:36px; border-radius:50%;
            margin:0 auto 8px;
            display:flex; align-items:center; justify-content:center;
            border:4px solid color-mix(in srgb, var(--text-sub) 18%, transparent);
            background:var(--surface); color:var(--text-sub);
            transition:.25s;
        }
        .step.active .c{ border-color:var(--success); background:var(--success); color:#fff; }
        .step .l{ font-size:.72rem; font-weight:900; color: color-mix(in srgb, var(--text-sub) 88%, transparent); }
        .step.active .l{ color:var(--text-main); }

        /* Orders */
        .order-row{ display:flex; justify-content:space-between; align-items:center; gap:12px; cursor:pointer; }
        .order-meta{ display:flex; flex-direction:column; gap:6px; min-width:0; }
        .order-id{ font-weight:900; font-size:1rem; }
        .order-date{ font-weight:800; font-size:.78rem; color:var(--text-sub); }
        .order-price{ font-weight:900; color:var(--primary); font-size:.95rem; }

        .status-badge{
            padding:8px 12px; border-radius:999px;
            font-size:.78rem; font-weight:900;
            display:flex; align-items:center; gap:8px;
            min-width:118px; justify-content:center;
            border:1px solid var(--border);
            background:var(--glass2);
        }
        .st-pending{ color:#b45309; background: color-mix(in srgb, #f59e0b 20%, var(--surface) 80%); }
        .st-prep{ color:#1d4ed8; background: color-mix(in srgb, #3b82f6 18%, var(--surface) 82%); }
        .st-dlv{ color:#0f766e; background: color-mix(in srgb, #14b8a6 16%, var(--surface) 84%); }
        .st-done{ color:#166534; background: color-mix(in srgb, #22c55e 16%, var(--surface) 84%); }
        .st-cancel{ color:#991b1b; background: color-mix(in srgb, #ef4444 14%, var(--surface) 86%); }

        /* Modal bottom sheet */
        .modal{
            position:fixed; inset:0;
            background: rgba(0,0,0,.46);
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
            z-index:9999;
            display:none;
            align-items:flex-end;
        }
        .sheet{
            width:100%; max-width:720px; margin:0 auto;
            background:var(--surface);
            border-radius:26px 26px 0 0;
            border:1px solid var(--border);
            box-shadow:0 -20px 60px rgba(0,0,0,.25);
            max-height:86vh;
            overflow:auto;
            padding:18px 18px 90px;
            animation: up .25s ease-out;
        }
        @keyframes up{ from{ transform: translateY(18%);} to{ transform: translateY(0);} }
        .sheet-head{
            position:sticky; top:0; z-index:10;
            background:var(--surface);
            padding:4px 0 12px;
            border-bottom:1px solid var(--border);
            display:flex; justify-content:space-between; align-items:center;
        }
        .sheet-title{ font-weight:900; font-size:1.1rem; color:var(--primary); }
        .close{
            width:42px; height:42px; border-radius:16px;
            background:var(--glass2); border:1px solid var(--border);
            display:flex; align-items:center; justify-content:center;
            cursor:pointer;
        }
        .detail-row{
            display:flex; justify-content:space-between; gap:10px;
            padding:10px 2px; font-weight:800;
        }
        .d-label{ color:var(--text-sub); }
        .d-val{ color:var(--text-main); font-weight:900; text-align:left; }

        .items-box{
            margin:14px 0;
            background: color-mix(in srgb, var(--surface) 92%, transparent);
            border:1px solid var(--border);
            border-radius:22px;
            padding:12px;
        }
        .meal-row{
            display:flex; gap:12px;
            padding:12px 2px;
            border-bottom:1px dashed color-mix(in srgb, var(--border) 75%, transparent);
            align-items:flex-start;
        }
        .meal-row:last-child{ border-bottom:none; }
        .mimg{
            width:66px; height:66px; border-radius:18px;
            object-fit:cover; border:1px solid var(--border);
            background: color-mix(in srgb, var(--surface) 70%, #000 30%);
            flex-shrink:0;
        }
        .minfo{ flex:1; min-width:0; }
        .mname{ font-weight:900; font-size:.98rem; margin-bottom:6px; line-height:1.25; }
        .mdesc{ font-weight:800; font-size:.80rem; color:var(--text-sub); line-height:1.4; }
        .mprice{ margin-top:8px; font-weight:900; color:var(--primary); font-size:.88rem; }
        .tag{
            display:inline-flex; align-items:center;
            padding:4px 8px; border-radius:10px;
            background:var(--glass2); border:1px solid var(--border);
            font-weight:900; font-size:.70rem; margin-left:6px;
        }
        .address{
            margin-top:14px;
            border-radius:18px;
            padding:14px;
            border:1px solid var(--border);
            background: color-mix(in srgb, var(--info) 16%, var(--surface) 84%);
            font-weight:900;
            display:flex; gap:10px; align-items:flex-start;
            line-height:1.45;
        }

        /* Notifications Panel */
        #notifOverlay{
            position:fixed; inset:0;
            background: rgba(0,0,0,.42);
            z-index:9000;
            display:none;
        }
        #notifPanel{
            position:fixed;
            top:78px;
            left:14px; right:14px;
            background:var(--surface);
            border:1px solid var(--border);
            border-radius:22px;
            max-height:420px;
            overflow:auto;
            z-index:9001;
            box-shadow:0 20px 60px rgba(0,0,0,.22);
            display:none;
            padding:14px;
        }
        .notif-head{
            display:flex; align-items:center; justify-content:space-between;
            padding:6px 6px 12px;
            border-bottom:1px solid var(--border);
            margin-bottom:10px;
        }
        .notif-title{ font-weight:900; font-size:1rem; }
        .notif-sub{ color:var(--text-sub); font-size:.78rem; font-weight:800; }

        .notif-item{
            display:flex; gap:12px;
            padding:12px;
            border-radius:18px;
            border:1px solid var(--border);
            margin-bottom:10px;
            background: color-mix(in srgb, var(--surface) 92%, transparent);
            cursor:pointer;
        }
        .notif-item:active{ transform: scale(.99); }
        .notif-ico{
            width:46px; height:46px; border-radius:16px;
            display:flex; align-items:center; justify-content:center;
            background: color-mix(in srgb, var(--primary) 12%, transparent);
            color:var(--primary);
            border:1px solid var(--border);
            flex-shrink:0;
        }
        .notif-item .t{ font-weight:900; font-size:.92rem; margin-bottom:3px; }
        .notif-item .time{ margin-top:6px; font-weight:900; font-size:.72rem; color: color-mix(in srgb, var(--text-sub) 75%, transparent); }

        /* Toast */
        #toast{
            position:fixed;
            top:-180px;
            left:14px; right:14px;
            background:var(--surface);
            border:1px solid var(--border);
            border-radius:22px;
            padding:14px;
            box-shadow:0 24px 70px rgba(0,0,0,.18);
            z-index:10001;
            display:flex; align-items:center; gap:12px;
            transition:.55s cubic-bezier(.68,-.55,.265,1.55);
            cursor:pointer;
        }
        .toast-ico{
            width:56px; height:56px; border-radius:18px;
            display:flex; align-items:center; justify-content:center;
            background: color-mix(in srgb, var(--accent) 16%, transparent);
            color:var(--primary);
            border:1px solid var(--border);
            flex-shrink:0;
            font-size:1.15rem;
        }
        .xbtn{
            width:40px; height:40px; border-radius:16px;
            display:flex; align-items:center; justify-content:center;
            background:var(--glass2);
            border:1px solid var(--border);
            color:var(--text-sub);
        }
    </style>
</head>

<body data-theme="light">

<audio id="notifSound" src="assets/notification.mp3" preload="auto"></audio>

<!-- Notifications panel -->
<div id="notifOverlay" onclick="toggleNotifs()"></div>
<div id="notifPanel">
    <div class="notif-head">
        <div>
            <div class="notif-title">الإشعارات</div>
            <div class="notif-sub">آخر التحديثات على طلباتك</div>
        </div>
        <button class="action-btn" style="width:42px;height:42px;border-radius:14px;" onclick="toggleNotifs()">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <div id="notifList">
        <div style="text-align:center; color: var(--text-sub); padding: 18px;" data-empty="1">
            لا توجد إشعارات حالياً
        </div>
    </div>
</div>

<!-- Toast -->
<div id="toast" onclick="handleToastClick()">
    <div class="toast-ico"><i class="fas fa-bell"></i></div>
    <div style="flex:1;">
        <b id="toastTitle" style="display:block; font-size:0.95rem; color:var(--primary); font-weight:900;">إشعار</b>
        <span id="toastBody" style="font-size:0.85rem; color:var(--text-sub); font-weight:800;"></span>
    </div>
    <div class="xbtn" onclick="closeToast(event)"><i class="fas fa-times"></i></div>
</div>

<!-- Top bar -->
<div class="topbar">
    <div class="toprow">
        <a class="backbtn" href="client_dashboard.php" aria-label="Back"><i class="fas fa-arrow-right"></i></a>

        <div class="titlebox">
            <h1 class="maintitle">طلباتي</h1>
            <div class="subtitle">متابعة حالة الطلبات والاشتراك</div>
        </div>

        <div class="actions">
            <button class="action-btn" onclick="toggleNotifs()" aria-label="Notifications">
                <i class="fas fa-bell"></i>
                <span id="redDot" class="notif-dot"></span>
            </button>
        </div>
    </div>
</div>

<div class="container">

    <!-- Subscription Meal -->
    <div class="section">
        <div class="section-head">
            <h3 class="section-title"><i class="fas fa-box-open"></i> وجبة الاشتراك (اليوم)</h3>
            <a class="section-link" href="client_select_meals.php">تعديل</a>
        </div>

        <?php if (!$sub_meal): ?>
            <div class="card card-soft" style="text-align:center; padding: 22px; border-style:dashed;">
                <div style="font-size: 2.4rem; opacity:.25; margin: 6px 0 10px;"><i class="fas fa-calendar-times"></i></div>
                <div style="font-weight:900; color: var(--text-sub);">لا توجد وجبة مجدولة اليوم</div>
            </div>
        <?php else:
            $st = (string)($sub_meal['status'] ?? 'pending');
            $step = getStep($st);
            $progress = ($step-1) * 33; if($step==4) $progress=100; if($step==0) $progress=0;

            $img = '';
            if (!empty($sub_meal['image'])) $img = 'uploads/'.$sub_meal['image'];
            else if (!empty($sub_meal['image_url'])) $img = 'uploads/'.$sub_meal['image_url'];
            if (!$img) $img = 'uploads/logo.png';

            $chipText = ($meal_source === 'plan') ? '📅 مجدولة تلقائياً' : '✅ تم اعتمادها';
            $m = statusMeta($st, false);
        ?>
            <div class="card">
                <div class="meal-head">
                    <img class="meal-img" src="<?php echo safeStr($img); ?>" alt="meal" loading="lazy">
                    <div style="flex:1; min-width:0;">
                        <div class="meal-name"><?php echo safeStr($sub_meal['meal_name'] ?? 'وجبة'); ?></div>
                        <div class="meal-chiprow">
                            <span class="chip"><i class="fas fa-badge-check"></i><?php echo safeStr($chipText); ?></span>
                            <span class="chip"><i class="fas <?php echo safeStr($m['icon']); ?>"></i><?php echo safeStr($m['txt']); ?></span>
                        </div>
                    </div>
                </div>

                <div class="tracker">
                    <div class="track-line"></div>
                    <div class="track-fill" style="width: <?php echo (int)$progress; ?>%;"></div>
                    <div class="steps">
                        <div class="step <?php echo $step>=1?'active':''; ?>"><div class="c"><i class="fas fa-receipt"></i></div><div class="l">استلام</div></div>
                        <div class="step <?php echo $step>=2?'active':''; ?>"><div class="c"><i class="fas fa-fire"></i></div><div class="l">تجهيز</div></div>
                        <div class="step <?php echo $step>=3?'active':''; ?>"><div class="c"><i class="fas fa-motorcycle"></i></div><div class="l">توصيل</div></div>
                        <div class="step <?php echo $step>=4?'active':''; ?>"><div class="c"><i class="fas fa-check"></i></div><div class="l">وصلت</div></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Menu Orders -->
    <div class="section" style="margin-top: 18px;">
        <div class="section-head">
            <h3 class="section-title"><i class="fas fa-shopping-bag"></i> طلبات المنيو</h3>
            <span class="section-link" style="color: var(--text-sub);">آخر 15 طلب</span>
        </div>

        <?php if (empty($menu_orders)): ?>
            <div class="card card-soft" style="text-align:center; padding: 22px; border-style:dashed;">
                <div style="font-size: 2.4rem; opacity:.25; margin: 6px 0 10px;"><i class="fas fa-box-open"></i></div>
                <div style="font-weight:900; color: var(--text-sub);">لا توجد طلبات منيو حالياً</div>
            </div>
        <?php else: ?>
            <?php foreach($menu_orders as $ord):
                $st = (string)($ord['status'] ?? 'pending');

                // pickup detection: order_type first, otherwise address/address_text
                $is_pickup = false;
                if (!empty($ord['order_type'])) {
                    $is_pickup = (strtolower((string)$ord['order_type']) === 'pickup');
                } else {
                    $addr = (string)($ord['address'] ?? '');
                    if (!$addr) $addr = (string)($ord['address_text'] ?? '');
                    $is_pickup = (mb_stripos($addr, 'فرع') !== false || mb_stripos($addr, 'استلام') !== false);
                }

                $meta = statusMeta($st, $is_pickup);
            ?>
                <div class="card card-soft" style="margin-bottom: 12px;" onclick="openOrderDetails(<?php echo (int)$ord['id']; ?>)">
                    <div class="order-row">
                        <div class="order-meta">
                            <div class="order-id">طلب #<?php echo (int)$ord['id']; ?></div>
                            <div class="order-date"><?php echo safeStr(date('Y/m/d - h:i A', strtotime($ord['created_at']))); ?></div>
                            <div class="order-price"><?php echo safeStr(number_format((float)$ord['total_price'], 2)); ?> ر.س</div>
                        </div>
                        <div class="status-badge <?php echo safeStr($meta['cls']); ?>">
                            <i class="fas <?php echo safeStr($meta['icon']); ?>"></i>
                            <?php echo safeStr($meta['txt']); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<!-- Modal -->
<div id="orderModal" class="modal" onclick="if(event.target==this) closeOrderModal()">
    <div class="sheet">
        <div id="modalLoading" style="text-align:center; padding: 36px;">
            <i class="fas fa-spinner fa-spin fa-2x" style="color:var(--primary);"></i>
            <div style="margin-top: 12px; font-weight: 900; color: var(--text-sub);">جاري تحميل التفاصيل...</div>
        </div>

        <div id="modalBody" style="display:none;">
            <div class="sheet-head">
                <div class="sheet-title">تفاصيل الطلب <span id="mOrderId">#</span></div>
                <div class="close" onclick="closeOrderModal()"><i class="fas fa-times"></i></div>
            </div>

            <div class="tracker" style="margin-top: 12px; margin-bottom: 18px;">
                <div class="track-line"></div>
                <div class="track-fill" id="mTrackFill" style="width:0%;"></div>
                <div class="steps">
                    <div class="step" id="step1"><div class="c"><i class="fas fa-receipt"></i></div><div class="l">استلام</div></div>
                    <div class="step" id="step2"><div class="c"><i class="fas fa-fire"></i></div><div class="l">تجهيز</div></div>
                    <div class="step" id="step3"><div class="c"><i class="fas" id="step3Icon"></i></div><div class="l" id="step3Label">...</div></div>
                    <div class="step" id="step4"><div class="c"><i class="fas" id="step4Icon"></i></div><div class="l" id="step4Label">...</div></div>
                </div>
            </div>

            <div class="detail-row"><span class="d-label">تاريخ الطلب:</span><span class="d-val" id="mDate">--</span></div>
            <div class="detail-row"><span class="d-label">طريقة الدفع:</span><span class="d-val" id="mPayMethod">--</span></div>

            <div class="items-box" id="mItemsList"></div>

            <div class="detail-row" style="font-size:1.08rem; border-top:1px solid var(--border); margin-top: 10px; padding-top: 14px;">
                <span class="d-label">الإجمالي الكلي:</span>
                <span class="d-val" style="color:var(--primary);" id="mTotal">0.00 ر.س</span>
            </div>

            <div class="address">
                <i class="fas fa-map-marker-alt" style="margin-top: 2px; color: var(--primary);"></i>
                <span id="mAddress">العنوان...</span>
            </div>
        </div>
    </div>
</div>

<?php if (file_exists('client_footer_nav.php')) include 'client_footer_nav.php'; ?>

<script>
    // ==========================
    // Theme sync with client_dashboard.php
    // ==========================
    function applyThemeFromStorage(){
        const t = localStorage.getItem('theme');
        document.body.setAttribute('data-theme', (t === 'dark') ? 'dark' : 'light');
    }
    applyThemeFromStorage();

    // ==========================
    // Modal open + secure details
    // ==========================
    function openOrderDetails(id) {
        document.getElementById('orderModal').style.display = 'flex';
        document.getElementById('modalLoading').style.display = 'block';
        document.getElementById('modalBody').style.display = 'none';

        fetch(`my_orders.php?get_details=1&order_id=${encodeURIComponent(id)}`, { cache: 'no-store' })
            .then(r => r.json())
            .then(res => {
                if(res.status === 'success') {
                    const ord = res.order || {};

                    document.getElementById('mOrderId').innerText = '#' + (ord.id || '');

                    // تاريخ
                    document.getElementById('mDate').innerText = ord.created_at || '--';

                    // الدفع (متوافق مع cod/tabby/tamara + fallback)
                    let payTxt = (ord.payment_method || '').toString();
                    let raw = payTxt.toLowerCase();

                    if (raw === 'cash' || payTxt === 'كاش' || raw === 'cod') payTxt = 'دفع عند الاستلام';
                    else if (raw === 'card' || payTxt === 'شبكة') payTxt = 'شبكة / مدى';
                    else if (raw.includes('bank') || raw.includes('transfer') || raw.includes('تحويل')) payTxt = 'تحويل بنكي';
                    else if (raw.includes('tabby')) payTxt = 'تابي (Tabby)';
                    else if (raw.includes('tamara')) payTxt = 'تمارا (Tamara)';
                    else if (!payTxt) payTxt = '--';

                    document.getElementById('mPayMethod').innerText = payTxt;

                    // إجمالي
                    document.getElementById('mTotal').innerText = (parseFloat(ord.total_price || 0).toFixed(2)) + ' ر.س';

                    // عنوان: يدعم address أو address_text
                    const addr = (ord.address && String(ord.address).trim()) ? ord.address : (ord.address_text || '--');
                    document.getElementById('mAddress').innerText = addr;

                    // Items
                    let itemsHtml = '';
                    (res.items || []).forEach(item => {
                        const sizeTxt = item.size_txt ? `<span class="tag">${escapeHtml(item.size_txt)}</span>` : '';
                        const optsHtml = item.opts_txt ? `<div class="mdesc">+ ${escapeHtml(item.opts_txt)}</div>` : '';
                        itemsHtml += `
                            <div class="meal-row">
                                <img src="${escapeAttr(item.image)}" class="mimg" alt="">
                                <div class="minfo">
                                    <div class="mname">${escapeHtml(item.meal_name)} ${sizeTxt}</div>
                                    ${optsHtml}
                                    <div class="mprice">${escapeHtml(String(item.quantity))} × ${escapeHtml(String(item.price))} ر.س</div>
                                </div>
                            </div>
                        `;
                    });
                    document.getElementById('mItemsList').innerHTML = itemsHtml || `<div style="text-align:center; padding:14px; color:var(--text-sub); font-weight:900;">لا توجد عناصر</div>`;

                    updateTracker(ord.status, res.is_pickup);

                    document.getElementById('modalLoading').style.display = 'none';
                    document.getElementById('modalBody').style.display = 'block';
                } else {
                    alert(res.message || 'تعذر فتح تفاصيل الطلب');
                    closeOrderModal();
                }
            })
            .catch(() => { alert("حدث خطأ في الاتصال."); closeOrderModal(); });
    }

    function closeOrderModal() {
        document.getElementById('orderModal').style.display = 'none';
    }

    function updateTracker(status, isPickup) {
        let step = 1;
        if(status === 'prepared' || status === 'cooking') step = 2;
        if(status === 'out_for_delivery' || status === 'ready_for_pickup') step = 3;
        if(status === 'delivered' || status === 'picked_up') step = 4;
        if(status === 'cancelled') step = 0;

        if(isPickup) {
            document.getElementById('step3Icon').className = 'fas fa-store';
            document.getElementById('step3Label').innerText = 'جاهز بالفرع';
            document.getElementById('step4Label').innerText = 'تم الاستلام';
        } else {
            document.getElementById('step3Icon').className = 'fas fa-motorcycle';
            document.getElementById('step3Label').innerText = 'جاري التوصيل';
            document.getElementById('step4Label').innerText = 'وصلت';
        }
        document.getElementById('step4Icon').className = 'fas fa-check';

        document.querySelectorAll('.step').forEach(el => el.classList.remove('active'));
        if(step >= 1) document.getElementById('step1').classList.add('active');
        if(step >= 2) document.getElementById('step2').classList.add('active');
        if(step >= 3) document.getElementById('step3').classList.add('active');
        if(step >= 4) document.getElementById('step4').classList.add('active');

        let w = 0;
        if(step === 2) w = 33;
        if(step === 3) w = 66;
        if(step === 4) w = 100;
        document.getElementById('mTrackFill').style.width = w + '%';
    }

    // ==========================
    // Notifications (from DB notifications: message only)
    // ==========================
    let lastSeenNotifId = parseInt(localStorage.getItem('my_orders_last_notif_id') || '0', 10);
    let toastMessage = "";
    let toastNotifId = 0;

    function toggleNotifs(){
        const panel = document.getElementById('notifPanel');
        const overlay = document.getElementById('notifOverlay');
        const open = (panel.style.display === 'block');
        if(open){
            panel.style.display = 'none';
            overlay.style.display = 'none';
        } else {
            panel.style.display = 'block';
            overlay.style.display = 'block';
        }
    }

    function pushToNotifList(n){
        const list = document.getElementById('notifList');
        if(!list) return;

        if (list.querySelector('[data-empty="1"]')) list.innerHTML = '';

        const item = document.createElement('div');
        item.className = 'notif-item';
        item.onclick = () => onNotifClick(n);

        const msg = (n.message || '').toString();
        const time = n.created_at ? n.created_at : '';

        item.innerHTML = `
            <div class="notif-ico"><i class="fas fa-bell"></i></div>
            <div style="flex:1; min-width:0;">
                <div class="t">${escapeHtml(msg || 'إشعار')}</div>
                <div class="time">${escapeHtml(time)}</div>
            </div>
        `;
        list.prepend(item);
    }

    function showToast(n){
        const toast = document.getElementById('toast');
        const msg = (n.message || '').toString();

        // عنوان ثابت + النص هو الرسالة
        document.getElementById('toastTitle').innerText = 'إشعار جديد';
        document.getElementById('toastBody').innerText  = msg || '';

        toastMessage = msg;
        toastNotifId = parseInt(n.id || 0, 10) || 0;

        // red dot
        document.getElementById('redDot').style.display = 'block';

        // show
        toast.style.top = '16px';

        // sound + vibrate
        document.getElementById('notifSound').play().catch(()=>{});
        if ("vibrate" in navigator) navigator.vibrate([120, 80, 120]);

        setTimeout(() => closeToast(), 12000);
    }

    function closeToast(e){
        if(e) e.stopPropagation();
        document.getElementById('toast').style.top = '-180px';
    }

    function extractOrderIdFromMessage(msg){
        // يدعم: #123 | طلب 123 | طلب رقم 123 | order #123 ...
        msg = (msg || '').toString();
        const patterns = [
            /#\s*(\d+)/,
            /طلب\s*#?\s*(\d+)/,
            /رقم\s*(\d+)/i,
            /order\s*#\s*(\d+)/i
        ];
        for (const re of patterns) {
            const m = msg.match(re);
            if (m && m[1]) return parseInt(m[1], 10);
        }
        return 0;
    }

    function handleToastClick(){
        if(toastNotifId){
            fetch(`my_orders.php?notif_read=${encodeURIComponent(toastNotifId)}`, { cache:'no-store' }).catch(()=>{});
        }
        const id = extractOrderIdFromMessage(toastMessage);
        if(id) openOrderDetails(id);
        else toggleNotifs();
    }

    function onNotifClick(n){
        if (n && n.id) fetch(`my_orders.php?notif_read=${encodeURIComponent(n.id)}`, { cache:'no-store' }).catch(()=>{});

        const id = extractOrderIdFromMessage(n.message || '');
        if(id) openOrderDetails(id);
        else toggleNotifs();
    }

    function pollNotifications(){
        fetch('my_orders.php?notif_poll=1', { cache:'no-store' })
            .then(r => r.json())
            .then(data => {
                if(!data || data.status !== 'success') return;

                // unread dot
                if ((data.unread_count || 0) > 0) {
                    document.getElementById('redDot').style.display = 'block';
                } else {
                    document.getElementById('redDot').style.display = 'none';
                }

                // build list first time
                const list = document.getElementById('notifList');
                const firstBuild = list && (list.querySelector('[data-empty="1"]') !== null);
                if(firstBuild && Array.isArray(data.latest)){
                    data.latest.slice().reverse().forEach(n => pushToNotifList(n));
                }

                // toast for new unread
                if (Array.isArray(data.unread) && data.unread.length) {
                    const newest = data.unread[0];
                    const newestId = parseInt(newest.id || 0, 10);
                    if (newestId && newestId > lastSeenNotifId) {
                        lastSeenNotifId = newestId;
                        localStorage.setItem('my_orders_last_notif_id', String(lastSeenNotifId));
                        pushToNotifList(newest);
                        showToast(newest);
                    }
                }
            })
            .catch(()=>{});
    }

    pollNotifications();
    setInterval(pollNotifications, 12000);

    // Auto open by query (?open=123) - اختياري
    (function(){
        const params = new URLSearchParams(window.location.search);
        const openId = parseInt(params.get('open') || '0', 10);
        if(openId) openOrderDetails(openId);
    })();

    function escapeHtml(str){
        return String(str ?? '').replace(/[&<>"']/g, s => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
        }[s]));
    }
    function escapeAttr(str){ return escapeHtml(str).replace(/`/g,''); }
</script>

</body>
</html>