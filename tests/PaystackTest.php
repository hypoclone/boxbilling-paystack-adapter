<?php

/**
 * Configuration, key selection, DI wiring and webhook (IPN) signature verification.
 */
class PaystackTest extends PaystackTestCase
{
    public function testConfigDeclaresOneTimePaymentsOnly(): void
    {
        $config = Payment_Adapter_Paystack::getConfig();

        $this->assertTrue($config['supports_one_time_payments']);
        $this->assertFalse($config['supports_subscriptions']);
        $this->assertTrue($config['can_load_in_iframe']);
    }

    public function testConfigFormExposesEverySettingTheAdapterReads(): void
    {
        $form = Payment_Adapter_Paystack::getConfig()['form'];

        foreach (['live_public_key', 'live_secret_key', 'test_public_key', 'test_secret_key', 'charge', 'auto_process_invoice'] as $field) {
            $this->assertArrayHasKey($field, $form, "Config form is missing the \"$field\" field");
            $this->assertIsArray($form[$field]);
            $this->assertArrayHasKey('label', $form[$field][1]);
        }

        $this->assertSame(['1' => 'Yes', '0' => 'No'], $form['auto_process_invoice'][1]['multiOptions']);
    }

    public function testConfigDeclaresLogo(): void
    {
        $logo = Payment_Adapter_Paystack::getConfig()['logo'];

        $this->assertSame('paystack.png', $logo['logo']);
        $this->assertArrayHasKey('height', $logo);
        $this->assertArrayHasKey('width', $logo);
    }

    public function testConstructorRequiresLivePublicKey(): void
    {
        $this->expectException(Payment_Exception::class);
        $this->expectExceptionMessageMatches('/Live Public Key/');

        new Payment_Adapter_Paystack(['live_secret_key' => self::LIVE_SECRET]);
    }

    public function testConstructorRequiresLiveSecretKey(): void
    {
        $this->expectException(Payment_Exception::class);
        $this->expectExceptionMessageMatches('/Live Secret Key/');

        new Payment_Adapter_Paystack(['live_public_key' => self::LIVE_PUBLIC]);
    }

    public function testConstructorAcceptsCompleteConfig(): void
    {
        $this->assertInstanceOf(Payment_Adapter_Paystack::class, $this->makeAdapter());
    }

    public function testUsesLiveKeysByDefault(): void
    {
        $adapter = $this->makeAdapter();

        $this->assertSame(self::LIVE_PUBLIC, $adapter->getPublicKey());
        $this->assertSame(self::LIVE_SECRET, $adapter->getSecretKey());
    }

    public function testUsesTestKeysInTestMode(): void
    {
        $adapter = $this->makeAdapter(['test_mode' => true]);

        $this->assertSame(self::TEST_PUBLIC, $adapter->getPublicKey());
        $this->assertSame(self::TEST_SECRET, $adapter->getSecretKey());
    }

    /**
     * FOSSBilling stores gateway settings as strings, so "0"/""/null must all
     * read as "live mode" rather than truthily flipping to the test keys.
     *
     * @dataProvider falsyTestModeProvider
     */
    public function testFalsyTestModeMeansLiveKeys($testMode): void
    {
        $adapter = $this->makeAdapter(['test_mode' => $testMode]);

        $this->assertSame(self::LIVE_PUBLIC, $adapter->getPublicKey());
        $this->assertSame(self::LIVE_SECRET, $adapter->getSecretKey());
    }

    public static function falsyTestModeProvider(): array
    {
        return [
            'string zero' => ['0'],
            'empty string' => [''],
            'boolean false' => [false],
            'null' => [null],
            'integer zero' => [0],
        ];
    }

    /**
     * A gateway configured for live use only must not warn or fatal when the
     * optional test keys were never filled in.
     */
    public function testMissingKeysDegradeToEmptyStringWithoutWarnings(): void
    {
        $adapter = new Payment_Adapter_Paystack([
            'live_public_key' => self::LIVE_PUBLIC,
            'live_secret_key' => self::LIVE_SECRET,
            'test_mode' => true,
        ]);

        $this->assertSame('', $adapter->getPublicKey());
        $this->assertSame('', $adapter->getSecretKey());
    }

    public function testGetTypeIsHtml(): void
    {
        $this->assertSame(Payment_AdapterAbstract::TYPE_HTML, $this->makeAdapter()->getType());
    }

    public function testGetActionsExposesVerifyAction(): void
    {
        $actions = $this->makeAdapter()->getActions();

        $this->assertCount(1, $actions);
        $this->assertSame('verify', $actions[0]['name']);
        $this->assertSame('Verify Transaction', $actions[0]['label']);
    }

    public function testGetServiceUrlIsNullBecauseCheckoutIsInline(): void
    {
        $this->assertNull($this->makeAdapter()->getServiceUrl());
    }

