<?php
// PaymentConfig.php
// ======================================================================
// طبقة موحّدة للتعامل مع إعدادات الدفع (system_settings + gateway_settings)
// ======================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ===============================
   System Settings (system_settings)
================================ */

function ps_getSystemSetting(string $key, string $default=''): string
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1");
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    if ($v === false || $v === null) return $default;
    return (string)$v;
}

function ps_setSystemSetting(string $key, string $value): void
{
    global $pdo;
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$key, $value]);
}

/* ===============================
   Active Gateway (payment_gateways)
================================ */

function pg_getActiveGatewayCode(): string
{
    global $pdo;
    $stmt = $pdo->query("
        SELECT code
        FROM payment_gateways
        WHERE is_active=1
        ORDER BY sort_order ASC, id ASC
        LIMIT 1
    ");
    $code = $stmt->fetchColumn();
    return $code ? (string)$code : '';
}

function pg_setActiveGateway(string $code): void
{
    global $pdo;

    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE payment_gateways SET is_active=0");
        $stmt = $pdo->prepare("UPDATE payment_gateways SET is_active=1 WHERE code=? LIMIT 1");
        $stmt->execute([$code]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* ===============================
   Gateway Settings (payment_gateway_settings)
================================ */

function pg_setSetting(string $gateway, string $env, string $key, string $value): void
{
    global $pdo;

    if (!in_array($env, ['test','live'], true)) $env = 'test';

    $stmt = $pdo->prepare("
        INSERT INTO payment_gateway_settings (gateway_code, env, setting_key, setting_value)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$gateway, $env, $key, $value]);
}

function pg_getSettings(string $gateway, string $env): array
{
    global $pdo;

    if (!in_array($env, ['test','live'], true)) $env = 'test';

    $stmt = $pdo->prepare("
        SELECT setting_key, setting_value
        FROM payment_gateway_settings
        WHERE gateway_code=? AND env=?
    ");
    $stmt->execute([$gateway, $env]);

    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    return $rows ?: [];
}

function pg_getSetting(string $gateway, string $env, string $key, string $default=''): string
{
    $all = pg_getSettings($gateway, $env);
    if (!array_key_exists($key, $all)) return $default;
    return (string)$all[$key];
}

/* ===============================
   Payment Environment Helpers
================================ */

function ps_getPaymentEnv(): string
{
    $env = ps_getSystemSetting('payment_env', 'test');
    if (!in_array($env, ['test','live'], true)) $env = 'test';
    return $env;
}

function ps_setPaymentEnv(string $env): void
{
    if (!in_array($env, ['test','live'], true)) $env = 'test';
    ps_setSystemSetting('payment_env', $env);
}

/* ===============================
   Base URL Helpers
   - مهم جداً لتوليد روابط return/webhook الصحيحة
================================ */

function ps_getAppBaseUrl(): string
{
    // لو تحب تثبيتها: ضعها في system_settings: app_base_url
    $saved = trim(ps_getSystemSetting('app_base_url', ''));
    if ($saved !== '') return rtrim($saved, '/');

    // fallback تلقائي
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}