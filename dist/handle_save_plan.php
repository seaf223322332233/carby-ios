<?php
// handle_save_plan.php - (مع حفظ الخيارات)
session_start();
require_once 'db_connect.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    $client_id = $_SESSION['user_id'];
    $plan = $_POST['plan'] ?? []; 
    // مصفوفة الخيارات الجديدة: meal_options[Day][MealID][] = JSON_String
    $meal_options = $_POST['meal_options'] ?? []; 

    if (empty($plan)) {
        die("خطأ: لم يتم اختيار أي وجبة.");
    }

    $w = date('w');
    $week_start = ($w == 6) ? date('Y-m-d') : date('Y-m-d', strtotime('last saturday'));

    try {
        $pdo->beginTransaction();

        foreach ($plan as $day_name => $meal_ids) {
            if (!is_array($meal_ids)) $meal_ids = [$meal_ids];

            // 1. إدارة اليوم (إنشاء أو جلب)
            $stmt = $pdo->prepare("SELECT id FROM plan_days WHERE client_id=? AND week_start_date=? AND day_name=?");
            $stmt->execute([$client_id, $week_start, $day_name]);
            $day_id = $stmt->fetchColumn();

            if (!$day_id) {
                $ins_day = $pdo->prepare("INSERT INTO plan_days (client_id, week_start_date, day_name) VALUES (?, ?, ?)");
                $ins_day->execute([$client_id, $week_start, $day_name]);
                $day_id = $pdo->lastInsertId();
            } else {
                // حذف القديم
                $pdo->prepare("DELETE FROM plan_day_items WHERE plan_day_id=?")->execute([$day_id]);
            }

            // 2. إدخال الوجبات + الخيارات
            $ins_item = $pdo->prepare("INSERT INTO plan_day_items (plan_day_id, meal_id, options_json) VALUES (?, ?, ?)");
            
            foreach ($meal_ids as $mid) {
                if(!empty($mid)) {
                    // هل توجد خيارات مختارة لهذه الوجبة في هذا اليوم؟
                    $json_data = null;
                    if (isset($meal_options[$day_name][$mid])) {
                        // البيانات تأتي كـ JSON Strings في مصفوفة، نحتاج لفكها ثم تجميعها
                        $final_opts = [];
                        foreach($meal_options[$day_name][$mid] as $opt_str) {
                            $final_opts[] = json_decode($opt_str, true);
                        }
                        $json_data = json_encode($final_opts, JSON_UNESCAPED_UNICODE);
                    }

                    $ins_item->execute([$day_id, $mid, $json_data]);
                }
            }
        }

        $pdo->commit();
        header("Location: client_package.php?saved=1");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die("حدث خطأ: " . $e->getMessage());
    }
}
?>