    public function testGetInvoiceTitlePadsInvoiceNumber(): void
    {
        $title = $this->makeAdapter()->getInvoiceTitle(['nr' => 42, 'serie' => 'BB']);

        $this->assertSame('Payment for invoice BB00042', $title);
    }

    public function testDiRoundTrip(): void
    {
        $adapter = $this->makeAdapter();
        $container = $this->makeContainer();
        $adapter->setDi($container);

        $this->assertSame($container, $adapter->getDi());
    }

    public function testIpnSignatureAcceptsValidSignature(): void
    {
        $adapter = $this->makeAdapter();
        $payload = '{"event":"charge.success","data":{"reference":"ref_1"}}';

        $this->assertTrue($this->callPrivate($adapter, 'isIpnValid', $this->signedIpn($payload)));
    }

    public function testIpnSignatureRejectsTamperedPayload(): void
    {
        $adapter = $this->makeAdapter();
        $signed = $this->signedIpn('{"event":"charge.success","data":{"amount":100}}');

        // Attacker keeps the valid signature but inflates the amount.
        $signed['http_raw_post_data'] = '{"event":"charge.success","data":{"amount":999999}}';

        $this->assertFalse($this->callPrivate($adapter, 'isIpnValid', $signed));
    }

    public function testIpnSignatureRejectsSignatureFromAnotherSecret(): void
    {
        $adapter = $this->makeAdapter();
        $payload = '{"event":"charge.success"}';

        $forged = $this->signedIpn($payload, 'sk_live_someone_elses_secret');

        $this->assertFalse($this->callPrivate($adapter, 'isIpnValid', $forged));
    }

    /**
     * @dataProvider malformedSignatureProvider
     */
    public function testIpnSignatureRejectsMalformedHeaders(array $server): void
    {
        $adapter = $this->makeAdapter();

        $data = ['server' => $server, 'http_raw_post_data' => '{"event":"charge.success"}'];

        $this->assertFalse($this->callPrivate($adapter, 'isIpnValid', $data));
    }

    public static function malformedSignatureProvider(): array
    {
        return [
            'missing header' => [[]],
            'empty signature' => [['HTTP_X_PAYSTACK_SIGNATURE' => '']],
            'junk signature' => [['HTTP_X_PAYSTACK_SIGNATURE' => 'not-a-valid-signature']],
            'truncated signature' => [['HTTP_X_PAYSTACK_SIGNATURE' => substr(hash_hmac('sha512', '{"event":"charge.success"}', self::LIVE_SECRET), 0, 32)]],
            'unrelated header only' => [['HTTP_X_SOMETHING_ELSE' => 'abc']],
        ];
    }

    public function testIpnSignatureIsVerifiedAgainstTheActiveModeSecret(): void
    {
        $payload = '{"event":"charge.success"}';
        $testModeAdapter = $this->makeAdapter(['test_mode' => true]);

        $this->assertTrue(
            $this->callPrivate($testModeAdapter, 'isIpnValid', $this->signedIpn($payload, self::TEST_SECRET)),
            'A webhook signed with the test secret must validate while in test mode'
        );
        $this->assertFalse(
            $this->callPrivate($testModeAdapter, 'isIpnValid', $this->signedIpn($payload, self::LIVE_SECRET)),
            'A webhook signed with the live secret must not validate while in test mode'
        );
    }

    /**
     * Regression guard: the signing secret must be read per call. An earlier
     * revision cached it in a global constant, so a second gateway handling a
     * webhook in the same request verified against the first one's key.
     */
    public function testEachAdapterVerifiesAgainstItsOwnSecret(): void
    {
        $payload = '{"event":"charge.success"}';

        $first = $this->makeAdapter(['live_secret_key' => 'sk_live_first']);
        $second = $this->makeAdapter(['live_secret_key' => 'sk_live_second']);

        $this->assertTrue($this->callPrivate($first, 'isIpnValid', $this->signedIpn($payload, 'sk_live_first')));
        $this->assertTrue($this->callPrivate($second, 'isIpnValid', $this->signedIpn($payload, 'sk_live_second')));
        $this->assertFalse($this->callPrivate($second, 'isIpnValid', $this->signedIpn($payload, 'sk_live_first')));
    }

    /**
     * Verifying twice in one request must stay stable (no constant redefinition,
     * no cached state) and must not depend on call order.
     */
    public function testSignatureVerificationIsRepeatable(): void
    {
        $adapter = $this->makeAdapter();
        $valid = $this->signedIpn('{"event":"charge.success"}');

        $this->assertTrue($this->callPrivate($adapter, 'isIpnValid', $valid));
        $this->assertFalse($this->callPrivate($adapter, 'isIpnValid', ['server' => ['HTTP_X_PAYSTACK_SIGNATURE' => 'bogus'], 'http_raw_post_data' => 'x']));
        $this->assertTrue($this->callPrivate($adapter, 'isIpnValid', $valid));
    }
}
