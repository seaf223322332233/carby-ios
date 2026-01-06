<?php
// gateways/MoyasarAdapter.php
require_once __DIR__ . '/../PaymentConfig.php';
require_once __DIR__ . '/../PaymentEngine.php';

class MoyasarAdapter
{
    private string $env;

    public function __construct(string $env) {
        $this->env = in_array($env, ['test','live'], true) ? $env : 'test';
    }

    public function init(array $txn, array $customer): array
    {
        // في المرحلة القادمة: سنبني checkout محلي (Moyasar Form) + verify في return
        // هنا نعيد تحويل إلى صفحة checkout داخل موقعك
        $publishable = pg_getSetting('moyasar', $this->env, 'publishable_key', '');
        if ($publishable === '') {
            return ['ok'=>false, 'error'=>'MOYASAR_PUBLISHABLE_KEY_MISSING'];
        }

        return [
            'ok' => true,
            'gateway' => 'moyasar',
            'provider_ref' => null,
            'redirect_url' => PaymentEngine::baseUrl() . '/moyasar_checkout.php?txn=' . (int)$txn['id']
        ];
    }
}
