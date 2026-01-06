<?php
// chef_dashboard.php - الإصدار المعتمد (دمج محرك السحب الأصلي + نظام الأوزان الجديد)
// ==============================================================================

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Content-Type: text/html; charset=utf-8");

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/chef_error.log');
error_reporting(E_ALL);

// تسجيل الأخطاء الفاتلة (لتجنب 500 الصامت)
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log("FATAL: {$e['message']} in {$e['file']}:{$e['line']}");
    }
});

if (session_status() == PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['chef', 'admin', 'preparer'])) {
    header("Location: login.php");
    exit;
}

require_once 'db_connect.php';

// فحص اتصال DB (لا يغير التصميم، فقط يمنع 500)
try {
    $pdo->query("SELECT 1");
} catch (Exception $e) {
    error_log("DB CONNECT FAIL: " . $e->getMessage());
    die("<div style='padding:15px;font-family:Tajawal,Tahoma;direction:rtl;background:#fff;border-radius:10px;margin:15px'>
        <b>خطأ اتصال قاعدة البيانات</b><br>راجع ملف <b>chef_error.log</b>
    </div>");
}

$today = date('Y-m-d');

// --- 🛠️ الجزء 1: المحرك الذكي للأوزان ---

function smartExtractWeight($str) {
    if (!$str) return 0;
    $str = strtolower(trim($str));
    if (preg_match('/(\d+(\.\d+)?)/', $str, $matches)) {
        $val = floatval($matches[1]);
        if (strpos($str, 'kg') !== false || strpos($str, 'كيلو') !== false) return $val * 1000;
        if (strpos($str, 'l') !== false && strpos($str, 'ml') === false) return $val * 1000;
        return $val;
    }
    return 0;
}

function detectType($str) {
    $str = strtolower($str);
    if (strpos($str, 'ml') !== false || strpos($str, 'l') !== false || strpos($str, 'مل') !== false || strpos($str, 'لتر') !== false || strpos($str, 'صوص') !== false) return 'liquid';
    if (strpos($str, 'piece') !== false || strpos($str, 'pcs') !== false || strpos($str, 'قطعة') !== false || strpos($str, 'حبة') !== false) return 'count';
    return 'solid';
}

function formatSmart($val, $type) {
    if ($val <= 0) return "-";
    if ($type == 'liquid') {
        if ($val >= 1000) return number_format($val/1000, 2) . " <small>لتر</small>";
        return number_format($val, 0) . " <small>مل</small>";
    } elseif ($type == 'count') return number_format($val, 0) . " <small>حبة</small>";
    else {
        if ($val >= 1000) return number_format($val/1000, 2) . " <small>كجم</small>";
        return number_format($val, 0) . " <small>جم</small>";
    }
}

// --- 🛠️ الجزء 2: محرك سحب الطلبات (استعادة الكود الأصلي الشغال) ---

$msg = "";
if (isset($_POST['generate_orders'])) {
    try {
        $pdo->beginTransaction();

        // سحب وجبات اليوم من جداول الاختيارات اليومية للعملاء
        $sql = "SELECT ds.client_id, ds.meal_id, ds.category_id
                FROM daily_selections ds
                WHERE ds.delivery_date = ?
                AND ds.client_id NOT IN (SELECT client_id FROM client_pauses WHERE ? BETWEEN start_date AND end_date)
                AND NOT EXISTS (
                    SELECT 1 FROM delivery_log dl
                    WHERE dl.client_id = ds.client_id
                      AND dl.meal_id   = ds.meal_id
                      AND dl.delivery_date = ds.delivery_date
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$today, $today]);
        $selections = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ins = $pdo->prepare("INSERT INTO delivery_log (client_id, meal_id, delivery_date, status, category) VALUES (?, ?, ?, 'pending', ?)");
        $added = 0;
        foreach ($selections as $sel) {
            $ins->execute([$sel['client_id'], $sel['meal_id'], $today, 'اشتراك']);
            $added++;
        }

        $pdo->commit();
        $msg = ($added > 0) ? "تم سحب ($added) طلب جديد بنجاح." : "قائمة الطلبات محدثة بالفعل.";

    } catch (Exception $e) {
        // ✅ rollback آمن لمنع 500
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = "خطأ في السحب: " . $e->getMessage();
        error_log("GENERATE_ORDERS ERROR: " . $e->getMessage());
    }
}

