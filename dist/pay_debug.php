<?php
header('Content-Type: text/plain; charset=utf-8');
echo "PAY_DEBUG OK\n";

echo "PHP_VERSION: " . PHP_VERSION . "\n";
echo "FILE: " . __FILE__ . "\n";
echo "DIR: " . __DIR__ . "\n\n";

$files = array(
  'auth_admin.php',
  'db_connect.php',
  'PaymentConfig.php',
  'PaymentEngine.php',
  'PaymentApply.php',
  'sidebar.php',
);

foreach ($files as $f) {
  $p = __DIR__ . '/' . $f;
  echo $f . ' => ' . (file_exists($p) ? 'FOUND' : 'MISSING') . "\n";
}

echo "\nTry include db_connect.php...\n";
if (file_exists(__DIR__.'/db_connect.php')) {
  include __DIR__.'/db_connect.php';
  echo "db_connect.php included.\n";
  echo isset($pdo) ? "PDO exists.\n" : "PDO NOT set.\n";
}

echo "\nDONE\n";
