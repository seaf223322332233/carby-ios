<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(null); exit; }

require_once 'db_connect.php';
$user_id = (int)$_SESSION['user_id'];

// آخر إشعار شافه العميل
$last_seen = 0;
$stmt = $pdo->prepare("SELECT last_seen_id FROM user_notification_state WHERE user_id=? LIMIT 1");
$stmt->execute([$user_id]);
$last_seen = (int)($stmt->fetchColumn() ?: 0);

// اجلب إشعار جديد فقط (خاص بالعميل)
$stmt = $pdo->prepare("
  SELECT id, title, message, image, link
  FROM notifications
  WHERE client_id = ? AND id > ?
  ORDER BY id ASC
  LIMIT 1
");
$stmt->execute([$user_id, $last_seen]);
$n = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$n) { echo json_encode(null); exit; }

// نفس مفاتيح الداشبورد
echo json_encode([
  'id'    => (int)$n['id'],
  'title' => $n['title'] ?: 'إشعار',
  'msg'   => $n['message'] ?: '',
  'img'   => !empty($n['image']) ? $n['image'] : null,
  'link'  => !empty($n['link']) ? $n['link'] : null
]);
