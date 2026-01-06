<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['ok'=>false]); exit; }

require_once 'db_connect.php';
$user_id = (int)$_SESSION['user_id'];

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) { echo json_encode(['ok'=>false]); exit; }

$stmt = $pdo->prepare("
  INSERT INTO user_notification_state (user_id, last_seen_id)
  VALUES (?, ?)
  ON DUPLICATE KEY UPDATE last_seen_id = GREATEST(last_seen_id, VALUES(last_seen_id))
");
$stmt->execute([$user_id, $id]);

echo json_encode(['ok'=>true]);
