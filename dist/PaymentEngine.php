<?php
// PaymentEngine.php
// ======================================================================
// محرك الدفع الموحد: إنشاء معاملات - تشغيل الدفع - تسجيل الأحداث - تحديث الحالة
// ======================================================================

require_once 'db_connect.php';
require_once 'PaymentConfig.php';

class PaymentEngine
{
    /* ===============================
       TXN CRUD
    ================================ */

    public static function createTxn(
        string $orderType,
        int $orderId,
        int $userId,
        float $amount,
        string $currency = 'SAR'
    ): int {
        global $pdo;

        $gateway = pg_getActiveGatewayCode();
        $env     = ps_getPaymentEnv();

        if ($gateway === '') {
            throw new Exception("No active gateway set");
        }
        if ($amount <= 0) {
            throw new Exception("Invalid amount");
        }

        $stmt = $pdo->prepare("
            INSERT INTO payment_transactions
            (order_type, order_id, user_id, gateway_code, env, amount, currency, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'created')
        ");
        $stmt->execute([
            $orderType,
            $orderId,
            $userId,
            $gateway,
            $env,
            number_format($amount, 2, '.', ''),
            $currency
        ]);

        return (int)$pdo->lastInsertId();
    }

    public static function getTxn(int $txnId): array
    {
        global $pdo;
        $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE id=? LIMIT 1");
        $stmt->execute([$txnId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: [];
    }

    public static function setTxnStatus(int $txnId, string $status, ?string $providerRef = null, $payload = null): void
    {
        global $pdo;

        $allowed = ['created','pending','paid','failed','canceled','refunded'];
        if (!in_array($status, $allowed, true)) $status = 'pending';

        $payloadJson = ($payload === null) ? null : json_encode($payload, JSON_UNESCAPED_UNICODE);

        $stmt = $pdo->prepare("
            UPDATE payment_transactions
            SET status=?,
                provider_ref=COALESCE(?, provider_ref),
                provider_payload=COALESCE(?, provider_payload)
            WHERE id=?
            LIMIT 1
        ");
        $stmt->execute([$status, $providerRef, $payloadJson, $txnId]);
    }

    /* ===============================
       Events Logger
    ================================ */

    public static function logEvent(string $gateway, string $env, ?int $txnId, string $type, array $headers, $payload): void
    {
        global $pdo;

        if (!in_array($env, ['test','live'], true)) $env = 'test';

        $stmt = $pdo->prepare("
            INSERT INTO payment_events (gateway_code, env, txn_id, event_type, headers, payload)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $gateway,
            $env,
            $txnId,
            $type,
            json_encode($headers, JSON_UNESCAPED_UNICODE),
            json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /* ===============================
       Start Payment (Router)
    ================================ */

    public static function startPayment(int $txnId, array $customer = []): array
    {
        $txn = self::getTxn($txnId);
        if (!$txn) {
            return ['ok'=>false, 'error'=>'TXN_NOT_FOUND'];
        }

        $gateway = $txn['gateway_code'];
        $env     = $txn['env'];

        // подчерк: كل بوابة لها Adapter خاص
        switch ($gateway) {
            case 'moyasar':
                require_once __DIR__ . '/gateways/MoyasarAdapter.php';
                $adapter = new MoyasarAdapter($env);
                $res = $adapter->init($txn, $customer);
                break;

            case 'paytabs':
                require_once __DIR__ . '/gateways/PayTabsAdapter.php';
                $adapter = new PayTabsAdapter($env);
                $res = $adapter->init($txn, $customer);
                break;

            case 'hyperpay':
                require_once __DIR__ . '/gateways/HyperPayAdapter.php';
                $adapter = new HyperPayAdapter($env);
                $res = $adapter->init($txn, $customer);
                break;

            default:
                return ['ok'=>false, 'error'=>'GATEWAY_NOT_SUPPORTED', 'gateway'=>$gateway];
        }

        // تخزين الحالة + provider_ref إن وجد
        if (!empty($res['ok'])) {
            self::setTxnStatus($txnId, 'pending', $res['provider_ref'] ?? null, $res);
        } else {
            self::setTxnStatus($txnId, 'failed', null, $res);
        }

        return $res;
    }

    /* ===============================
       Helpers
    ================================ */

    public static function baseUrl(): string
    {
        return ps_getAppBaseUrl();
    }

    public static function getHeadersSafe(): array
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $headers[$name] = $v;
            }
        }
        return $headers;
    }

    public static function httpJson(string $method, string $url, array $headers = [], $body = null): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_TIMEOUT, 40);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'code' => $code,
            'body' => $resp,
            'error' => $err
        ];
    }
}