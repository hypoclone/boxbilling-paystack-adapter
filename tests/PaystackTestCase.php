<?php

use PHPUnit\Framework\TestCase;
use Pimple\Container;

/**
 * Shared scaffolding: builds adapters wired to fake FOSSBilling services.
 */
abstract class PaystackTestCase extends TestCase
{
    protected const LIVE_PUBLIC = 'pk_live_123';
    protected const LIVE_SECRET = 'sk_live_secret';
    protected const TEST_PUBLIC = 'pk_test_123';
    protected const TEST_SECRET = 'sk_test_secret';

    protected FakeLogger $logger;

    protected FakeDb $db;

    protected FakeCurrencyService $currency;

    protected function setUp(): void
    {
        $this->logger = new FakeLogger();
        $this->db = new FakeDb();
        $this->currency = new FakeCurrencyService();
    }

    protected function defaultConfig(array $overrides = []): array
    {
        return array_merge([
            'live_public_key' => self::LIVE_PUBLIC,
            'live_secret_key' => self::LIVE_SECRET,
            'test_public_key' => self::TEST_PUBLIC,
            'test_secret_key' => self::TEST_SECRET,
        ], $overrides);
    }

    protected function makeAdapter(array $overrides = []): Payment_Adapter_Paystack
    {
        $adapter = new Payment_Adapter_Paystack($this->defaultConfig($overrides));
        $adapter->setDi($this->makeContainer());

        return $adapter;
    }

    /**
     * Adapter whose outbound Paystack call is intercepted instead of executed.
     */
    protected function makeStubbedAdapter(array $overrides = [], $response = false): StubbedRequestPaystack
    {
        $adapter = new StubbedRequestPaystack($this->defaultConfig($overrides));
        $adapter->setDi($this->makeContainer());
        $adapter->response = $response;

        return $adapter;
    }

    protected function makeContainer(): Container
    {
        $container = new Container();
        $container['logger'] = $this->logger;
        $container['db'] = $this->db;
        $container['mod_service'] = $container->protect(function ($module) {
            if ($module === 'currency') {
                return $this->currency;
            }

            throw new RuntimeException("Unexpected module service requested: $module");
        });

        return $container;
    }

    /**
     * Build the webhook payload FOSSBilling hands the adapter, signed the way
     * Paystack signs it.
     */
    protected function signedIpn(string $payload, string $secret = self::LIVE_SECRET): array
    {
        return [
            'server' => ['HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $payload, $secret)],
            'http_raw_post_data' => $payload,
        ];
    }

    protected function chargeSuccessPayload(array $overrides = []): string
    {
        $data = array_merge([
            'reference' => 'ref_123',
            'amount' => 10000,
            'currency' => 'GHS',
            'status' => 'success',
            'metadata' => ['bb_invoice_id' => 42, 'bb_gateway_id' => 7],
        ], $overrides);

        return json_encode(['event' => 'charge.success', 'data' => $data]);
    }

    /**
     * A transaction row as the platform stores it, with every column the
     * adapter reads present.
     */
    protected function transactionRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'invoice_id' => null,
            'status' => Model_Transaction::STATUS_RECEIVED,
            'amount' => null,
            'currency' => null,
            'txn_id' => null,
            'type' => null,
        ], $overrides);
    }

    protected function invoiceRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 42,
            'gateway_id' => 7,
            'serie' => 'BB',
            'nr' => 42,
            'currency' => 'GHS',
            'total' => 100.00,
            'status' => Model_Invoice::STATUS_UNPAID,
            'buyer' => [
                'email' => 'buyer@example.com',
                'first_name' => 'Ama',
                'last_name' => 'Mensah',
            ],
        ], $overrides);
    }

    protected function callPrivate(object $object, string $method, ...$args)
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
