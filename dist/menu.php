<?php
// menu.php - Client Menu (Grid Premium + Dark Mode Sync + New Options System + Menu Toggle)
// =======================================================================================

header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');

if (session_status() == PHP_SESSION_NONE) session_start();
require_once 'db_connect.php';

// -------------------------------------------------------
// ✅ التحقق من وجود باقة نشطة للعميل
// -------------------------------------------------------
$has_package = false;
$package_message = '';
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'client') {
    $client_id = (int)$_SESSION['user_id'];
    try {
        $stmt = $pdo->prepare("SELECT subscription_status, package_id FROM client_details WHERE user_id = ? LIMIT 1");
        $stmt->execute([$client_id]);
        $client_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($client_data) {
            $has_package = in_array($client_data['subscription_status'], ['active', 'paused', 'pending_payment'], true);
        }
    } catch (Exception $e) {
        // في حالة الخطأ، نعتبر أنه لا يوجد باقة
    }
}

// إذا كان زائر (غير مسجل) أو ليس لديه باقة، نعرض رسالة جميلة
if (!isset($_SESSION['user_id']) || !$has_package) {
    // سنعرض رسالة جميلة في بداية المحتوى
    $show_no_package_message = true;
} else {
    $show_no_package_message = false;
}

// -------------------------------------------------------
// Helpers: safe checks (tables/columns)
// -------------------------------------------------------
function tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) { return false; }
}

function columnExists(PDO $pdo, string $table, string $col): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$col]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) { return false; }
}

function getSetting(PDO $pdo, string $key, string $default=''): string {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1");
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        if ($v === false || $v === null) return $default;
        return (string)$v;
    } catch (Exception $e) { return $default; }
}

// -------------------------------------------------------
// ✅ Menu Toggle (from system_settings)
// -------------------------------------------------------
$menuEnabled = (int)getSetting($pdo, 'menu_enabled', '1');
if ($menuEnabled !== 1) {
    http_response_code(503);
    echo "<!doctype html><html lang='ar' dir='rtl'><meta charset='utf-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1'>
    <title>المنيو غير متاح</title>
    <link href='https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap' rel='stylesheet'>
    <body style='margin:0;font-family:Tajawal,Arial;background:#0b1220;color:#fff;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px;text-align:center'>
      <div style='max-width:520px;width:100%;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);border-radius:22px;padding:22px'>
        <div style='font-size:44px;margin-bottom:10px'>🍔</div>
        <h2 style='margin:0 0 10px;font-weight:900'>المنيو غير متاح حالياً</h2>
        <p style='margin:0;color:rgba(255,255,255,.75);font-weight:700;line-height:1.6'>
          تم إيقاف صفحة المنيو مؤقتاً من الإدارة.
        </p>
        <a href='client_dashboard.php' style='display:inline-block;margin-top:16px;padding:12px 16px;border-radius:14px;background:#6c5ce7;color:#fff;text-decoration:none;font-weight:900'>
          العودة للرئيسية
        </a>
      </div>
    </body></html>";
    exit;
}

// -------------------------------------------------------
// Fetch Products with Sizes and Linked Options (Smart)
// -------------------------------------------------------
$products_data = [];

$hasOptionCats = tableExists($pdo, 'option_categories');
$poHasOverride = columnExists($pdo, 'product_options', 'price_override');
$poHasActive   = columnExists($pdo, 'product_options', 'is_active');
$goHasActive   = columnExists($pdo, 'global_options', 'is_active');
$goHasCatId    = columnExists($pdo, 'global_options', 'category_id');
$goHasFat      = columnExists($pdo, 'global_options', 'fat');
$goHasFats     = columnExists($pdo, 'global_options', 'fats'); // just in case
$psHasFat      = columnExists($pdo, 'product_sizes', 'fat');
$psHasFats     = columnExists($pdo, 'product_sizes', 'fats');

