<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure logic of the Paystack adapter: configuration,
 * key selection, and — most importantly — webhook (IPN) signature verification.
 */
class PaystackTest extends TestCase
{
    private const LIVE_SECRET = 'sk_live_secret';

    private function makeAdapter(array $overrides = []): Payment_Adapter_Paystack
    {
        $config = array_merge([
            'live_public_key' => 'pk_live_123',
            'live_secret_key' => self::LIVE_SECRET,
            'test_public_key' => 'pk_test_123',
            'test_secret_key' => 'sk_test_secret',
        ], $overrides);

        return new Payment_Adapter_Paystack($config);
    }

    private function invokeIsIpnValid(Payment_Adapter_Paystack $adapter, array $data): bool
    {
        $method = new ReflectionMethod($adapter, 'isIpnValid');
        $method->setAccessible(true);

        return (bool) $method->invoke($adapter, $data);
    }

    public function testConfigDeclaresOneTimePaymentsOnly(): void
    {
        $config = Payment_Adapter_Paystack::getConfig();

        $this->assertTrue($config['supports_one_time_payments']);
        $this->assertFalse($config['supports_subscriptions']);
        $this->assertArrayHasKey('live_public_key', $config['form']);
        $this->assertArrayHasKey('live_secret_key', $config['form']);
        $this->assertArrayHasKey('charge', $config['form']);
    }

    public function testConstructorRequiresLiveSecretKey(): void
    {
        $this->expectException(Payment_Exception::class);
        new Payment_Adapter_Paystack(['live_public_key' => 'pk_live_123']); // no secret key
    }

    public function testUsesLiveKeysByDefault(): void
    {
        $adapter = $this->makeAdapter();

        $this->assertSame('pk_live_123', $adapter->getPublicKey());
        $this->assertSame(self::LIVE_SECRET, $adapter->getSecretKey());
    }

    public function testUsesTestKeysInTestMode(): void
    {
        $adapter = $this->makeAdapter(['test_mode' => true]);

        $this->assertSame('pk_test_123', $adapter->getPublicKey());
        $this->assertSame('sk_test_secret', $adapter->getSecretKey());
    }

    public function testIpnSignatureAcceptsValidSignature(): void
    {
        $adapter = $this->makeAdapter();
        $payload = '{"event":"charge.success","data":{"reference":"ref_1"}}';
        $signature = hash_hmac('sha512', $payload, self::LIVE_SECRET);

        $data = [
            'server' => ['HTTP_X_PAYSTACK_SIGNATURE' => $signature],
            'http_raw_post_data' => $payload,
        ];

        $this->assertTrue($this->invokeIsIpnValid($adapter, $data));
    }

    public function testIpnSignatureRejectsTamperedPayload(): void
    {
        $adapter = $this->makeAdapter();
        $signedPayload = '{"event":"charge.success","data":{"amount":100}}';
        $signature = hash_hmac('sha512', $signedPayload, self::LIVE_SECRET);

        // Attacker keeps the valid signature but changes the body.
        $data = [
            'server' => ['HTTP_X_PAYSTACK_SIGNATURE' => $signature],
            'http_raw_post_data' => '{"event":"charge.success","data":{"amount":999999}}',
        ];

        $this->assertFalse($this->invokeIsIpnValid($adapter, $data));
    }

    public function testIpnSignatureRejectsWrongSignature(): void
    {
        $adapter = $this->makeAdapter();

        $data = [
            'server' => ['HTTP_X_PAYSTACK_SIGNATURE' => 'not-a-valid-signature'],
            'http_raw_post_data' => '{"event":"charge.success"}',
        ];

        $this->assertFalse($this->invokeIsIpnValid($adapter, $data));
    }

    public function testIpnSignatureRejectsMissingHeader(): void
    {
        $adapter = $this->makeAdapter();

        $data = [
            'server' => [],
            'http_raw_post_data' => '{"event":"charge.success"}',
        ];

        $this->assertFalse($this->invokeIsIpnValid($adapter, $data));
    }
}
