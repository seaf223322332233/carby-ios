<?php
// payment_admin_export.php - Export payment transactions as CSV
// ==============================================================================
require_once 'auth_admin.php';
require_once 'db_connect.php';

function g($k,$d=''){ return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d; }

$f_status = g('status','');
$f_gateway = g('gateway','');
$f_env = g('env','');
$f_q = g('q','');
$f_order_type = g('order_type','');
$f_date_from = g('from','');
$f_date_to = g('to','');

$where = [];
$params = [];

if ($f_status !== '') { $where[] = "status = ?"; $params[] = $f_status; }
if ($f_gateway !== '') { $where[] = "gateway_code = ?"; $params[] = $f_gateway; }
if ($f_env !== '') { $where[] = "env = ?"; $params[] = $f_env; }
if ($f_order_type !== '') { $where[] = "order_type = ?"; $params[] = $f_order_type; }
if ($f_date_from !== '') { $where[] = "DATE(created_at) >= ?"; $params[] = $f_date_from; }
if ($f_date_to !== '') { $where[] = "DATE(created_at) <= ?"; $params[] = $f_date_to; }

if ($f_q !== '') {
    $where[] = "(id = ? OR provider_ref LIKE ? OR order_id = ? OR user_id = ?)";
    $params[] = (int)$f_q;
    $params[] = "%".$f_q."%";
    $params[] = (int)$f_q;
    $params[] = (int)$f_q;
}

$whereSql = $where ? ("WHERE ".implode(" AND ", $where)) : "";

$stmt = $pdo->prepare("
  SELECT
    id, status, amount, currency, gateway_code, env,
    order_type, order_id, user_id, provider_ref,
    created_at, updated_at
  FROM payment_transactions
  $whereSql
  ORDER BY id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Output CSV
$filename = "payments_export_" . date('Ymd_His') . ".csv";
header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=$filename");

$out = fopen("php://output", "w");

// BOM for Excel Arabic
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, array_keys($rows[0] ?? [
  'id','status','amount','currency','gateway_code','env','order_type','order_id','user_id','provider_ref','created_at','updated_at'
]));

foreach ($rows as $r) {
    fputcsv($out, $r);
}
fclose($out);
exit;
