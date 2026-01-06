<?php
// checkout.php - إتمام الطلب (مرتبط بإعدادات الدفع الجديدة)
// =================================================================

header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: text/html; charset=utf-8');
session_start();

// حماية الصفحة
if (empty($_SESSION['cart'])) {
    header("Location: menu.php");
    exit;
}

require_once 'db_connect.php';
require_once 'PaymentConfig.php'; // ✅ الربط مع إعدادات الدفع الجديدة

// CSRF بسيط لحماية طلب AJAX
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// حساب الإجمالي
$grand_total = 0;
$total_items = 0;
foreach ($_SESSION['cart'] as $item) {
    $grand_total += ($item['unit_price'] * $item['qty']);
    $total_items += $item['qty'];
}

// (اختياري) الفروع
$branches = [
    ['id' => 1, 'name' => 'الفرع الرئيسي - الرياض'],
    ['id' => 2, 'name' => 'فرع القصيم']
];

/* =========================================================
   ✅ جلب إعدادات الدفع من المركز الجديد
========================================================= */

// 1) التحويل البنكي
$bank_enabled   = ps_getSystemSetting('bank_transfer_enabled', '1') === '1';
$bank_name      = ps_getSystemSetting('bank_name','');
$account_name   = ps_getSystemSetting('account_name','');
$iban           = ps_getSystemSetting('iban','');
$stc_pay_number = ps_getSystemSetting('stc_pay_number','');

// 2) الدفع الإلكتروني (بوابة فعالة + مفاتيح)
$runtime = pg_getRuntimeConfig();
$active_gateway_code = $runtime['gateway'];  // مثال: stripe / moyasar / ...
$payment_env         = $runtime['env'];      // test/live
$gateway_settings    = $runtime['settings']; // key=>value

$gateway_name_ar = '';
$online_available = false;

// اسم البوابة من جدول payment_gateways
if (!empty($active_gateway_code)) {
    try {
        $stmtG = $pdo->prepare("SELECT name_ar, is_active FROM payment_gateways WHERE code = ? LIMIT 1");
        $stmtG->execute([$active_gateway_code]);
        $gRow = $stmtG->fetch(PDO::FETCH_ASSOC);
        if ($gRow && (int)$gRow['is_active'] === 1) {
            $gateway_name_ar = $gRow['name_ar'] ?: $active_gateway_code;

            // تحقق من وجود مفاتيح أساسية (مرنة)
            // نقبل public_key/secret_key أو api_key (حسب المنصة)
            $hasKey =
                !empty($gateway_settings['secret_key']) ||
                !empty($gateway_settings['api_key']) ||
                (!empty($gateway_settings['public_key']) && !empty($gateway_settings['secret_key']));

            $online_available = $hasKey;
        }
    } catch (Exception $e) {
        $online_available = false;
    }
}

