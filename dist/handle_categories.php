<?php
require_once 'auth_admin.php';
require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header("Location: manage_options.php");
  exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'add_category') {
  $name = trim($_POST['name'] ?? '');
  $sort = (int)($_POST['sort_order'] ?? 0);

  if ($name === '') {
    header("Location: manage_options.php?error=cat_missing");
    exit;
  }

  $stmt = $pdo->prepare("INSERT INTO option_categories (name, sort_order, is_active, created_at) VALUES (?, ?, 1, NOW())");
  $stmt->execute([$name, $sort]);

  header("Location: manage_options.php?success=cat_added");
  exit;
}

if ($action === 'toggle_category') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) {
    header("Location: manage_options.php?error=bad_id");
    exit;
  }

  $pdo->prepare("UPDATE option_categories SET is_active = IF(is_active=1,0,1) WHERE id=?")->execute([$id]);
  header("Location: manage_options.php?success=cat_toggled");
  exit;
}

header("Location: manage_options.php");
exit;
