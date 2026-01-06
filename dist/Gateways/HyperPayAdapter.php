<?php
// gateways/HyperPayAdapter.php - Real HyperPay/OPPWA Checkout Init (Server-to-Server)
// ==============================================================================
// Creates checkoutId via POST /v1/checkouts then redirects user to local widget page:
//   hyperpay_checkout.php?txn=ID
//
// Required settings (per env):
//  - base_url     (e.g. https://test.oppwa.com OR https://eu-test.oppwa.com)
//  - entity_id
//  - access_token (Bearer)
//
// Notes:
// - paymentType=DB (Debit) is standard for one-time purchase.
// - shopperResultUrl must be your payment_return.php
// - notificationUrl (webhook) optional but recommended
// ==============================================================================

require_once __DIR__ . '/../PaymentConfig.php';
require_once __DIR__ . '/../PaymentEngine.php';

class HyperPayAdapter
{
    private string $env;

    public function __construct(string $env)
    {
        $this->env = in_array($env, ['test','live'], true) ? $env : 'test';
    }

    private function required(string $v): bool
    {
        return trim($v) !== '';
    }

    private function fmtAmount($amount): string
    {
        // OPPWA expects decimal amount as string with 2 decimals
        return number_format((float)$amount, 2, '.', '');
    }

    public function init(array $txn, array $customer): array
    {
        $baseUrl     = rtrim(pg_getSetting('hyperpay', $this->env, 'base_url', ''), '/');
        $entityId    = pg_getSetting('hyperpay', $this->env, 'entity_id', '');
        $accessToken = pg_getSetting('hyperpay', $this->env, 'access_token', '');

        if (!$this->required($baseUrl) || !$this->required($entityId) || !$this->required($accessToken)) {
            return [
                'ok' => false,
                'error' => 'HYPERPAY_KEYS_MISSING',
                'details' => [
                    'base_url' => (bool)$this->required($baseUrl),
                    'entity_id' => (bool)$this->required($entityId),
                    'access_token' => (bool)$this->required($accessToken),
                ]
            ];
        }

        $txnId = (int)($txn['id'] ?? 0);
        if ($txnId <= 0) {
            return ['ok'=>false, 'error'=>'TXN_INVALID'];
        }

        $amount   = $this->fmtAmount($txn['amount'] ?? 0);
        $currency = strtoupper((string)($txn['currency'] ?? 'SAR'));
        if (!preg_match('/^[A-Z]{3,5}$/', $currency)) $currency = 'SAR';

        // URLs
        $base = ps_getAppBaseUrl();
        $shopperResultUrl = $base . "/payment_return.php?gateway=hyperpay&txn=" . $txnId;
        $notificationUrl  = $base . "/payment_webhook.php?gateway=hyperpay&txn=" . $txnId; // optional

        // Optional: brands can be configured in settings (e.g. "VISA MASTER MADA")
        $brands = trim((string)pg_getSetting('hyperpay', $this->env, 'brands', ''));
        // HyperPay brands are usually passed at widget level, not always required here.

        // Build request fields (application/x-www-form-urlencoded)
        // Common minimal fields:
        //  - entityId, amount, currency, paymentType, shopperResultUrl
        // Recommended:
        //  - merchantTransactionId
        //  - customer.* (email, givenName, surname)
        //  - billing.* (street1, city, state, country, postcode)
        //  - notificationUrl
        $fields = [
            'entityId' => $entityId,
            'amount' => $amount,
            'currency' => $currency,
            'paymentType' => 'DB',
            'shopperResultUrl' => $shopperResultUrl,
            'notificationUrl' => $notificationUrl,

            // helps you debug/trace in HyperPay dashboard
            'merchantTransactionId' => 'TXN-' . $txnId,

            // Customer (optional)
            'customer.email' => (string)($customer['email'] ?? ''),
            'customer.givenName' => (string)($customer['name'] ?? 'Customer'),
            'customer.surname' => (string)($customer['name'] ?? 'Customer'),

            // Billing (optional)
            'billing.street1' => (string)($customer['street1'] ?? ''),
            'billing.city' => (string)($customer['city'] ?? ''),
            'billing.state' => (string)($customer['state'] ?? ''),
            'billing.country' => (string)($customer['country'] ?? 'SA'),
            'billing.postcode' => (string)($customer['zip'] ?? ''),
        ];

        // Clean empty optional fields (so we don't send blank values)
        foreach ($fields as $k => $v) {
            if (is_string($v) && trim($v) === '') unset($fields[$k]);
        }

        // Some test environments sometimes use testMode=EXTERNAL (optional)
        // If you want: set hyperpay setting test_mode to "EXTERNAL"
        $testMode = trim((string)pg_getSetting('hyperpay', $this->env, 'test_mode', ''));
        if ($this->env === 'test' && $testMode !== '') {
            $fields['testMode'] = $testMode;
        }

        $endpoint = $baseUrl . "/v1/checkouts";

        $resp = PaymentEngine::httpJson(
            "POST",
            $endpoint,
            [
                "Authorization: Bearer " . $accessToken,
                "Content-Type: application/x-www-form-urlencoded",
                "Accept: application/json",
            ],
            http_build_query($fields)
        );

        $raw = (string)($resp['body'] ?? '');
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = ['raw' => $raw];

        if (!empty($resp['error'])) {
            return [
                'ok' => false,
                'error' => 'HYPERPAY_HTTP_ERROR',
                'http' => $resp,
                'data' => $data
            ];
        }

        if ((int)($resp['code'] ?? 0) >= 400) {
            return [
                'ok' => false,
                'error' => 'HYPERPAY_BAD_HTTP',
                'http' => $resp,
                'data' => $data
            ];
        }

        // Success response typically includes:
        //  - id (checkoutId)
        //  - result.code (e.g. 000.200.100 for successfully created checkout) :contentReference[oaicite:1]{index=1}
        $checkoutId = (string)($data['id'] ?? '');
        $resultCode = (string)($data['result']['code'] ?? '');
        $resultDesc = (string)($data['result']['description'] ?? '');

        if ($checkoutId === '') {
            return [
                'ok' => false,
                'error' => 'HYPERPAY_NO_CHECKOUT_ID',
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
                'data' => $data
            ];
        }

        // Consider checkout created if code starts with 000.200 (created/pending session) :contentReference[oaicite:2]{index=2}
        $isCreated = (strpos($resultCode, '000.200.') === 0 || $resultCode === '');

        if (!$isCreated) {
            return [
                'ok' => false,
                'error' => 'HYPERPAY_CHECKOUT_NOT_CREATED',
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
                'checkout_id' => $checkoutId,
                'data' => $data
            ];
        }

        // Redirect to our local widget page (already built in مرحلة 8)
        return [
            'ok' => true,
            'gateway' => 'hyperpay',
            'provider_ref' => $checkoutId, // store in payment_transactions.provider_ref
            'redirect_url' => $base . "/hyperpay_checkout.php?txn=" . $txnId,
            'result_code' => $resultCode,
            'result_desc' => $resultDesc,
            'brands' => $brands,
        ];
    }
}
