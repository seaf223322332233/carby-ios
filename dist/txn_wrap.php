<?php
// txn_wrap.php - wraps the transactions page and prints fatal error details
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('html_errors', 0);
error_reporting(E_ALL);

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "FATAL\n{$e['message']}\nFILE: {$e['file']}\nLINE: {$e['line']}\n";
        exit;
    }
});

ob_start();
include __DIR__ . '/payment_admin_transactions.php';
$out = ob_get_clean();

header('Content-Type: text/html; charset=utf-8');
echo $out !== '' ? $out : "<pre>NO OUTPUT (but no fatal). If you still see 500 without wrapper, it's server config / rewrite.</pre>";