if (isset($_POST['bulk_action']) && !empty($_POST['order_ids'])) {
    try{
        $ids = array_map('intval', $_POST['order_ids']);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE delivery_log SET status = 'prepared' WHERE id IN ($in)")->execute($ids);
    }catch(Exception $e){
        $msg = "خطأ في التحديث: " . $e->getMessage();
        error_log("BULK_ACTION ERROR: " . $e->getMessage());
    }
}

if (isset($_GET['done_id'])) {
    try{
        $pdo->prepare("UPDATE delivery_log SET status = 'prepared' WHERE id = ?")->execute([(int)$_GET['done_id']]);
        header("Location: chef_dashboard.php");
        exit;
    }catch(Exception $e){
        $msg = "خطأ في إنهاء الطلب: " . $e->getMessage();
        error_log("DONE_ID ERROR: " . $e->getMessage());
    }
}

// --- 🛠️ الجزء 3: تجميع البيانات للعرض (Logic Engine) ---

$production_items = [];
$tickets_list = [];

try {
    $sql = "SELECT dl.id as log_id, u.name as client_name, p.name as meal_name, ds.selected_weight, ds.selected_option
            FROM delivery_log dl
            LEFT JOIN users u ON dl.client_id = u.id
            LEFT JOIN products p ON dl.meal_id = p.id
            LEFT JOIN daily_selections ds ON (ds.client_id = dl.client_id AND ds.meal_id = dl.meal_id AND ds.delivery_date = dl.delivery_date)
            WHERE dl.delivery_date = ? AND dl.status = 'pending' ORDER BY p.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$today]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        // 1. معالجة الوجبة
        $mName = $row['meal_name'] ?: 'منتج مجهول';
        $mW = smartExtractWeight($row['selected_weight']);
        $mType = detectType($mName);
        $mKey = md5('meal_'.$mName);

        if (!isset($production_items[$mKey])) {
            $production_items[$mKey] = ['name'=>$mName, 'type'=>$mType, 'total_qty'=>0, 'count'=>0, 'is_option'=>false];
        }
        $production_items[$mKey]['count']++;
        $production_items[$mKey]['total_qty'] += $mW;

        // 2. معالجة الخيار
        $opt = trim($row['selected_option'] ?? '');
        if (!empty($opt)) {
            $cName = trim(preg_replace('/[\[\(].*?[\]\)]/', '', $opt)) ?: $opt;
            $oKey = md5('opt_'.$cName);
            if (!isset($production_items[$oKey])) {
                $production_items[$oKey] = ['name'=>$cName, 'type'=>detectType($opt), 'total_qty'=>0, 'count'=>0, 'is_option'=>true];
            }
            $production_items[$oKey]['count']++;
            $production_items[$oKey]['total_qty'] += smartExtractWeight($opt);
        }

        $tickets_list[] = [
            'id' => $row['log_id'],
            'client' => $row['client_name'] ?? 'عميل',
            'meal' => $mName,
            'details' => ($row['selected_weight'] ? $row['selected_weight'].'g' : '') . ' | ' . $opt
        ];
    }
} catch (Exception $e) {
    $msg = "خطأ في الجلب: " . $e->getMessage();
    error_log("FETCH ERROR: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>شاشة الإنتاج</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css?v=9.0">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #2c3e50; --accent: #e67e22; --success: #27ae60; --bg: #f4f7f6; }
        body { background-color: var(--bg); font-family: 'Tajawal', sans-serif; margin: 0; }
        .chef-container { display: flex; min-height: 100vh; }
        .main-content { flex: 1; padding: 30px; }

        .chef-header {
            background: linear-gradient(135deg, var(--primary), #34495e);
            color: white; padding: 25px; border-radius: 15px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 10px 20px rgba(0,0,0,0.1); margin-bottom: 30px;
        }

        .btn-action { background: var(--accent); color: white; border: none; padding: 12px 20px; border-radius: 10px; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }

        .prod-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px; margin-bottom: 40px; }
        .prod-card { background: #fff; border-radius: 15px; padding: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); border-top: 6px solid var(--success); text-align: center; position: relative; }
        .prod-card.option-card { border-top-color: var(--accent); }
        .prod-card.liquid { border-top-color: #3498db; }
        .prod-qty { font-size: 2.2rem; font-weight: 900; color: var(--primary); margin: 10px 0; }

        .ticket-item { background: #fff; padding: 18px; border-radius: 12px; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 3px 10px rgba(0,0,0,0.02); border-right: 6px solid var(--primary); }

        @media print { .print-hide, .sidebar { display: none !important; } .prod-grid { display: block; } .prod-card { border: 2px solid #000; width: 31%; float: right; margin: 1%; box-shadow: none; page-break-inside: avoid; } }
        .summary-check { position: absolute; top: 10px; right: 10px; width: 18px; height: 18px; }
    </style>
</head>
<body>

<div class="chef-container">
    <?php if(file_exists('chef_sidebar.php')) include 'chef_sidebar.php'; ?>

    <div class="main-content">
        <div class="chef-header print-hide">
            <div>
                <h1 style="margin:0;"><i class="fas fa-fire-alt"></i> شاشة الإنتاج والطبخ</h1>
                <p style="margin:5px 0 0 0; opacity:0.8;">تاريخ اليوم: <?php echo $today; ?></p>
            </div>
            <div style="display:flex; gap:10px;">
                <form method="POST"><button type="submit" name="generate_orders" class="btn-action"><i class="fas fa-sync"></i> سحب الوجبات الجديدة</button></form>
                <button onclick="window.print()" class="btn-action" style="background:#34495e;"><i class="fas fa-print"></i> طباعة القائمة</button>
            </div>
        </div>

        <?php if($msg): ?><div style="background:#d4edda; color:#155724; padding:15px; border-radius:10px; margin-bottom:20px; border:1px solid #c3e6cb;"><?php echo $msg; ?></div><?php endif; ?>

        <h2 style="font-weight:900; color:var(--primary); margin-bottom:20px;"><i class="fas fa-blender"></i> ملخص كميات المطبخ (Production)</h2>
        <div class="prod-grid">
            <?php foreach($production_items as $item): ?>
                <div class="prod-card <?php echo ($item['type']=='liquid'?'liquid':($item['is_option']?'option-card':'')); ?>">
                    <input type="checkbox" class="summary-check print-hide">
                    <div style="font-weight:800; color:#333;"><?php echo htmlspecialchars($item['name']); ?></div>
                    <div class="prod-qty"><?php echo formatSmart($item['total_qty'], $item['type']); ?></div>
                    <div style="font-size:0.85rem; color:#7f8c8d;">لـ <?php echo $item['count']; ?> طلبات</div>
                </div>
            <?php endforeach; ?>
        </div>

        <h2 style="font-weight:900; color:var(--primary); margin-bottom:20px;"><i class="fas fa-box-open"></i> تذاكر التغليف الفردية</h2>
        <form method="POST">
            <button type="submit" name="bulk_action" class="btn-action" style="background:var(--primary); margin-bottom:15px;">إنهاء المحدد</button>
            <?php foreach($tickets_list as $t): ?>
                <div class="ticket-item">
                    <div style="display:flex; align-items:center; gap:15px;">
                        <input type="checkbox" name="order_ids[]" value="<?php echo $t['id']; ?>" style="width:20px; height:20px;">
                        <div>
                            <h4 style="margin:0;"><?php echo htmlspecialchars($t['meal']); ?></h4>
                            <p style="margin:5px 0 0 0; color:#7f8c8d;">العميل: <?php echo htmlspecialchars($t['client']); ?> | <?php echo $t['details']; ?></p>
                        </div>
                    </div>
                    <a href="?done_id=<?php echo $t['id']; ?>" class="btn-action" style="background:var(--success); text-decoration:none;">جاهز</a>
                </div>
            <?php endforeach; ?>
        </form>
    </div>
</div>

<script>
    // ✅ نفس السلوك القديم، لكن بدون loop على done_id
    setTimeout(function(){
        if(!window.location.search.includes('done_id')){
            location.reload();
        }
    }, 60000);
</script>

</body>
</html>