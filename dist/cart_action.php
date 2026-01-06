<?php
// cart_action.php - معالج السلة (يدعم المنيو الذكي + checkout مرتبط بإعدادات الدفع الجديدة)
// =========================================================================================

// تنظيف المخرجات لمنع أخطاء JSON
if (ob_get_length()) ob_clean();

ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once 'db_connect.php';
require_once 'PaymentConfig.php'; // ✅ مهم: قراءة إعدادات الدفع الجديدة

$response = ['status' => 'error', 'message' => 'حدث خطأ غير متوقع'];

function post($k, $d = null) {
    return isset($_POST[$k]) ? $_POST[$k] : $d;
}

function json_out($arr) {
    if (ob_get_length()) ob_clean();
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('طريقة الطلب غير صحيحة');
    }

    $action = (string) post('action', '');

    // =========================================================
    // 1️⃣ إضافة للسلة (الوضع البسيط - Add Classic)
    // =========================================================
    if ($action === 'add') {

        $pid = (int) post('product_id', 0);
        $name = trim((string) post('name', 'منتج'));
        $price = (float) post('price', 0);
        $qty = (int) post('qty', 1);
        $options = post('options', []);
        $selected_weight = post('selected_weight', null);

        if ($pid <= 0) throw new Exception('product_id غير صحيح');

        if (!is_array($options)) $options = [];

        $cartKey = $pid . '_' . md5(json_encode($options, JSON_UNESCAPED_UNICODE) . (string)$selected_weight);

        if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

        if (isset($_SESSION['cart'][$cartKey])) {
            $_SESSION['cart'][$cartKey]['qty'] += max(1, $qty);
        } else {
            $_SESSION['cart'][$cartKey] = [
                'id' => $pid,
                'name' => $name,
                'unit_price' => $price,
                'qty' => max(1, $qty),
                'options' => $options,
                'selected_weight' => $selected_weight
            ];
        }

        $response = ['status' => 'success', 'message' => 'تمت الإضافة', 'count' => count($_SESSION['cart'])];
        json_out($response);
    }

    // =========================================================
    // 2️⃣ إضافة للسلة (الوضع الذكي - Smart Menu Add) 🚀
    // =========================================================
    if ($action === 'add_smart') {

        $pid = (int) post('product_id', 0);
        $size_name = trim((string) post('size_name', '')); // الوزن المختار
        $base_price = (float) post('base_price', 0);

        if ($pid <= 0) throw new Exception('product_id غير صحيح');

        $options_json_str = (string) post('options', '[]');
        $options = json_decode($options_json_str, true);
        if (!is_array($options)) $options = [];

        // جلب اسم المنتج من قاعدة البيانات
        $stmt = $pdo->prepare("SELECT name FROM products WHERE id = ?");
        $stmt->execute([$pid]);
        $p_name = $stmt->fetchColumn();
        if (!$p_name) $p_name = "وجبة مخصصة";

        // حساب السعر النهائي
        $total_price = $base_price;
        foreach ($options as $opt) {
            $total_price += (float)($opt['price'] ?? 0);
        }

        $cartKey = $pid . '_' . md5($options_json_str . $size_name);

        if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

        if (isset($_SESSION['cart'][$cartKey])) {
            $_SESSION['cart'][$cartKey]['qty'] += 1;
        } else {
            $_SESSION['cart'][$cartKey] = [
                'id' => $pid,
                'name' => $p_name,
                'unit_price' => $total_price,
                'qty' => 1,
                'options' => $options,
                'selected_weight' => $size_name
            ];
        }

        $response = ['status' => 'success', 'message' => 'تمت الإضافة بنجاح', 'count' => count($_SESSION['cart'])];
        json_out($response);
    }

    // =========================================================
    // 3️⃣ إتمام الطلب (Checkout) ✅ مرتبط بإعدادات الدفع الجديدة
    // =========================================================
    if ($action === 'checkout') {

        if (empty($_SESSION['cart'])) throw new Exception('السلة فارغة');

        // ✅ CSRF
        $csrf = (string) post('csrf_token', '');
        if (empty($_SESSION['csrf_token']) || !$csrf || !hash_equals($_SESSION['csrf_token'], $csrf)) {
            throw new Exception('CSRF token invalid');
        }

        $name = trim((string) post('name', 'ضيف'));
        $phone = trim((string) post('phone', ''));
        $payment = trim((string) post('payment', 'cash')); // cash | bank | online
        $address = trim((string) post('address', ''));

        if ($phone === '') throw new Exception('رقم الجوال مطلوب');

        // القادم من checkout (للمزامنة/التشخيص)
        $posted_gateway = trim((string) post('payment_gateway', ''));
        $posted_env = trim((string) post('payment_env', ''));

        // ✅ اقرأ فعلياً من النظام (مصدر الحقيقة)
        $runtime = pg_getRuntimeConfig();
        $system_gateway = $runtime['gateway'];
        $system_env = $runtime['env'];
        $settings = $runtime['settings'];

        // اعتمد النظام، وليس فقط ما أرسله العميل
        $gateway = $system_gateway ?: $posted_gateway;
        $env = in_array($system_env, ['test','live'], true) ? $system_env : (in_array($posted_env, ['test','live'], true) ? $posted_env : 'test');

        // تحقق من تفعيل التحويل البنكي عند اختيار bank
        if ($payment === 'bank') {
            $bank_enabled = ps_getSystemSetting('bank_transfer_enabled', '1') === '1';
            if (!$bank_enabled) throw new Exception('التحويل البنكي غير متاح حالياً');
        }

        // تحقق من جاهزية الدفع الإلكتروني عند اختيار online
        $online_available = false;
        if ($payment === 'online') {
            if (!$gateway) {
                throw new Exception('لا توجد بوابة دفع إلكتروني مفعّلة');
            }

            // مفاتيح مرنة: secret_key أو api_key أو (public+secret)
            $hasKey =
                !empty($settings['secret_key']) ||
                !empty($settings['api_key']) ||
                (!empty($settings['public_key']) && !empty($settings['secret_key']));

            $online_available = $hasKey;

            if (!$online_available) {
                throw new Exception('مفاتيح الدفع الإلكتروني غير مكتملة للبوابة: ' . $gateway);
            }
        }

        $pdo->beginTransaction();

        // حساب الإجمالي
        $total_price = 0;
        foreach ($_SESSION['cart'] as $item) {
            $total_price += ((float)$item['unit_price'] * (int)$item['qty']);
        }

        // ✅ حفظ طريقة الدفع بشكل واضح (بدون تعديل بنية DB)
        // مثال: online|stripe|test
        $payment_method_store = $payment;
        if ($payment === 'online') {
            $payment_method_store = 'online|' . $gateway . '|' . $env;
        }

        $stmt = $pdo->prepare("
            INSERT INTO individual_orders
              (customer_name, customer_phone, address, total_price, payment_method, status, created_at)
            VALUES
              (?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$name, $phone, $address, $total_price, $payment_method_store]);
        $order_id = (int) $pdo->lastInsertId();

        $stmt_item = $pdo->prepare("
            INSERT INTO individual_order_items
              (order_id, meal_id, quantity, price, options_json, selected_weight)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($_SESSION['cart'] as $item) {
            $opts_json = null;
            if (!empty($item['options'])) {
                $opts_json = json_encode(array_values($item['options']), JSON_UNESCAPED_UNICODE);
            }
            $sel_weight = $item['selected_weight'] ?? null;

            $stmt_item->execute([
                $order_id,
                (int)$item['id'],
                (int)$item['qty'],
                (float)$item['unit_price'],
                $opts_json,
                $sel_weight
            ]);
        }

        $pdo->commit();

        // ✅ تفريغ السلة بعد إنشاء الطلب
        unset($_SESSION['cart']);

        // ✅ إذا Online: رجّع رابط بدء الدفع
        if ($payment === 'online') {
            // token بسيط لحماية فتح صفحة الدفع (جلسة نفس المستخدم)
            $token = bin2hex(random_bytes(16));
            $_SESSION['payment_tokens'][$order_id] = $token;

            $payment_url = "payment_start.php?order_id=" . urlencode((string)$order_id) . "&t=" . urlencode($token);

            $response = [
                'status' => 'success',
                'order_id' => $order_id,
                'payment_url' => $payment_url,
                'gateway' => $gateway,
                'env' => $env
            ];
            json_out($response);
        }

        // cash / bank
        $response = ['status' => 'success', 'order_id' => $order_id];
        json_out($response);
    }

    // =========================================================
    // 4️⃣ حذف وتعديل
    // =========================================================
    if ($action === 'remove') {
        $key = (string) post('key', '');
        if ($key !== '' && isset($_SESSION['cart'][$key])) unset($_SESSION['cart'][$key]);
        $response = ['status' => 'success'];
        json_out($response);
    }

    if ($action === 'update_qty') {
        $key = (string) post('key', '');
        $qty = (int) post('qty', 1);
        if ($qty > 0 && $key !== '' && isset($_SESSION['cart'][$key])) {
            $_SESSION['cart'][$key]['qty'] = $qty;
        }
        $response = ['status' => 'success'];
        json_out($response);
    }

    $response = ['status' => 'error', 'message' => 'Unknown action: ' . $action];
    json_out($response);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $response = ['status' => 'error', 'message' => 'System Error: ' . $e->getMessage()];
    json_out($response);
}