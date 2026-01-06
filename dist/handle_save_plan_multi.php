<?php
// تفعيل الأخطاء للتشخيص
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_SESSION['user_id'])) {
    $client_id = $_SESSION['user_id'];
    $meals_input = $_POST['meal'] ?? []; // [Day][Category] => MealID (or Array)
    
    // --- توحيد منطق الأسبوع (السبت هو البداية) ---
    // نحسب تاريخ "آخر سبت" ليكون هو مفتاح الأسبوع الحالي
    $today_w = date('w'); // 6=Sat, 0=Sun, ...
    if ($today_w == 6) {
        $week_start_date = date('Y-m-d'); // اليوم هو السبت
    } else {
        $week_start_date = date('Y-m-d', strtotime('last saturday'));
    }

    try {
        $pdo->beginTransaction();

        // 1. حذف الخطة القديمة لهذا الأسبوع (تحديث كامل)
        $stmt_check = $pdo->prepare("SELECT id FROM plan_days WHERE client_id = ? AND week_start_date = ?");
        $stmt_check->execute([$client_id, $week_start_date]);
        $old_ids = $stmt_check->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($old_ids)) {
            $ids_str = implode(',', $old_ids);
            $pdo->exec("DELETE FROM plan_day_items WHERE plan_day_id IN ($ids_str)");
            $pdo->exec("DELETE FROM plan_days WHERE id IN ($ids_str)");
        }

        // 2. حفظ الخطة الجديدة
        $stmt_day = $pdo->prepare("INSERT INTO plan_days (client_id, day_name, week_start_date) VALUES (?, ?, ?)");
        $stmt_item = $pdo->prepare("INSERT INTO plan_day_items (plan_day_id, meal_id, category) VALUES (?, ?, ?)");

        foreach ($meals_input as $day_name => $categories) {
            // إنشاء اليوم
            $stmt_day->execute([$client_id, $day_name, $week_start_date]);
            $day_id = $pdo->lastInsertId();

            // حفظ الوجبات
            foreach ($categories as $cat => $val) {
                // إذا كان اختيار واحد (Radio) يأتي كقيمة، نحوله لمصفوفة
                $meal_ids = is_array($val) ? $val : [$val];
                
                foreach ($meal_ids as $mid) {
                    if (!empty($mid)) {
                        $stmt_item->execute([$day_id, $mid, $cat]);
                    }
                }
            }
        }

        $pdo->commit();
        header("Location: client_package.php?success=1");

    } catch (Exception $e) {
        $pdo->rollBack();
        die("خطأ في الحفظ: " . $e->getMessage());
    }
} else {
    header("Location: client_package.php");
}
?>