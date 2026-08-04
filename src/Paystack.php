<?php
/**
 * Paystack payment gateway adapter for FOSSBilling.
 *
 * @author  Samuel Apraku
 * @license Apache-2.0
 */

class Payment_Adapter_Paystack implements \FOSSBilling\InjectionAwareInterface
{
    const ENDPOINT = 'https://api.paystack.co/transaction';
    const TXN_SUCCESS = 'success';

    private $config = [];

    protected $di;

    private $url;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function __construct($config)
    {
        $this->config = $config;

        if (!function_exists('curl_exec')) {
            throw new Payment_Exception('PHP Curl extension must be enabled in order to use the Paystack gateway');
        }

        if (!isset($this->config['live_public_key'])) {
            throw new Payment_Exception('Payment gateway "Paystack" is not configured properly. Please update the "Live Public Key" parameter.');
        }

        if (!isset($this->config['live_secret_key'])) {
            throw new Payment_Exception('Payment gateway "Paystack" is not configured properly. Please update the "Live Secret Key" parameter.');
        }
    }

    public static function getConfig()
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Enter your Paystack API keys to start accepting payments by Paystack.',
            'description_client' => 'Pay by mobile money or debit/credit card.',
            'can_load_in_iframe' => true,
            'logo' => [
                'logo' => 'paystack.png',
                'height' => '30px',
                'width' => '65px',
            ],
            'form' => [
                'live_public_key' => ['text', [
                    'label' => 'Live Public Key',
                ],],
                'live_secret_key' => ['text', [
                    'label' => 'Live Secret Key',
                ],],
                'test_public_key' => ['text', [
                    'label' => 'Test Public Key',
                ],],
                'test_secret_key' => ['text', [
                    'label' => 'Test Secret Key',
                ],],
                'charge' => ['text', [
                    'label' => 'Transaction Charge (%)',
                ],],
                'auto_process_invoice' => ['radio', [
                    'label' => 'Process invoice after payment',
                    'multiOptions' => ['1' => 'Yes', '0' => 'No'],
                ],],
            ],
        ];
    }

    public function getPublicKey()
    {
        if (!empty($this->config['test_mode'])) {
            return $this->config['test_public_key'] ?? '';
        }

        return $this->config['live_public_key'] ?? '';
    }

    public function getSecretKey()
    {
        if (!empty($this->config['test_mode'])) {
            return $this->config['test_secret_key'] ?? '';
        }

        return $this->config['live_secret_key'] ?? '';
    }

    /**
     * Payment gateway endpoint
     *
     * @return string
     */
    public function getServiceUrl()
    {
        return $this->url;
    }

    /**
     * Return payment gateway type
     *
     * @return string
     */
    public function getType()
    {
        return Payment_AdapterAbstract::TYPE_HTML;
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoice = $api_admin->invoice_get(['id' => $invoice_id]);

        // Paystack expects the amount in the currency's subunit (pesewas/kobo/cents).
        $amount = round($this->getAmountInDefaultCurrency($invoice['currency'], $invoice['total']) * 100, 0, PHP_ROUND_HALF_UP);

        $charge = (isset($this->config['charge']) && is_numeric($this->config['charge'])) ? (float) $this->config['charge'] : 0.0;
        if ($charge > 0) {
            $amount += round($amount * $charge / 100, 0, PHP_ROUND_HALF_UP);
        }
        $amount = (int) $amount;

        $feeNote = $charge > 0
            ? '<br><strong>' . rtrim(rtrim(number_format($charge, 2), '0'), '.') . '% fee applies</strong>'
            : '';

        // Encode every customer-supplied value so it cannot break out of the JS context.
        $publicKey = json_encode($this->getPublicKey());
        $email     = json_encode($invoice['buyer']['email'] ?? '');
        $firstName = json_encode($invoice['buyer']['first_name'] ?? '');
        $lastName  = json_encode($invoice['buyer']['last_name'] ?? '');
        $currency  = json_encode($this->getDefaultCurrency());
        $gatewayId = (int) $invoice['gateway_id'];
        $invoiceId = (int) $invoice['id'];

        $form  = '<div class="text-center">' . PHP_EOL;
        $form .= '<p>Pay with mobile money or debit/credit card.' . $feeNote . '</p>' . PHP_EOL;
        $form .= '<form id="paymentForm">' . PHP_EOL;
        $form .= '<input type="submit" class="btn btn-alt btn-primary btn-large" value="Complete Payment with Paystack" />' . PHP_EOL;
        $form .= '</form>' . PHP_EOL;
        $form .= '</div>' . PHP_EOL;
        $form .= '<script src="https://js.paystack.co/v1/inline.js"></script>' . PHP_EOL;
        $form .= "<script type='text/javascript'>
    const paymentForm = document.getElementById('paymentForm');
    paymentForm.addEventListener('submit', payWithPaystack, false);
    function payWithPaystack(e) {
        e.preventDefault();
        let handler = PaystackPop.setup({
            key: $publicKey,
            email: $email,
            amount: $amount,
            firstname: $firstName,
            lastname: $lastName,
            currency: $currency,
            metadata: {
                bb_gateway_id: $gatewayId,
                bb_invoice_id: $invoiceId,
                custom_fields: [
                    {
                        display_name: 'Invoice ID',
                        variable_name: 'bb_invoice_id',
                        value: $invoiceId
                    }
                ]
            },
            onClose: function () {
                alert('Payment has been cancelled.');
            },
            callback: function (response) {
                alert('Payment complete! Reference: ' + response.reference);
            }
        });
        handler.openIframe();
    }
</script>" . PHP_EOL;

        return $form;
    }

    public function getInvoiceTitle(array $invoice)
    {
        $p = [
            ':id' => sprintf('%05s', $invoice['nr']),
            ':serie' => $invoice['serie'],
        ];

        return __('Payment for invoice :serie:id', $p);
    }

    /**
     * @param $api_admin  - admin api
     * @param $id         - transaction id from db
     * @param $data       - ipn data
     * @param $gateway_id - gateway id
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        if (!$this->isIpnValid($data)) {
            throw new Payment_Exception('Paystack IPN is not valid');
        }

        $ipn = $this->_getIpnObject($data); // paystack returns post body in webhook
        $tx = $api_admin->invoice_transaction_get(['id' => $id]);
        $invoice_id = isset($tx['invoice_id']) ? $tx['invoice_id'] : $ipn->data->metadata->bb_invoice_id;
        if ($tx['status'] === Model_Transaction::STATUS_PROCESSED) {
            return;
        }

        $reference = $ipn->data->reference;
        $amount = $ipn->data->amount * 1 / 100;
        $currency = $ipn->data->currency;

        $invoice = $api_admin->invoice_get(['id' => $invoice_id]);

        $tx_data = ['id' => $id];
        if (!$tx['invoice_id']) {
            $tx_data['invoice_id'] = $invoice_id;
            $api_admin->invoice_transaction_update($tx_data);
        }

        if ($tx['status'] === Model_Transaction::STATUS_RECEIVED) {
            $this->verifyTransaction($api_admin, $id, $data);
        }

        if (!$tx['amount']) {
            $tx_data['amount'] = $amount;
        }
        if (!$tx['currency']) {
            $tx_data['currency'] = $currency;
        }
        if (!$tx['txn_id']) {
            $tx_data['txn_id'] = $reference;
        }
        if (!$tx['type']) {
            $tx_data['type'] = \Payment_Transaction::TXTYPE_PAYMENT;
        }

        if ($this->_isSuccessEvent($ipn)) {
            $markAsPaid = $this->config['auto_process_invoice'] ?? false;

            $this->di['logger']->info('Processing transaction from Paystack with id: ' . $reference);

            if ($markAsPaid && $ipn->data->status === self::TXN_SUCCESS) {
                $api_admin->invoice_mark_as_paid([
                    'id' => $invoice_id,
                    'check_product_setup' => true,
                ]);
            }
        }

        $invoice = $api_admin->invoice_get(['id' => $invoice_id]);

        if ($invoice['status'] === \Model_Invoice::STATUS_PAID) {
            $tx_data['status'] = Model_Transaction::STATUS_PROCESSED;
        }
        $api_admin->invoice_transaction_update($tx_data);
    }

    private function _getIpnObject($ipn)
    {
        return json_decode((string) ($ipn['http_raw_post_data'] ?? ''));
    }

    private function _isSuccessEvent($ipnObject): bool
    {
        return isset($ipnObject->event) && $ipnObject->event === 'charge.success';
    }

    public function isIpnDuplicate($txn_id, $invoice_id, $gateway_id, $amount, $ipn)
    {
        $sql = 'SELECT id
                FROM transaction
                WHERE txn_id = :transaction_id
                  AND txn_status = :transaction_status
                  AND type = :transaction_type
                  AND amount = :transaction_amount
                LIMIT 2';

        $bindings = [
            ':transaction_id' => $txn_id,
            ':transaction_status' => $ipn['data']['status'],
            ':transaction_type' => $ipn['txn_type'],
            ':transaction_amount' => $amount,
        ];

        $rows = $this->di['db']->getAll($sql, $bindings);

        return count($rows) > 1;
    }

    public function verifyTransaction($api_admin, $id, $ipn)
    {
        $ipnObj = $this->_getIpnObject($ipn);
        if (!$this->_isSuccessEvent($ipnObj)) {
            return false;
        }

        $reference = $ipnObj->data->reference;

        $response = $this->request('/verify/' . rawurlencode($reference));
        if (!$response) {
            return false;
        }

        $obj = json_decode($response);
        if (!is_object($obj)) {
            return false;
        }

        $status = 'unknown';
        if (isset($obj->status) && $obj->status) {
            $txn = $api_admin->invoice_transaction_get(['id' => $id]);
            $status = Model_Transaction::STATUS_APPROVED;
            if ($txn['status'] === Model_Transaction::STATUS_PROCESSED) {
                $status = Model_Transaction::STATUS_PROCESSED;
            }
            $d = [
                'id' => $id,
                'status' => $status,
                'txn_status' => $obj->data->status,
                'note' => $obj->message,
                'output' => $obj->data,
                'error' => '',
                'error_code' => null,
            ];
        } else {
            $d = [
                'id' => $id,
                'status' => Model_Transaction::STATUS_RECEIVED,
                'error' => $obj->message ?? 'Paystack could not verify this transaction',
                'error_code' => null,
                'txn_status' => $status,
            ];
        }
        $d['updated_at'] = date('Y-m-d H:i:s');
        $api_admin->invoice_transaction_update($d);

        return $obj->status ?? false;
    }

    /**
     * Perform an authenticated GET request against the Paystack transaction API.
     *
     * @param string $path
     * @return string|false
     */
    protected function request($path)
    {
        $secretKey = $this->getSecretKey();
        $url = self::ENDPOINT . $path;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $secretKey,
                'Cache-Control: no-cache',
            ],
        ]);

        $data = curl_exec($ch);
        if (curl_errno($ch)) {
            curl_close($ch);

            return false;
        }
        curl_close($ch);

        return $data;
    }

    /**
     * Generate links for performing actions required by gateway
     */
    public function getActions()
    {
        return [
            [
                'name' => 'verify',
                'label' => 'Verify Transaction',
            ],
        ];
    }

    /**
     * Process actions to be performed by gateway
     */
    public function processAction($api_admin, $id, $ipn, $gateway_id, $action)
    {
        switch ($action) {
            case 'verify':
                return $this->verifyTransaction($api_admin, $id, $ipn);
            default:
                return;
        }
    }

    protected function getAmountInDefaultCurrency($currency, $amount)
    {
        $currencyService = $this->di['mod_service']('currency');

        return $currencyService->toBaseCurrency($currency, $amount);
    }

    protected function getDefaultCurrency()
    {
        $currencyService = $this->di['mod_service']('currency');
        $default = $currencyService->getDefault();

        return $default->code;
    }

    /**
     * Validate a Paystack webhook by verifying its signature header.
     *
     * @param array $data
     * @return bool
     */
    private function isIpnValid($data)
    {
        $server = $data['server'] ?? [];
        $input = $data['http_raw_post_data'] ?? '';

        if (!isset($server['HTTP_X_PAYSTACK_SIGNATURE'])) {
            return false;
        }

        $expected = hash_hmac('sha512', $input, $this->getSecretKey());

        // Timing-safe comparison.
        return hash_equals($expected, (string) $server['HTTP_X_PAYSTACK_SIGNATURE']);
    }
}
