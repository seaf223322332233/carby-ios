<?php
// PaymentApply.php
// ==============================================================================
// Applies a PAID transaction to your business tables (subscriptions/orders/etc.)
// - Idempotent via payment_fulfillments.txn_id UNIQUE
// - Safe: checks table/columns existence before updating
// ==============================================================================

require_once 'db_connect.php';
require_once 'PaymentEngine.php'; // includes PaymentConfig

class PaymentApply
{
    /* ===============================
       Public entry
    ================================ */
    public static function applyPaidTxn(int $txnId): array
    {
        global $pdo;

        $txn = PaymentEngine::getTxn($txnId);
        if (!$txn) return ['ok'=>false, 'error'=>'TXN_NOT_FOUND'];

        if (($txn['status'] ?? '') !== 'paid') {
            return ['ok'=>false, 'error'=>'TXN_NOT_PAID'];
        }

        // idempotency: if already applied, return ok
        if (self::isApplied($txnId)) {
            return ['ok'=>true, 'already_applied'=>true];
        }

        // Ensure table exists (if you didn't run SQL, we try to create safely)
        self::ensureFulfillmentsTable();

        // Lock with transaction to avoid race
        $pdo->beginTransaction();
        try {
            // Re-check inside transaction
            if (self::isApplied($txnId, true)) {
                $pdo->commit();
                return ['ok'=>true, 'already_applied'=>true];
            }

            $orderType = (string)$txn['order_type'];
            $orderId   = (int)$txn['order_id'];
            $userId    = (int)$txn['user_id'];

            // 1) APPLY (customizable switch)
            $result = self::applyByOrderType($orderType, $orderId, $txn, $userId);

            // 2) Record fulfillment (even if you only updated generic)
            self::markApplied($txnId, $orderType, $orderId, $result['note'] ?? null);

            $pdo->commit();
            return array_merge(['ok'=>true], $result);

        } catch (Exception $e) {
            $pdo->rollBack();
            return ['ok'=>false, 'error'=>'APPLY_EXCEPTION', 'message'=>$e->getMessage()];
        }
    }

    /* ===============================
       Customize here
    ================================ */
    private static function applyByOrderType(string $orderType, int $orderId, array $txn, int $userId): array
    {
        // هنا هو المكان الوحيد الذي ستعدله لاحقاً حسب مشروعك الحقيقي.
        // أنا وضعت تطبيقات عامة + محاولات تحديث جداول شائعة.

        switch ($orderType) {

            // مثال شائع عندك: اشتراك باقة
            case 'package_subscription':
            case 'subscription':
                // محاولات شائعة: subscriptions / client_subscriptions / user_subscriptions
                $ok =
                    self::tryUpdateStatus('subscriptions', $orderId, ['status'=>'active', 'paid'=>1, 'paid_at'=>date('Y-m-d H:i:s'), 'payment_txn_id'=>$txn['id']]) ||
                    self::tryUpdateStatus('client_subscriptions', $orderId, ['status'=>'active', 'paid'=>1, 'paid_at'=>date('Y-m-d H:i:s'), 'payment_txn_id'=>$txn['id']]) ||
                    self::tryUpdateStatus('user_subscriptions', $orderId, ['status'=>'active', 'paid'=>1, 'paid_at'=>date('Y-m-d H:i:s'), 'payment_txn_id'=>$txn['id']]);

                if ($ok) return ['applied_to'=>'subscription_table', 'note'=>'subscription activated'];
                // fallback: سجل في جدول داخلي
                return ['applied_to'=>'fulfillment_only', 'note'=>'subscription paid (no matching table updated)'];

            // طلبات عامة
            case 'order':
            case 'client_order':
                $ok =
                    self::tryUpdateStatus('orders', $orderId, ['payment_status'=>'paid', 'status'=>'confirmed', 'paid_at'=>date('Y-m-d H:i:s'), 'payment_txn_id'=>$txn['id']]) ||
                    self::tryUpdateStatus('client_orders', $orderId, ['payment_status'=>'paid', 'status'=>'confirmed', 'paid_at'=>date('Y-m-d H:i:s'), 'payment_txn_id'=>$txn['id']]);

                if ($ok) return ['applied_to'=>'orders_table', 'note'=>'order marked paid'];
                return ['applied_to'=>'fulfillment_only', 'note'=>'order paid (no matching table updated)'];

            // فواتير
            case 'invoice':
                $ok =
                    self::tryUpdateStatus('invoices', $orderId, ['status'=>'paid', 'paid_at'=>date('Y-m-d H:i:s'), 'payment_txn_id'=>$txn['id']]);

                if ($ok) return ['applied_to'=>'invoices_table', 'note'=>'invoice paid'];
                return ['applied_to'=>'fulfillment_only', 'note'=>'invoice paid (no matching table updated)'];

            default:
                // fallback generic attempt: update common table name based on orderType
                // example: order_type = "my_table" => tries to update table "my_table"
                $ok = self::tryUpdateStatus($orderType, $orderId, ['status'=>'paid', 'payment_txn_id'=>$txn['id']]);
                if ($ok) return ['applied_to'=>'dynamic_table', 'note'=>"updated table=$orderType"];
                return ['applied_to'=>'fulfillment_only', 'note'=>"paid txn stored; no handler for order_type=$orderType"];
        }
    }