// اختيار افتراضي لطريقة الدفع
$default_payment = 'cash';
if ($online_available) $default_payment = 'online';
elseif ($bank_enabled) $default_payment = 'bank';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>إتمام الطلب</title>

    <link rel="stylesheet" href="client_style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <style>
        :root { --primary: #6c5ce7; --bg-light: #f8f9fa; --text-dark: #2d3436; }

        body {
            background-color: var(--bg-light);
            font-family: 'Tajawal', sans-serif;
            padding-bottom: 50px; margin: 0; color: var(--text-dark);
        }

        /* الهيدر */
        .page-header {
            background: linear-gradient(135deg, var(--primary), #8e44ad);
            padding: 20px 20px 40px 20px; color: white;
            border-radius: 0 0 30px 30px;
            box-shadow: 0 4px 20px rgba(108, 92, 231, 0.2);
            margin-bottom: -30px; position: relative; z-index: 1;
        }
        .header-top { display: flex; align-items: center; gap: 15px; margin-bottom: 5px; }
        .back-btn { color: white; font-size: 1.4rem; text-decoration: none; }
        .header-title { font-size: 1.5rem; font-weight: 800; margin: 0; }

        /* الحاويات */
        .checkout-container { padding: 0 20px; max-width: 600px; margin: 0 auto; position: relative; z-index: 2; }

        /* البطاقات */
        .card-box {
            background: #fff; border-radius: 20px; padding: 25px 20px; margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.04); border: 1px solid #fff;
        }

        /* أزرار التبديل (توصيل/استلام) */
        .delivery-switch {
            display: flex; background: #f1f2f6; padding: 5px; border-radius: 15px; margin-bottom: 20px;
        }
        .switch-opt {
            flex: 1; text-align: center; padding: 12px; border-radius: 12px; cursor: pointer;
            font-weight: 700; color: #636e72; transition: 0.3s; display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .switch-opt.active { background: #fff; color: var(--primary); box-shadow: 0 2px 10px rgba(0,0,0,0.05); }

        /* حقول الموقع */
        .geo-btn {
            background: #e0e7ff; color: var(--primary); border: 2px dashed var(--primary);
            padding: 12px; border-radius: 12px; width: 100%; font-weight: bold; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 15px;
            transition: 0.2s;
        }
        .geo-btn:hover { background: var(--primary); color: #fff; border-style: solid; }
        .geo-btn.success { background: #d1fae5; color: #059669; border-color: #059669; }

        /* النماذج */
        .form-group { margin-bottom: 15px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 700; color: #555; font-size: 0.9rem; }
        .form-input {
            width: 100%; padding: 14px; border: 2px solid #f1f2f6; border-radius: 12px;
            font-size: 1rem; box-sizing: border-box; font-family: inherit; background: #fdfdfd;
        }
        .form-input:focus { border-color: var(--primary); outline: none; background: #fff; }

        /* خيارات الدفع */
        .payment-methods { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .pay-option { display: none; }
        .pay-card {
            padding: 15px; border: 2px solid #f1f2f6; border-radius: 15px;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            cursor: pointer; transition: 0.2s; height: 90px; background: #fff; text-align: center;
        }
        .pay-icon { font-size: 1.8rem; color: #b2bec3; margin-bottom: 8px; }
        .pay-text { font-weight: bold; color: #636e72; font-size: 0.85rem; }

        .pay-option:checked + .pay-card { border-color: var(--primary); background: #f0f0ff; }
        .pay-option:checked + .pay-card .pay-icon { color: var(--primary); }
        .pay-option:checked + .pay-card .pay-text { color: var(--primary); }

        /* صندوق معلومات البنك */
        .bank-info {
            margin-top: 14px;
            padding: 14px;
            border-radius: 14px;
            border: 2px dashed #e5e7eb;
            background: #fafafa;
            display: none;
            line-height: 1.8;
            font-weight: 700;
        }
        .bank-info .k { color:#6b7280; font-weight:800; }
        .bank-info .v { color:#111827; font-weight:900; }
        .copy-mini {
            margin-top: 10px;
            display:flex; gap:8px; flex-wrap:wrap;
        }
        .copy-mini button{
            border:none; cursor:pointer;
            padding:10px 12px; border-radius:12px;
            background:#f3f4f6; font-weight:900;
        }

        /* زر التأكيد */
        .btn-submit {
            width: 100%; padding: 18px; background: linear-gradient(135deg, var(--primary), #8e44ad);
            color: white; border: none; border-radius: 16px; font-weight: 800; font-size: 1.2rem;
            cursor: pointer; box-shadow: 0 10px 25px rgba(108, 92, 231, 0.4);
            display: flex; justify-content: center; align-items: center; gap: 12px; margin-top: 10px;
        }
        .btn-submit:disabled { background: #ccc; cursor: not-allowed; box-shadow: none; }

        /* شاشات التحميل */
        .status-overlay {
            position: fixed; inset: 0; background: rgba(255,255,255,0.95); z-index: 5000;
            display: none; justify-content: center; align-items: center; flex-direction: column;
        }
        .spinner {
            width: 50px; height: 50px; border: 5px solid #eee; border-top-color: var(--primary);
            border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 20px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .success-icon { font-size: 4rem; color: #2ecc71; margin-bottom: 15px; }
        .order-num { font-size: 1.5rem; color: var(--primary); font-weight: 800; display: block; margin: 10px 0; }
        .btn-home { background: #2d3436; color: white; padding: 12px 30px; border-radius: 50px; text-decoration: none; font-weight: bold; margin-top: 20px; display: inline-block; }

        .note-pill{
            display:inline-flex; align-items:center; gap:8px;
            background:#111827; color:#fff;
            padding:8px 12px; border-radius:999px;
            font-weight:900; font-size:.85rem;
            margin-top:10px;
            opacity:.9;
        }
    </style>
</head>
<body>

    <div class="page-header">
        <div class="header-top">
            <a href="cart.php" class="back-btn"><i class="fas fa-arrow-right"></i></a>
            <h2 class="header-title">إتمام الطلب</h2>
        </div>
        <div style="opacity: 0.9; font-size: 0.95rem;">
            إجمالي: <strong><?php echo number_format($grand_total, 2); ?> ر.س</strong> (<?php echo $total_items; ?> وجبات)
        </div>

        <?php if ($online_available): ?>
            <div class="note-pill">
                <i class="fas fa-plug"></i>
                الدفع الإلكتروني متاح عبر: <?php echo htmlspecialchars($gateway_name_ar); ?> (<?php echo htmlspecialchars($payment_env); ?>)
            </div>
        <?php endif; ?>
    </div>

    <div class="checkout-container">
        <form id="orderForm" onsubmit="submitOrder(event)">

            <div class="card-box" style="margin-top: -20px;">
                <div class="section-title" style="margin-bottom:15px; font-weight:800;">كيف تريد استلام طلبك؟</div>

                <div class="delivery-switch">
                    <div class="switch-opt active" onclick="toggleDelivery('delivery')">
                        <i class="fas fa-motorcycle"></i> توصيل
                    </div>
                    <div class="switch-opt" onclick="toggleDelivery('pickup')">
                        <i class="fas fa-store"></i> استلام من الفرع
                    </div>
                </div>
                <input type="hidden" name="order_type" id="orderType" value="delivery">

                <div id="deliveryFields">
                    <div class="form-group">
                        <label class="form-label">موقعك (للسائق) <span style="color:red">*</span></label>

                        <div class="geo-btn" onclick="getLocation()" id="geoBtn">
                            <i class="fas fa-map-marker-alt"></i> اضغط لتحديد موقعك الحالي
                        </div>

                        <input type="url" name="location_url" id="locationUrl" class="form-control form-input" placeholder="أو الصق رابط جوجل ماب هنا..." required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">تفاصيل العنوان (الحي، الشارع..)</label>
                        <textarea name="address_details" id="addrDetails" class="form-input" rows="2" placeholder="مثال: بجوار مسجد...، الدور الثاني"></textarea>
                    </div>
                </div>

                <div id="pickupFields" style="display:none;">
                    <div class="form-group">
                        <label class="form-label">اختر الفرع:</label>
                        <select name="branch_name" id="branchName" class="form-input">
                            <?php foreach($branches as $b): ?>
                                <option value="<?php echo $b['name']; ?>"><?php echo $b['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="card-box">
                <div class="section-title" style="margin-bottom:15px; font-weight:800;">بيانات التواصل</div>
                <div class="form-group">
                    <label class="form-label">الاسم <span style="color:red">*</span></label>
                    <input type="text" name="name" class="form-input" placeholder="اسمك الكريم" required>
                </div>
                <div class="form-group">
                    <label class="form-label">رقم الجوال <span style="color:red">*</span></label>
                    <input type="tel" name="phone" class="form-input" placeholder="05xxxxxxxx" required pattern="[0-9]{10}" title="10 أرقام">
                </div>
            </div>

            <div class="card-box">
                <div class="section-title" style="margin-bottom:15px; font-weight:800;">طريقة الدفع</div>

                <div class="payment-methods">
                    <!-- 1) الدفع عند الاستلام (دائماً) -->
                    <label>
                        <input type="radio" name="payment" value="cash" class="pay-option" <?php echo $default_payment==='cash'?'checked':''; ?>>
                        <div class="pay-card">
                            <i class="fas fa-money-bill-wave pay-icon"></i>
                            <span class="pay-text">دفع عند الاستلام</span>
                        </div>
                    </label>

                    <!-- 2) التحويل البنكي (حسب التفعيل) -->
                    <?php if ($bank_enabled): ?>
                    <label>
                        <input type="radio" name="payment" value="bank" class="pay-option" <?php echo $default_payment==='bank'?'checked':''; ?>>
                        <div class="pay-card">
                            <i class="fas fa-university pay-icon"></i>
                            <span class="pay-text">تحويل بنكي</span>
                        </div>
                    </label>
                    <?php endif; ?>

                    <!-- 3) الدفع الإلكتروني (حسب البوابة + المفاتيح) -->
                    <?php if ($online_available): ?>
                    <label>
                        <input type="radio" name="payment" value="online" class="pay-option" <?php echo $default_payment==='online'?'checked':''; ?>>
                        <div class="pay-card">
                            <i class="far fa-credit-card pay-icon"></i>
                            <span class="pay-text">دفع إلكتروني (<?php echo htmlspecialchars($gateway_name_ar); ?>)</span>
                        </div>
                    </label>
                    <?php endif; ?>
                </div>

                <?php if ($bank_enabled): ?>
                <div class="bank-info" id="bankInfo">
                    <div><span class="k">اسم البنك:</span> <span class="v" id="b_bank"><?php echo htmlspecialchars($bank_name); ?></span></div>
                    <div><span class="k">اسم الحساب:</span> <span class="v" id="b_acc"><?php echo htmlspecialchars($account_name); ?></span></div>
                    <div><span class="k">IBAN:</span> <span class="v" id="b_iban"><?php echo htmlspecialchars($iban); ?></span></div>
                    <div><span class="k">STC Pay:</span> <span class="v" id="b_stc"><?php echo htmlspecialchars($stc_pay_number); ?></span></div>

                    <div class="copy-mini">
                        <button type="button" onclick="copyText('#b_iban')"><i class="fas fa-copy"></i> نسخ IBAN</button>
                        <button type="button" onclick="copyText('#b_stc')"><i class="fas fa-copy"></i> نسخ STC</button>
                    </div>

                    <div style="margin-top:10px;color:#6b7280;font-weight:800;">
                        بعد التحويل سيتم التواصل معك لتأكيد العملية.
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn">
                <span>تأكيد الطلب</span>
                <i class="fas fa-check-circle"></i>
            </button>
        </form>
    </div>

    <div class="status-overlay" id="statusScreen">
        <div id="loadingView" style="text-align:center;">
            <div class="spinner"></div>
            <h3>جاري إرسال طلبك...</h3>
        </div>

        <div id="successView" style="display:none; text-align:center; padding:20px;">
            <div class="success-icon"><i class="fas fa-check-circle"></i></div>
            <h2>تم الطلب بنجاح!</h2>
            <p>شكراً لك، طلبك وصلنا.</p>
            <span class="order-num" id="orderIdDisp">#---</span>
            <a href="menu.php" class="btn-home">عودة للقائمة</a>
        </div>
    </div>

<script>
    // تبديل بين التوصيل والاستلام
    function toggleDelivery(type) {
        $('.switch-opt').removeClass('active');
        $('#orderType').val(type);

        if (type === 'delivery') {
            $('.switch-opt:first-child').addClass('active');
            $('#deliveryFields').slideDown();
            $('#pickupFields').slideUp();
            $('#locationUrl').prop('required', true);
        } else {
            $('.switch-opt:last-child').addClass('active');
            $('#deliveryFields').slideUp();
            $('#pickupFields').slideDown();
            $('#locationUrl').prop('required', false);
        }
    }

    // 📍 تحديد الموقع الجغرافي
    function getLocation() {
        let btn = $('#geoBtn');

        if (navigator.geolocation) {
            btn.html('<i class="fas fa-spinner fa-spin"></i> جاري تحديد الموقع...');

            navigator.geolocation.getCurrentPosition(
                function(position) {
                    let lat = position.coords.latitude;
                    let lng = position.coords.longitude;
                    let mapLink = `https://www.google.com/maps?q=${lat},${lng}`;

                    $('#locationUrl').val(mapLink);
                    btn.addClass('success').html('<i class="fas fa-check"></i> تم تحديد موقعك بنجاح!');
                },
                function() {
                    btn.html('<i class="fas fa-exclamation-triangle"></i> تعذر تحديد الموقع، يرجى كتابته يدوياً');
                    alert('تأكد من تفعيل خدمة الموقع (GPS) في متصفحك.');
                }
            );
        } else {
            alert("المتصفح لا يدعم تحديد الموقع.");
        }
    }

    // إظهار/إخفاء معلومات البنك حسب اختيار الدفع
    function syncBankInfo(){
        const pay = $('input[name="payment"]:checked').val();
        if(pay === 'bank'){
            $('#bankInfo').slideDown();
        } else {
            $('#bankInfo').slideUp();
        }
    }
    $(document).on('change', 'input[name="payment"]', syncBankInfo);
    $(document).ready(syncBankInfo);

    function copyText(selector){
        const txt = $(selector).text().trim();
        navigator.clipboard.writeText(txt);
        alert('تم النسخ');
    }

    // إرسال الطلب
    function submitOrder(e) {
        e.preventDefault();

        let btn = $('#submitBtn');
        let originalText = btn.html();
        btn.prop('disabled', true).html('جاري المعالجة...');
        $('#statusScreen').fadeIn();

        // تجهيز العنوان (للسائق)
        let finalAddress = "";
        let type = $('#orderType').val();

        if (type === 'delivery') {
            let loc = $('#locationUrl').val();
            let det = $('#addrDetails').val();
            finalAddress = `موقع: ${loc} \n تفاصيل: ${det}`;
        } else {
            finalAddress = "استلام من فرع: " + $('#branchName').val();
        }

        // ✅ بيانات الدفع الجديدة (بوابة + بيئة) — تُرسل دائماً حتى لو cash
        let formData = {
            action: 'checkout',
            csrf_token: '<?php echo htmlspecialchars($csrf_token); ?>',

            name: $('input[name="name"]').val(),
            phone: $('input[name="phone"]').val(),
            payment: $('input[name="payment"]:checked').val(),
            address: finalAddress,

            // ✅ ربط مع إعدادات الدفع الجديدة
            payment_gateway: '<?php echo htmlspecialchars($active_gateway_code); ?>',
            payment_env: '<?php echo htmlspecialchars($payment_env); ?>'
        };

        $.ajax({
            url: 'cart_action.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(res) {
                if (res.status === 'success') {

                    // ✅ لو الدفع الإلكتروني يرجع رابط بوابة الدفع
                    if (res.payment_url && res.payment_url.length > 5) {
                        window.location.href = res.payment_url;
                        return;
                    }

                    $('#loadingView').hide();
                    $('#orderIdDisp').text('#' + res.order_id);
                    $('#successView').show();
                } else {
                    $('#statusScreen').fadeOut();
                    alert(res.message || 'حدث خطأ');
                    btn.prop('disabled', false).html(originalText);
                }
            },
            error: function() {
                $('#statusScreen').fadeOut();
                alert('خطأ في الاتصال.');
                btn.prop('disabled', false).html(originalText);
            }
        });
    }
</script>

</body>
</html>