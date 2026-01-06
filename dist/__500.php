<?php
// __500.php - show the real fatal error (no guessing)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('html_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/__500_error.log');
error_reporting(E_ALL);

register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "FATAL\n{$e['message']}\nFILE: {$e['file']}\nLINE: {$e['line']}\n";
  }
});

header('Content-Type: text/plain; charset=utf-8');

echo "PHP ".PHP_VERSION."\n";
echo "1) auth_admin...\n";
require __DIR__ . '/auth_admin.php';
echo "OK auth_admin\n";

echo "2) db_connect...\n";
require __DIR__ . '/db_connect.php';
echo "OK db_connect\n";

echo "3) PaymentConfig...\n";
require __DIR__ . '/PaymentConfig.php';
echo "OK PaymentConfig\n";

echo "DONE\n";