try {
    // 1) Fetch active products
    $stmt = $pdo->query("SELECT * FROM products WHERE stock_qty != 0 ORDER BY id DESC");
    $products_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // dynamic select for product_sizes macros
    $sizeFatCol = $psHasFats ? 'fats' : ($psHasFat ? 'fat' : null);

    foreach ($products_raw as $prod) {
        $p_id = (int)$prod['id'];

        // 2) Fetch sizes for this product (include macros if exist)
        $sizesCols = "id, product_id, size_name, price, weight, calories, protein, carbs";
        if ($sizeFatCol) $sizesCols .= ", `$sizeFatCol` AS fats";
        else $sizesCols .= ", 0 AS fats";

        $sizes_stmt = $pdo->prepare("SELECT $sizesCols FROM product_sizes WHERE product_id = ? ORDER BY price ASC");
        $sizes_stmt->execute([$p_id]);
        $sizes = $sizes_stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3) Fetch linked options with NEW system fields (tier nutrition + category)
        $optSelect = "
            po.product_id,
            po.global_option_id,
            po.sort_order,
            ".($poHasOverride ? "po.price_override," : "NULL AS price_override,")."
            po.price AS base_price,

            go.name,
            go.unit as display_unit,
            go.pricing_config
        ";

        // nutrition base columns (optional)
        $goFatCol = $goHasFats ? 'fats' : ($goHasFat ? 'fat' : null);
        $optSelect .= ", COALESCE(go.calories,0) AS n_calories
                        , COALESCE(go.protein,0)  AS n_protein
                        , COALESCE(go.carbs,0)    AS n_carbs";
        if ($goFatCol) $optSelect .= ", COALESCE(go.`$goFatCol`,0) AS n_fat";
        else $optSelect .= ", 0 AS n_fat";

        // category fields (optional)
        if ($goHasCatId) {
            $optSelect .= ", go.category_id";
        } else {
            $optSelect .= ", NULL AS category_id";
        }

        $optJoinCats = "";
        if ($hasOptionCats && $goHasCatId) {
            $optSelect .= ", oc.name AS category_name, oc.sort_order AS category_sort";
            $optJoinCats = " LEFT JOIN option_categories oc ON go.category_id = oc.id ";
        } else {
            $optSelect .= ", NULL AS category_name, 9999 AS category_sort";
        }

        $where = " WHERE po.product_id = ? ";
        if ($poHasActive) $where .= " AND po.is_active = 1 ";
        if ($goHasActive) $where .= " AND go.is_active = 1 ";

        $opts_sql = "
            SELECT $optSelect
            FROM product_options po
            JOIN global_options go ON po.global_option_id = go.id
            $optJoinCats
            $where
            ORDER BY COALESCE(category_sort,9999) ASC, po.sort_order ASC, go.name ASC
        ";
        $opts_stmt = $pdo->prepare($opts_sql);
        $opts_stmt->execute([$p_id]);
        $options = $opts_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Process options for JS
        $processed_options = [];
        foreach ($options as $opt) {
            $pricing = [];
            if (!empty($opt['pricing_config'])) {
                $decoded = json_decode($opt['pricing_config'], true);
                if (is_array($decoded)) $pricing = $decoded;
            }

            $processed_options[] = [
                'id' => (int)$opt['global_option_id'],
                'name' => (string)$opt['name'],
                'display_unit' => (string)($opt['display_unit'] ?? ''),
                'price_override' => $opt['price_override'],
                'base_price' => $opt['base_price'],
                'pricing_config' => $pricing,
                'nutrition_base' => [
                    'calories' => (float)$opt['n_calories'],
                    'protein'  => (float)$opt['n_protein'],
                    'carbs'    => (float)$opt['n_carbs'],
                    'fat'      => (float)$opt['n_fat'],
                ],
                'category' => [
                    'id' => $opt['category_id'],
                    'name' => $opt['category_name'] ? (string)$opt['category_name'] : 'إضافات',
                    'sort' => (int)($opt['category_sort'] ?? 9999),
                ],
            ];
        }

        $prod['sizes'] = $sizes;
        $prod['options'] = $processed_options;

        // Only add if it has sizes
        if (count($sizes) > 0) {
            $products_data[] = $prod;
        }
    }
} catch (Exception $e) {
    // silently ignore to keep UI clean (you can log if needed)
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>قائمة الطعام</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <style>
        /* ==========================
           Theme Vars (Match dashboard)
        ========================== */
        :root {
            --primary: #6c5ce7;
            --primary2: #8e44ad;

            --bg: #f7f8ff;
            --surface: #ffffff;

            --text-main: #1f2937;
            --text-sub: #6b7280;

            --card-shadow: 0 14px 40px rgba(17, 24, 39, 0.08);
            --soft-shadow: 0 10px 25px rgba(17, 24, 39, 0.06);
            --border: rgba(17,24,39,.07);

            --glass2: rgba(255,255,255,.45);

            --danger: #ff7675;
            --success: #22c55e;
        }

        body[data-theme="dark"]{
            --bg: #0b1220;
            --surface: #111b2f;

            --text-main: #f8fafc;
            --text-sub: #94a3b8;

            --card-shadow: 0 18px 55px rgba(0,0,0,0.45);
            --soft-shadow: 0 14px 35px rgba(0,0,0,0.35);
            --border: rgba(255,255,255,.08);

            --glass2: rgba(17,27,47,.55);
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body{
            margin:0;
            font-family:'Tajawal',sans-serif;
            background: var(--bg);
            color: var(--text-main);
            padding-top: 96px;
            padding-bottom: 105px;
            overflow-x:hidden;
        }
        a { text-decoration:none; color: inherit; }

        /* ==========================
           Fixed Header (Premium)
        ========================== */
        .top-nav {
            position: fixed; top: 0; left: 0; width: 100%; z-index: 1000;
            height: 84px;
            background: color-mix(in srgb, var(--bg) 92%, transparent);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--border);
            display:flex; align-items:center;
        }
        .user-welcome { padding: 0 16px; display: flex; align-items: center; justify-content: space-between; width: 100%; }
        .title-box{ display:flex; align-items:center; gap: 12px; }
        .back-btn{
            width:46px; height:46px; border-radius:14px;
            background: var(--surface);
            border: 1px solid var(--border);
            box-shadow: var(--soft-shadow);
            display:flex; align-items:center; justify-content:center;
            color: var(--text-main);
        }
        .back-btn:active{ transform: scale(.98); }
        .page-title{
            margin:0;
            font-size: 1.05rem;
            font-weight: 900;
        }
        .page-sub{
            font-size:.78rem;
            color: var(--text-sub);
            font-weight: 800;
            margin-top: 2px;
        }

        .icon-btn{
            background: var(--surface);
            color: var(--text-main);
            width:46px; height:46px;
            border-radius:14px;
            display:flex; align-items:center; justify-content:center;
            box-shadow: var(--soft-shadow);
            border: 1px solid var(--border);
            position: relative;
            cursor:pointer;
        }
        .icon-btn:active{ transform: scale(.98); }

        .cart-badge{
            position:absolute; top:-6px; right:-6px;
            width:22px; height:22px;
            background: var(--danger);
            border-radius:50%;
            text-align:center;
            line-height:22px;
            color:white;
            font-size:0.72rem;
            font-weight:900;
            border:2px solid var(--surface);
        }

        /* ==========================
           Grid (Premium like packages)
        ========================== */
        .meal-grid{
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
            padding: 12px 16px;
        }
        .meal-card{
            background: linear-gradient(135deg,
                color-mix(in srgb, var(--surface) 85%, var(--primary) 15%),
                var(--surface)
            );
            border: 1px solid var(--border);
            border-radius: 26px;
            overflow: hidden;
            box-shadow: var(--soft-shadow);
            display:flex;
            flex-direction:column;
            position: relative;
            transition: transform .18s ease;
        }
        .meal-card:active{ transform: scale(.985); }
        .meal-card::after{
            content:"";
            position:absolute;
            inset:-60px auto auto -60px;
            width:160px; height:160px;
            background: radial-gradient(circle, rgba(162,155,254,.35), transparent 65%);
            pointer-events:none;
        }

        .meal-img{
            width:100%;
            height: 138px;
            object-fit: cover;
            background: rgba(0,0,0,.06);
        }
        .meal-info{
            padding: 12px 12px 14px;
            display:flex;
            flex-direction:column;
            gap: 8px;
            flex: 1;
            position:relative;
            z-index:1;
        }
        .meal-title{
            font-size: .92rem;
            font-weight: 900;
            line-height: 1.25;
            height: 2.6em;
            overflow:hidden;
        }
        .meal-sub{
            font-size: .75rem;
            color: var(--text-sub);
            font-weight: 800;
            height: 1.4em;
            overflow:hidden;
        }

        .meal-footer{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-top:auto;
        }
        .meal-price{
            font-size: 1.05rem;
            font-weight: 900;
            color: var(--primary);
        }
        .meal-price small{ font-size: .72rem; font-weight:900; opacity:.9; }

        .btn-add-mini{
            width: 42px; height: 42px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--primary), var(--primary2));
            color: #fff;
            border: none;
            display:flex; align-items:center; justify-content:center;
            cursor: pointer;
            box-shadow: 0 14px 30px rgba(108,92,231,.25);
        }
        .btn-add-mini:active{ transform: scale(.98); }

        /* ==========================
           Modal (Premium)
        ========================== */
        .modal-overlay{
            position:fixed; inset:0;
            background: rgba(0,0,0,0.6);
            z-index: 3000;
            display:none;
            justify-content:center;
            align-items:flex-end;
            backdrop-filter: blur(3px);
        }
        .modal-content{
            background: var(--surface);
            width: 100%;
            max-width: 520px;
            border-radius: 26px 26px 0 0;
            border: 1px solid var(--border);
            box-shadow: 0 -18px 55px rgba(0,0,0,0.25);
            animation: slideUp .25s ease-out;
            display:flex;
            flex-direction:column;
            max-height: 86vh;
            overflow:hidden;
        }
        @keyframes slideUp{ from { transform: translateY(100%);} to { transform: translateY(0);} }

        .modal-header{
            padding: 18px 18px 12px;
            display:flex; justify-content:space-between; align-items:center;
            border-bottom: 1px solid var(--border);
            flex-shrink:0;
        }
        .modal-title{
            margin:0;
            font-size:1.08rem;
            font-weight: 900;
        }
        .modal-desc{
            color: var(--text-sub);
            font-size:.82rem;
            font-weight: 800;
            margin-top: 4px;
        }
        .close-x{
            width: 44px; height: 44px;
            border-radius: 14px;
            border: 1px solid var(--border);
            background: var(--surface);
            box-shadow: var(--soft-shadow);
            color: var(--text-main);
            display:flex; align-items:center; justify-content:center;
            cursor:pointer;
        }
        .close-x:active{ transform: scale(.98); }

        .modal-body{
            padding: 14px 18px;
            overflow:auto;
            flex: 1;
        }

        .modal-footer{
            padding: 14px 18px 22px;
            border-top: 1px solid var(--border);
            background: var(--surface);
            flex-shrink:0;
        }

        .section-title{
            font-weight: 900;
            margin: 8px 0 10px;
            color: var(--text-main);
            font-size: .95rem;
        }

        /* Size selector */
        .size-group{
            display:flex;
            gap: 10px;
            overflow-x:auto;
            padding-bottom: 6px;
            scrollbar-width:none;
        }
        .size-group::-webkit-scrollbar{ display:none; }
        .size-radio{ display:none; }
        .size-label{
            min-width: 92px;
            text-align:center;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 10px 10px;
            cursor:pointer;
            background: color-mix(in srgb, var(--surface) 92%, transparent);
            box-shadow: 0 10px 22px rgba(17,24,39,0.04);
        }
        .size-radio:checked + .size-label{
            border-color: color-mix(in srgb, var(--primary) 55%, var(--border));
            background: color-mix(in srgb, var(--primary) 10%, var(--surface));
            color: var(--primary);
        }
        .size-name{ display:block; font-weight:900; font-size:.9rem; }
        .size-price{ display:block; font-size:.78rem; color: var(--text-sub); font-weight:800; margin-top:2px; }

        /* Macros */
        .macros-bar{
            display:flex;
            justify-content:space-between;
            gap: 8px;
            background: color-mix(in srgb, var(--surface) 88%, transparent);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 10px;
            margin: 12px 0 14px;
            font-size: .78rem;
            color: var(--text-sub);
            font-weight: 800;
            flex-wrap: wrap;
        }
        .macros-bar span{
            display:inline-flex;
            align-items:center;
            gap:6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: var(--glass2);
            border: 1px solid var(--border);
            color: var(--text-main);
            font-weight: 900;
        }

        /* Options */
        .cat-title{
            margin: 14px 0 10px;
            font-weight: 900;
            font-size: .92rem;
            color: var(--text-main);
            display:flex; align-items:center; gap:8px;
        }
        .cat-title::before{
            content:"";
            width: 10px; height: 10px;
            border-radius: 4px;
            background: linear-gradient(135deg, var(--primary), var(--primary2));
            box-shadow: 0 0 18px rgba(108,92,231,.25);
        }

        .opt-row{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap: 10px;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 18px;
            margin-bottom: 10px;
            cursor: pointer;
            background: color-mix(in srgb, var(--surface) 92%, transparent);
            transition: .18s ease;
        }
        .opt-row.active{
            border-color: color-mix(in srgb, var(--primary) 60%, var(--border));
            background: color-mix(in srgb, var(--primary) 8%, var(--surface));
        }
        .opt-left{ display:flex; gap:10px; align-items:flex-start; }
        .opt-chk{
            width: 20px; height: 20px;
            accent-color: var(--primary);
            margin-top: 2px;
            flex-shrink:0;
        }
        .opt-name{
            font-weight: 900;
            font-size: .92rem;
            line-height: 1.2;
        }
        .opt-unit{
            font-size: .75rem;
            color: var(--text-sub);
            font-weight: 800;
            margin-top: 2px;
        }
        .opt-nut{
            margin-top:6px;
            font-size:.74rem;
            color: color-mix(in srgb, var(--text-sub) 90%, #fff 10%);
            font-weight: 900;
            display:flex; gap:10px; flex-wrap:wrap;
        }
        .opt-price{
            font-weight: 900;
            white-space: nowrap;
            margin-top: 2px;
        }
        .opt-free{ color: color-mix(in srgb, var(--text-sub) 85%, #fff 15%); }

        /* CTA */
        .btn-main{
            width:100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--primary), var(--primary2));
            color: #fff;
            border: none;
            border-radius: 18px;
            font-weight: 900;
            font-size: 1rem;
            cursor:pointer;
            box-shadow: 0 18px 40px rgba(108,92,231,.25);
        }
        .btn-main:active{ transform: scale(.99); }

        /* Toast */
        #toast-msg{
            position:fixed;
            bottom: 92px;
            left:50%;
            transform:translateX(-50%);
            background: rgba(34,197,94,.92);
            color: #fff;
            padding: 12px 22px;
            border-radius: 999px;
            font-weight: 900;
            box-shadow: 0 18px 45px rgba(0,0,0,.20);
            z-index: 9999;
            display:none;
        }

        /* Empty */
        .empty{
            text-align:center;
            padding: 60px 20px;
            color: var(--text-sub);
            font-weight: 900;
        }
        .empty i{ font-size: 44px; opacity:.35; display:block; margin-bottom: 12px; }

        /* No Package Message */
        .no-package-banner{
            margin: 18px;
            padding: 24px 20px;
            border-radius: 24px;
            background: linear-gradient(135deg, rgba(255, 118, 117, 0.12) 0%, rgba(255, 118, 117, 0.06) 100%);
            border: 2px solid rgba(255, 118, 117, 0.25);
            text-align: center;
            box-shadow: var(--soft-shadow);
        }
        .no-package-banner .banner-icon{
            font-size: 3.5rem;
            margin-bottom: 16px;
            display: block;
            color: var(--danger);
            opacity: 0.9;
        }
        .no-package-banner .banner-title{
            font-size: 1.25rem;
            font-weight: 900;
            color: var(--text-main);
            margin: 0 0 10px 0;
        }
        .no-package-banner .banner-text{
            font-size: 0.95rem;
            color: var(--text-sub);
            font-weight: 800;
            line-height: 1.6;
            margin: 0 0 20px 0;
        }
        .no-package-banner .banner-buttons{
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .banner-btn{
            padding: 14px 24px;
            border-radius: 16px;
            font-weight: 900;
            font-size: 0.95rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.25s;
            border: 2px solid transparent;
        }
        .banner-btn.primary{
            background: linear-gradient(135deg, var(--primary), var(--primary2));
            color: #fff;
            box-shadow: 0 8px 20px rgba(108, 92, 231, 0.3);
        }
        .banner-btn.primary:active{
            transform: scale(0.98);
        }
        .banner-btn.secondary{
            background: var(--surface);
            color: var(--text-main);
            border-color: var(--border);
            box-shadow: var(--soft-shadow);
        }
        .banner-btn.secondary:active{
            transform: scale(0.98);
        }

        @media (max-width: 380px){
            .meal-img{ height: 124px; }
            .meal-title{ font-size: .88rem; }
        }
    </style>
</head>

<body data-theme="light">

    <!-- Top Header -->
    <div class="top-nav">
        <div class="user-welcome">
            <div class="title-box">
                <a class="back-btn" href="client_dashboard.php" aria-label="Back"><i class="fas fa-arrow-right"></i></a>
                <div>
                    <h3 class="page-title">قائمة الطعام 🍔</h3>
                    <div class="page-sub">اختر الحجم والإضافات بنظام ذكي</div>
                </div>
            </div>

            <div class="nav-actions">
                <div class="icon-btn" onclick="window.location.href='cart.php'" aria-label="Cart">
                    <i class="fas fa-shopping-basket" style="font-size:1.15rem;"></i>
                    <span id="cart-count" class="cart-badge">
                        <?php echo isset($_SESSION['cart']) ? count($_SESSION['cart']) : 0; ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Content -->
    <?php if($show_no_package_message): ?>
        <div class="no-package-banner">
            <i class="fas fa-box-open banner-icon"></i>
            <h2 class="banner-title">ليس لديك باقة نشطة</h2>
            <p class="banner-text">
                لاستعراض قائمة الطعام والاستمتاع بوجباتنا المميزة، يجب أن تكون لديك باقة نشطة.
                <br>يمكنك الاشتراك في إحدى باقاتنا الآن.
            </p>
            <div class="banner-buttons">
                <?php if(isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'client'): ?>
                    <a href="client_browse_packages.php" class="banner-btn primary">
                        <i class="fas fa-box-open"></i>
                        <span>اشترك في باقة</span>
                    </a>
                    <a href="client_dashboard.php" class="banner-btn secondary">
                        <i class="fas fa-home"></i>
                        <span>الرئيسية</span>
                    </a>
                <?php else: ?>
                    <a href="register_client.php" class="banner-btn primary">
                        <i class="fas fa-user-plus"></i>
                        <span>إنشاء حساب جديد</span>
                    </a>
                    <a href="login.php" class="banner-btn secondary">
                        <i class="fas fa-sign-in-alt"></i>
                        <span>تسجيل الدخول</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif(empty($products_data)): ?>
        <div class="empty">
            <i class="fas fa-utensils"></i>
            لا توجد منتجات متاحة حالياً.
        </div>
    <?php else: ?>
        <div class="meal-grid">
            <?php foreach ($products_data as $prod):
                $firstSize = $prod['sizes'][0];
                $imgSrc = !empty($prod['image']) ? 'uploads/'.$prod['image'] : 'uploads/logo.png';
                $desc = $prod['description'] ?? '';
                $prodJSON = htmlspecialchars(json_encode($prod, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
            ?>
            <div class="meal-card">
                <img src="<?php echo htmlspecialchars($imgSrc); ?>" class="meal-img" loading="lazy" alt="meal">

                <div class="meal-info">
                    <div class="meal-title"><?php echo htmlspecialchars($prod['name'] ?? 'منتج'); ?></div>
                    <div class="meal-sub"><?php echo htmlspecialchars(mb_substr($desc, 0, 34)); ?></div>

                    <div class="meal-footer">
                        <span class="meal-price">
                            <?php echo htmlspecialchars($firstSize['price'] ?? '0'); ?> <small>ر.س</small>
                        </span>
                        <button class="btn-add-mini" type="button" onclick='openSmartModal(<?php echo $prodJSON; ?>)'>
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Modal -->
    <div id="optionsModal" class="modal-overlay" onclick="if(event.target==this) $('#optionsModal').fadeOut()">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h3 id="modalTitle" class="modal-title">--</h3>
                    <div id="modalDesc" class="modal-desc">--</div>
                </div>
                <div class="close-x" onclick="$('#optionsModal').fadeOut()"><i class="fas fa-times"></i></div>
            </div>

            <div class="modal-body">
                <div class="section-title">اختر الحجم:</div>
                <div id="sizeContainer" class="size-group"></div>

                <div id="macrosContainer" class="macros-bar"></div>

                <div id="optionsSection" style="display:none;">
                    <div class="section-title">الإضافات والخيارات:</div>
                    <div id="optionsContainer"></div>
                </div>
            </div>

            <div class="modal-footer">
                <button onclick="submitSmartOrder()" class="btn-main" type="button">
                    إضافة للسلة (<span id="totalPriceBtn">0</span> ر.س)
                </button>
            </div>
        </div>
    </div>

    <div id="toast-msg"></div>

    <script>
        // Sync theme with dashboard (localStorage: theme)
        (function(){
            const t = localStorage.getItem('theme');
            document.body.setAttribute('data-theme', (t === 'dark') ? 'dark' : 'light');
        })();

        let currentProduct = null;
        let selectedSizeIndex = 0;
        let selectedOptions = new Set();

        function openSmartModal(productData){
            currentProduct = productData;
            selectedOptions.clear();

            $('#modalTitle').text(productData.name || '--');
            $('#modalDesc').text(productData.description || '');

            // render sizes
            let sizeHtml = '';
            productData.sizes.forEach((size, index)=>{
                const isChecked = (index===0) ? 'checked' : '';
                sizeHtml += `
                    <input type="radio" name="size_opt" id="size_${index}" class="size-radio" value="${index}" ${isChecked}
                        onchange="onSizeChange(${index})">
                    <label for="size_${index}" class="size-label">
                        <span class="size-name">${size.size_name}</span>
                        <span class="size-price">${parseFloat(size.price || 0).toFixed(2)} ر.س</span>
                    </label>
                `;
            });
            $('#sizeContainer').html(sizeHtml);

            onSizeChange(0);
            $('#optionsModal').fadeIn().css('display','flex');
        }

        function onSizeChange(index){
            selectedSizeIndex = index;

            const size = currentProduct.sizes[index];
            const mealWeight = parseFloat(size.weight || 0);

            const cals = parseFloat(size.calories || 0);
            const prot = parseFloat(size.protein  || 0);
            const carbs= parseFloat(size.carbs    || 0);
            const fats = parseFloat(size.fats     || 0);

            $('#macrosContainer').html(`
                <span>🔥 ${cals} Cal</span>
                <span>🥩 ${prot}g P</span>
                <span>🥔 ${carbs}g C</span>
                <span>🥑 ${fats}g F</span>
            `);

            renderOptions(mealWeight);
            updateTotal();
        }

        function escapeHtml(str){
            return String(str ?? '').replace(/[&<>"'`=\/]/g, s => ({
                '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','/':'&#x2F;','`':'&#x60;','=':'&#x3D;'
            }[s]));
        }

        function renderOptions(mealWeight){
            const container = $('#optionsContainer');
            container.empty();

            const opts = (currentProduct.options && currentProduct.options.length) ? currentProduct.options : [];
            if (!opts.length){
                $('#optionsSection').hide();
                return;
            }
            $('#optionsSection').show();

            // group by category name
            const groups = {};
            opts.forEach(opt=>{
                const catName = (opt.category && opt.category.name) ? opt.category.name : 'إضافات';
                if (!groups[catName]) groups[catName] = [];
                groups[catName].push(opt);
            });

            const catNames = Object.keys(groups);
            catNames.forEach(cat=>{
                container.append(`<div class="cat-title">${escapeHtml(cat)}</div>`);

                groups[cat].forEach(opt=>{
                    // ----- tier matching (works even if pricing_config has only tiers) -----
                    let tiers = (opt.pricing_config && Array.isArray(opt.pricing_config.tiers)) ? opt.pricing_config.tiers : null;
                    let matchedTier = null;

                    if (tiers && tiers.length){
                        tiers = tiers.slice().sort((a,b)=> parseFloat(a.threshold||0) - parseFloat(b.threshold||0));
                        for (const tier of tiers){
                            const th = parseFloat(tier.threshold || 0);
                            if (mealWeight <= th){ matchedTier = tier; break; }
                        }
                        if (!matchedTier) matchedTier = tiers[tiers.length - 1];
                    }

                    // price priority: override -> base -> tier price
                    let price = 0;
                    if (opt.price_override !== null && opt.price_override !== "" && opt.price_override !== undefined){
                        price = parseFloat(opt.price_override || 0);
                    } else if (opt.base_price !== null && opt.base_price !== "" && opt.base_price !== undefined){
                        price = parseFloat(opt.base_price || 0);
                    } else if (matchedTier && matchedTier.price !== undefined){
                        price = parseFloat(matchedTier.price || 0);
                    }

                    // nutrition priority: tier.nutrition -> opt.nutrition_base
                    let nut = opt.nutrition_base || {calories:0,protein:0,carbs:0,fat:0};
                    if (matchedTier && matchedTier.nutrition){
                        nut = {
                            calories: parseFloat(matchedTier.nutrition.calories || 0),
                            protein:  parseFloat(matchedTier.nutrition.protein  || 0),
                            carbs:    parseFloat(matchedTier.nutrition.carbs    || 0),
                            fat:      parseFloat(matchedTier.nutrition.fat      || 0),
                        };
                    }

                    const isChecked = selectedOptions.has(opt.id) ? 'checked' : '';
                    const activeClass = isChecked ? 'active' : '';

                    const priceLabel = (price > 0)
                        ? `<span class="opt-price" style="color: var(--success)">+${price.toFixed(2)} ر.س</span>`
                        : `<span class="opt-price opt-free">مجاناً</span>`;

                    const nutHtml = `
                        <div class="opt-nut">
                            <span>🔥 ${nut.calories}</span>
                            <span>🥩 ${nut.protein}</span>
                            <span>🥔 ${nut.carbs}</span>
                            <span>🥑 ${nut.fat}</span>
                        </div>
                    `;

                    const html = `
                        <label class="opt-row ${activeClass}" id="opt_row_${opt.id}">
                            <div class="opt-left">
                                <input type="checkbox"
                                    class="opt-chk"
                                    value="${opt.id}"
                                    data-price="${price}"
                                    onchange="toggleOption(${opt.id}, this)"
                                    ${isChecked}>
                                <div>
                                    <div class="opt-name">${escapeHtml(opt.name)}</div>
                                    <div class="opt-unit">${escapeHtml(opt.display_unit || '')}</div>
                                    ${nutHtml}
                                </div>
                            </div>
                            ${priceLabel}
                        </label>
                    `;
                    container.append(html);
                });
            });
        }

        function toggleOption(id, checkbox){
            if (checkbox.checked){
                selectedOptions.add(id);
                $(`#opt_row_${id}`).addClass('active');
            } else {
                selectedOptions.delete(id);
                $(`#opt_row_${id}`).removeClass('active');
            }
            updateTotal();
        }

        function updateTotal(){
            const sizePrice = parseFloat(currentProduct.sizes[selectedSizeIndex].price || 0);
            let optionsTotal = 0;

            $('.opt-chk:checked').each(function(){
                optionsTotal += parseFloat($(this).data('price') || 0);
            });

            const finalPrice = sizePrice + optionsTotal;
            $('#totalPriceBtn').text(finalPrice.toFixed(2));
        }

        function submitSmartOrder(){
            const btn = $('.btn-main');
            const originalText = btn.html();

            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري الإضافة...');

            const size = currentProduct.sizes[selectedSizeIndex];
            const selectedOptsData = [];

            $('.opt-chk:checked').each(function(){
                const id = $(this).val();
                const name = $(this).closest('label').find('.opt-name').text();
                const price = $(this).data('price');
                selectedOptsData.push({ id, name, price });
            });

            $.ajax({
                url: 'cart_action.php',
                type: 'POST',
                data: {
                    action: 'add_smart',
                    product_id: currentProduct.id,
                    size_id: size.id,
                    size_name: size.size_name,
                    base_price: size.price,
                    options: JSON.stringify(selectedOptsData)
                },
                dataType: 'json',
                success: function(data){
                    btn.prop('disabled', false).html(originalText);

                    if (data && data.status === 'success'){
                        $('#cart-count').text(data.count || 0);
                        $('#optionsModal').fadeOut();
                        showToast('تمت الإضافة للسلة بنجاح ✅');
                    } else {
                        alert('خطأ: ' + (data?.message || 'حدث خطأ غير معروف'));
                    }
                },
                error: function(xhr){
                    btn.prop('disabled', false).html(originalText);
                    console.error(xhr.responseText);
                    alert('حدث خطأ في الاتصال. يرجى المحاولة مرة أخرى.');
                }
            });
        }

        function showToast(msg){
            const t = $('#toast-msg');
            t.stop(true,true).text(msg).fadeIn(180).delay(1900).fadeOut(260);
        }
    </script>

    <?php if (file_exists('client_footer_nav.php')) include 'client_footer_nav.php'; ?>

</body>
</html>