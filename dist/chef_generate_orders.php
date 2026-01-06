<?php
// إجبار المتصفح على استخدام ترميز UTF-8
header('Content-Type: text/html; charset=utf-8');

// 1. تضمين "حارس الشيف" (للتأكد أن الشيف أو المدير فقط من يمكنه تشغيل هذا)
require_once 'auth_chef.php'; 

// 2. تضمين ملف الاتصال
require_once 'db_connect.php'; // يجلب $pdo

// 3. تحديد متغيرات "اليوم"
$today_date = date('Y-m-d'); // تاريخ اليوم (e.g., 2025-11-13)
$today_day_name = strtolower(date('l')); // اسم اليوم (e.g., 'thursday')

// 4. تحديد "تاريخ بدء الأسبوع" الصحيح
// (هذه هي النقطة الأصعب: يجب أن نجد تاريخ "السبت" الخاص بهذا الأسبوع)
$today_day_num = (int)date('w'); // 0=الأحد, 6=السبت
if ($today_day_num == 6) { // إذا كان اليوم هو السبت
    $week_start_date = $today_date;
} else {
    // ابحث عن "السبت الماضي"
    $week_start_date = date('Y-m-d', strtotime('last saturday'));
}

// 5. التحقق أولاً: هل تم إنشاء طلبات اليوم بالفعل؟
// (لمنع تشغيل "الخلاط" مرتين)
$stmt_check = $pdo->prepare("SELECT COUNT(*) FROM delivery_log WHERE delivery_date = ?");
$stmt_check->execute([$today_date]);
if ($stmt_check->fetchColumn() > 0) {
    // نعم، تم إنشاؤها. أعد توجيه الشيف
    header("Location: chef_dashboard.php?error=already_generated");
    exit;
}

// 6. بدء "العملية" (Transaction) لضمان السلامة
try {
    $pdo->beginTransaction();

    // 7. الاستعلام الرئيسي: جلب جميع الوجبات المخططة لهذا اليوم
    // (لجميع العملاء الذين اشتراكهم ساري)
    // هذا هو الاستعلام الذي يعمل مع نظام "الاختيار المتعدد"
    $sql = "SELECT 
                pd.client_id, 
                pdi.meal_id, 
                pdi.category
            FROM 
                plan_days AS pd
            JOIN 
                plan_day_items AS pdi ON pd.id = pdi.plan_day_id
            JOIN 
                client_details AS cd ON pd.client_id = cd.user_id
            WHERE 
                pd.week_start_date = ? 
                AND pd.day_name = ?
                AND cd.subscription_end_date >= ? -- (أهم شرط: التأكد أن الاشتراك ساري)
            ";
            
    $stmt_orders = $pdo->prepare($sql);
    $stmt_orders->execute([$week_start_date, $today_day_name, $today_date]);
    
    $orders_to_insert = $stmt_orders->fetchAll(PDO::FETCH_ASSOC);

    // 8. تجهيز جملة الإدخال في "سجل التوصيل"
    $sql_insert_log = "INSERT INTO delivery_log 
                            (client_id, meal_id, delivery_date, category, status) 
                       VALUES 
                            (?, ?, ?, ?, 'pending')";
    $stmt_insert_log = $pdo->prepare($sql_insert_log);

    // 9. المرور على الطلبات وإدخالها في السجل
    $inserted_count = 0;
    foreach ($orders_to_insert as $order) {
        $stmt_insert_log->execute([
            $order['client_id'],
            $order['meal_id'],
            $today_date,
            $order['category']
        ]);
        $inserted_count++;
    }

    // 10. تثبيت (Commit) العملية
    $pdo->commit();

    // 11. إعادة التوجيه إلى لوحة الشيف (مع رسالة نجاح)
    header("Location: chef_dashboard.php?success=generated&count=" . $inserted_count);
    exit;

} catch (Exception $e) {
    // 12. التراجع (Rollback) في حالة حدوث أي خطأ
    $pdo->rollBack();
    die("❌ حدث خطأ فادح أثناء إنشاء الطلبات: " . $e->getMessage());
}
?>