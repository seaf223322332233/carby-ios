<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Content-Type: text/html; charset=utf-8");

require_once 'auth_driver.php';
require_once 'db_connect.php';

$action = $_GET['action'] ?? '';
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

if($client_id <= 0) { header("Location: driver_dashboard.php"); exit; }

$today = date('Y-m-d');

// helper: insert notif
function pushNotif($pdo, $client_id, $title, $msg){
    $stmt = $pdo->prepare("INSERT INTO notifications (client_id, title, message, link, type) VALUES (?, ?, ?, 'my_orders.php', 'driver')");
    $stmt->execute([$client_id, $title, $msg]);
}

try{
    if($action === 'notify_near'){
        pushNotif($pdo, $client_id, "السائق قريب منك 🔔", "السائق في الطريق ووصل قريبًا.");
        header("Location: driver_dashboard.php?success=near"); exit;
    }

    if($action === 'pickup'){
        // استلام من المطعم: اجعلها out_for_delivery
        $stmt = $pdo->prepare("UPDATE delivery_log SET status='out_for_delivery', driver_id=? WHERE client_id=? AND delivery_date=? AND status='prepared'");
        $stmt->execute([$user_id_session, $client_id, $today]);

        pushNotif($pdo, $client_id, "تم استلام طلبك 🛵", "السائق استلم طلبك وبدأ التوصيل.");
        header("Location: driver_dashboard.php?success=1"); exit;
    }

    if($action === 'deliver'){
        $stmt = $pdo->prepare("UPDATE delivery_log SET status='delivered', delivered_at=NOW() WHERE client_id=? AND delivery_date=? AND driver_id=? AND status='out_for_delivery'");
        $stmt->execute([$client_id, $today, $user_id_session]);

        pushNotif($pdo, $client_id, "تم تسليم طلبك ✅", "تم تسليم طلبك بنجاح. نتمنى لك وجبة شهية!");
        header("Location: driver_dashboard.php?success=1"); exit;
    }

} catch(Exception $e) {}

header("Location: driver_dashboard.php");
exit;