    /* ===============================
       DB Utilities (safe)
    ================================ */

    private static function ensureFulfillmentsTable(): void
    {
        global $pdo;
        // safe create (won't fail if exists)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS payment_fulfillments (
              id BIGINT AUTO_INCREMENT PRIMARY KEY,
              txn_id BIGINT NOT NULL UNIQUE,
              order_type VARCHAR(40) NOT NULL,
              order_id BIGINT NOT NULL,
              applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              note VARCHAR(255) DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    private static function isApplied(int $txnId, bool $forUpdate = false): bool
    {
        global $pdo;
        $sql = "SELECT txn_id FROM payment_fulfillments WHERE txn_id=? LIMIT 1";
        if ($forUpdate) $sql = "SELECT txn_id FROM payment_fulfillments WHERE txn_id=? LIMIT 1 FOR UPDATE";
        $st = $pdo->prepare($sql);
        $st->execute([$txnId]);
        return (bool)$st->fetchColumn();
    }

    private static function markApplied(int $txnId, string $orderType, int $orderId, ?string $note): void
    {
        global $pdo;
        $st = $pdo->prepare("
            INSERT INTO payment_fulfillments (txn_id, order_type, order_id, note)
            VALUES (?, ?, ?, ?)
        ");
        $st->execute([$txnId, $orderType, $orderId, $note]);
    }

    private static function tableExists(string $table): bool
    {
        global $pdo;
        try {
            $st = $pdo->prepare("SHOW TABLES LIKE ?");
            $st->execute([$table]);
            return (bool)$st->fetchColumn();
        } catch (Exception $e) {
            return false;
        }
    }

    private static function columnExists(string $table, string $column): bool
    {
        global $pdo;
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $st->execute([$column]);
            return (bool)$st->fetchColumn();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Safe update by id, only updates columns that actually exist
     * and only if table exists and has id column.
     */
    private static function tryUpdateStatus(string $table, int $id, array $fields): bool
    {
        global $pdo;

        $table = trim($table);
        if ($table === '' || $id <= 0) return false;

        if (!self::tableExists($table)) return false;
        if (!self::columnExists($table, 'id')) return false;

        // Filter only existing columns
        $setParts = [];
        $vals = [];
        foreach ($fields as $col => $val) {
            if (!self::columnExists($table, $col)) continue;
            $setParts[] = "`$col`=?";
            $vals[] = $val;
        }

        if (count($setParts) === 0) return false;

        $vals[] = $id;
        $sql = "UPDATE `$table` SET ".implode(',', $setParts)." WHERE id=? LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute($vals);

        return $st->rowCount() > 0;
    }
}
