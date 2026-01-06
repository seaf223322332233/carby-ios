<?php
// gateways/PayTabsAdapter.php - Real PayTabs Hosted Payment Page (HPP) Init
// ==============================================================================
// Creates a PayTabs payment request via:
//   POST {domain}/payment/request
// Header:
//   authorization: {server_key}
// Body (JSON):
//   profile_id, tran_type, tran_class, cart_id, cart_description, cart_currency, cart_amount
// Optional:
//   return, callback, paypage_lang, customer_details, shipping_details, user_defined...
//
// Returns:
//   redirect_url: PayTabs payment page URL
//   tran_ref     : PayTabs transaction reference (store in provider_ref)
//
// Requirements (settings per env):
//   - domain      (e.g. https://secure.paytabs.sa)
//   - profile_id  (int)
//   - server_key  (string)
//
// ==============================================================================

require_once __DIR__ . '/../PaymentConfig.php';
require_once __DIR__ . '/../PaymentEngine.php';

class PayTabsAdapter
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

    private function fmtAmount($amount): float
    {
        // PayTabs accepts decimal (e.g. 500.00)
        return (float)number_format((float)$amount, 2, '.', '');
    }

    private function sanitizeCurrency(string $currency): string
    {
        $c = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3,5}$/', $c)) $c = 'SAR';
        return $c;
    }

    private function sanitizeLang(string $lang): string
    {
        $lang = strtolower(trim($lang));
        // PayTabs often uses 'en'/'ar'
        if (!in_array($lang, ['en','ar'], true)) $lang = 'ar';
        return $lang;
    }

    public function init(array $txn, array $customer): array
    {
        $txnId = (int)($txn['id'] ?? 0);
        if ($txnId <= 0) {
            return ['ok'=>false, 'error'=>'TXN_INVALID'];
        }

        // Settings
        $domain    = rtrim(pg_getSetting('paytabs', $this->env, 'domain', ''), '/');
        $profileId = trim((string)pg_getSetting('paytabs', $this->env, 'profile_id', ''));
        $serverKey = trim((string)pg_getSetting('paytabs', $this->env, 'server_key', ''));

        if (!$this->required($domain) || !$this->required($profileId) || !$this->required($serverKey)) {
            return [
                'ok' => false,
                'error' => 'PAYTABS_KEYS_MISSING',
                'details' => [
                    'domain' => (bool)$this->required($domain),
                    'profile_id' => (bool)$this->required($profileId),
                    'server_key' => (bool)$this->required($serverKey),
                ]
            ];
        }

        // Build URLs (Return + Callback/Webhook)
        $base = ps_getAppBaseUrl();

        // return: browser redirect after payment
        $returnUrl = $base . "/payment_return.php?gateway=paytabs&txn=" . $txnId;

        // callback: server-to-server (our webhook)
        $callbackUrl = $base . "/payment_webhook.php?gateway=paytabs&txn=" . $txnId;

        // Transaction info
        $amount   = $this->fmtAmount($txn['amount'] ?? 0);
        $currency = $this->sanitizeCurrency((string)($txn['currency'] ?? 'SAR'));

        // Required PayTabs parameters:
        // tran_type: sale
        // tran_class: ecom
        $cartId = "TXN-" . $txnId;
        $cartDesc = (string)($txn['order_type'] ?? 'order') . "#" . (string)($txn['order_id'] ?? '') . " / TXN#" . $txnId;

        // Optional: language
        $paypageLang = $this->sanitizeLang((string)pg_getSetting('paytabs', $this->env, 'paypage_lang', 'ar'));

        // Optional: country
        $country = strtoupper(trim((string)pg_getSetting('paytabs', $this->env, 'country', 'SA')));
        if (!preg_match('/^[A-Z]{2}$/', $country)) $country = 'SA';

        // Customer details (optional but recommended)
        $cust = [
            'name'    => (string)($customer['name'] ?? ('Customer '.$txnId)),
            'email'   => (string)($customer['email'] ?? ''),
            'phone'   => (string)($customer['phone'] ?? ''),
            'street1' => (string)($customer['street1'] ?? 'N/A'),
            'city'    => (string)($customer['city'] ?? 'Riyadh'),
            'state'   => (string)($customer['state'] ?? 'Riyadh'),
            'country' => (string)($customer['country'] ?? $country),
            'zip'     => (string)($customer['zip'] ?? '00000'),
        ];

        // PayTabs request payload
        $payload = [
            'profile_id'       => (int)$profileId,
            'tran_type'        => 'sale',
            'tran_class'       => 'ecom',
            'cart_id'          => $cartId,
            'cart_description' => mb_substr($cartDesc, 0, 120),
            'cart_currency'    => $currency,
            'cart_amount'      => $amount,

            'paypage_lang'     => $paypageLang,

            // IMPORTANT:
            // return: browser-based (may be affected by customer behavior)
            // callback: server-to-server (recommended)
            'return'           => $returnUrl,
            'callback'         => $callbackUrl,

            // Optional UI behavior
            'hide_shipping'    => true,

            // Prefill customer details
            'customer_details' => [
                'name'    => $cust['name'],
                'email'   => $cust['email'],
                'phone'   => $cust['phone'],
                'street1' => $cust['street1'],
                'city'    => $cust['city'],
                'state'   => $cust['state'],
                'country' => $country,
                'zip'     => $cust['zip'],
            ],

            // Optional: shipping_details (we can mirror customer)
            'shipping_details' => [
                'name'    => $cust['name'],
                'email'   => $cust['email'],
                'phone'   => $cust['phone'],
                'street1' => $cust['street1'],
                'city'    => $cust['city'],
                'state'   => $cust['state'],
                'country' => $country,
                'zip'     => $cust['zip'],
            ],

            // Helpful tracking
            'user_defined' => [
                'udf1' => 'TXN#'.$txnId,
                'udf2' => (string)($txn['order_type'] ?? ''),
                'udf3' => (string)($txn['order_id'] ?? ''),
            ],
        ];

        // Remove empty optional fields (avoid validation errors)
        $payload = $this->removeEmptyDeep($payload);

        // Endpoint
        $endpoint = $domain . "/payment/request";

        // Call PayTabs
        $resp = PaymentEngine::httpJson(
            "POST",
            $endpoint,
            [
                "authorization: " . $serverKey,
                "Content-Type: application/json",
                "Accept: application/json",
            ],
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        );

        $raw = (string)($resp['body'] ?? '');
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = ['raw' => $raw];

        if (!empty($resp['error'])) {
            return [
                'ok' => false,
                'error' => 'PAYTABS_HTTP_ERROR',
                'http' => $resp,
                'data' => $data
            ];
        }

        if ((int)($resp['code'] ?? 0) >= 400) {
            return [
                'ok' => false,
                'error' => 'PAYTABS_BAD_HTTP',
                'http' => $resp,
                'data' => $data
            ];
        }

        // Expected response fields:
        //  - tran_ref
        //  - redirect_url
        $tranRef = (string)($data['tran_ref'] ?? '');
        $redirectUrl = (string)($data['redirect_url'] ?? '');

        // Some responses use different naming; keep fallback safe
        if ($redirectUrl === '' && isset($data['redirectUrl'])) $redirectUrl = (string)$data['redirectUrl'];

        if ($redirectUrl === '') {
            return [
                'ok' => false,
                'error' => 'PAYTABS_NO_REDIRECT_URL',
                'tran_ref' => $tranRef,
                'data' => $data
            ];
        }

        return [
            'ok' => true,
            'gateway' => 'paytabs',
            'provider_ref' => $tranRef, // store in payment_transactions.provider_ref
            'redirect_url' => $redirectUrl,
            'paytabs_response' => [
                'tran_ref' => $tranRef,
                'redirect_url' => $redirectUrl,
                'cart_id' => $cartId
            ]
        ];
    }

    private function removeEmptyDeep($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $v2 = $this->removeEmptyDeep($v);

                // remove empty strings / null / empty arrays
                if ($v2 === null) continue;
                if (is_string($v2) && trim($v2) === '') continue;
                if (is_array($v2) && count($v2) === 0) continue;

                $out[$k] = $v2;
            }
            return $out;
        }
        return $value;
    }
}