<?php

/**
 * Checkout rendering: subunit amount maths, the optional transaction charge,
 * and escaping of everything the buyer controls.
 */
class PaystackCheckoutTest extends PaystackTestCase
{
    private function render(array $configOverrides = [], array $invoiceOverrides = []): string
    {
        $api = new FakeAdminApi();
        $api->invoices[42] = $this->invoiceRow($invoiceOverrides);

        return $this->makeAdapter($configOverrides)->getHtml($api, 42, null);
    }

    public function testAmountIsSentInTheCurrencySubunit(): void
    {
        $html = $this->render([], ['total' => 100.00]);

        $this->assertStringContainsString('amount: 10000,', $html);
    }

    public function testAmountIsConvertedToTheDefaultCurrencyFirst(): void
    {
        $this->currency = new FakeCurrencyService('NGN', 250.00);
        $api = new FakeAdminApi();
        $api->invoices[42] = $this->invoiceRow(['currency' => 'USD', 'total' => 20.00]);

        $html = $this->makeAdapter()->getHtml($api, 42, null);

        $this->assertSame([['USD', 20.00]], $this->currency->conversions);
        $this->assertStringContainsString('amount: 25000,', $html);
        $this->assertStringContainsString('currency: "NGN",', $html);
    }

    public function testSubunitAmountIsRoundedHalfUpToAnInteger(): void
    {
        $html = $this->render([], ['total' => 0.125]);

        // 0.125 -> 12.5 subunits -> 13, never "12.5"
        $this->assertStringContainsString('amount: 13,', $html);
        $this->assertStringNotContainsString('amount: 12.5', $html);
    }

    public function testTransactionChargeIsAddedOnTop(): void
    {
        $html = $this->render(['charge' => '2'], ['total' => 100.00]);

        $this->assertStringContainsString('amount: 10200,', $html);
        $this->assertStringContainsString('2% fee applies', $html);
    }

    public function testFractionalChargeIsRoundedAndLabelled(): void
    {
        $html = $this->render(['charge' => '2.5'], ['total' => 10.01]);

        // 1001 subunits + 2.5% (25.025 -> 25) = 1026
        $this->assertStringContainsString('amount: 1026,', $html);
        $this->assertStringContainsString('2.5% fee applies', $html);
    }

    /**
     * @dataProvider noChargeProvider
     */
    public function testNonNumericOrAbsentChargeIsIgnored(array $configOverrides): void
    {
        $html = $this->render($configOverrides, ['total' => 100.00]);

        $this->assertStringContainsString('amount: 10000,', $html);
        $this->assertStringNotContainsString('fee applies', $html);
    }

    public static function noChargeProvider(): array
    {
        return [
            'not configured' => [[]],
            'empty string' => [['charge' => '']],
            'non-numeric' => [['charge' => 'abc']],
            'zero' => [['charge' => '0']],
            'negative' => [['charge' => '-5']],
            'null' => [['charge' => null]],
        ];
    }

    public function testUsesThePublicKeyForTheActiveMode(): void
    {
        $this->assertStringContainsString('key: "' . self::LIVE_PUBLIC . '",', $this->render());
        $this->assertStringContainsString('key: "' . self::TEST_PUBLIC . '",', $this->render(['test_mode' => true]));
    }

    public function testSecretKeyIsNeverRenderedIntoTheCheckoutMarkup(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString(self::LIVE_SECRET, $html);
        $this->assertStringNotContainsString(self::TEST_SECRET, $html);
    }

    public function testBuyerDetailsAreRendered(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('email: "buyer@example.com",', $html);
        $this->assertStringContainsString('firstname: "Ama",', $html);
        $this->assertStringContainsString('lastname: "Mensah",', $html);
    }

    /**
     * A buyer-supplied name must not be able to terminate the inline <script>
     * block or inject statements into it.
     */
    public function testBuyerSuppliedValuesCannotBreakOutOfTheScriptBlock(): void
    {
        $closingTags = substr_count($this->render(), '</script>');

        $buyer = [
            'email' => '"><script>alert(1)</script>@example.com',
            'first_name' => '</script><script>alert(1)</script>',
            'last_name' => "'; window.stolen = 1; //",
        ];
        $html = $this->render([], ['buyer' => $buyer]);

        // The block cannot be terminated early: the only closing tags are the
        // adapter's own. (A literal "<script>" inside a JS string is inert;
        // "</script>" is what an HTML parser would act on, and it is escaped.)
        $this->assertSame($closingTags, substr_count($html, '</script>'));
        $this->assertStringContainsString('<\/script>', $html);

        // Every value survives as a quoted string literal, so none of it is
        // ever parsed as code.
        $this->assertSame($buyer['email'], $this->jsStringValue($html, 'email'));
        $this->assertSame($buyer['first_name'], $this->jsStringValue($html, 'firstname'));
        $this->assertSame($buyer['last_name'], $this->jsStringValue($html, 'lastname'));
    }

    public function testApostropheAndQuotesInNamesStayInsideAValidJsString(): void
    {
        $buyer = [
            'email' => "o'brien@example.com",
            'first_name' => "O'Brien",
            'last_name' => 'Ama "AM" Mensah',
        ];

        $html = $this->render([], ['buyer' => $buyer]);

        $this->assertSame($buyer['first_name'], $this->jsStringValue($html, 'firstname'));
        $this->assertSame($buyer['last_name'], $this->jsStringValue($html, 'lastname'));
        $this->assertSame($buyer['email'], $this->jsStringValue($html, 'email'));
    }

    public function testUnicodeNamesAreRenderedAsValidJsStrings(): void
    {
        $buyer = ['email' => 'kwame@example.com', 'first_name' => 'Yaa 💛', 'last_name' => 'Asantewaa'];

        $html = $this->render([], ['buyer' => $buyer]);

        $this->assertSame($buyer['first_name'], $this->jsStringValue($html, 'firstname'));
    }

    /**
     * Pull a rendered `key: "…"` value back out of the checkout script and
     * decode it, proving it is a well-formed string literal.
     */
    private function jsStringValue(string $html, string $key): string
    {
        $matched = preg_match('/\b' . preg_quote($key, '/') . ': ("(?:[^"\\\\]|\\\\.)*")/', $html, $matches);
        $this->assertSame(1, $matched, "No quoted \"$key\" value found in the rendered checkout");

        $decoded = json_decode($matches[1]);
        $this->assertIsString($decoded, "The \"$key\" value is not a valid string literal");

        return $decoded;
    }

    public function testInvoiceAndGatewayIdsAreEmittedAsIntegers(): void
    {
        $html = $this->render([], ['id' => '42abc', 'gateway_id' => '7; alert(1)']);

        $this->assertStringContainsString('bb_gateway_id: 7,', $html);
        $this->assertStringContainsString('bb_invoice_id: 42,', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
    }

    public function testInvoiceIdIsRepeatedInTheCustomField(): void
    {
        $html = $this->render();

        $this->assertStringContainsString("variable_name: 'bb_invoice_id',", $html);
        $this->assertStringContainsString('value: 42', $html);
    }

    public function testMissingBuyerDetailsRenderAsEmptyStrings(): void
    {
        $html = $this->render([], ['buyer' => []]);

        $this->assertStringContainsString('email: "",', $html);
        $this->assertStringContainsString('firstname: "",', $html);
        $this->assertStringContainsString('lastname: "",', $html);
    }

    public function testFormLoadsPaystackInlineJsAndHasASubmitButton(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('https://js.paystack.co/v1/inline.js', $html);
        $this->assertStringContainsString('<form id="paymentForm">', $html);
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('handler.openIframe();', $html);
    }

    public function testCheckoutReadsTheRequestedInvoice(): void
    {
        $api = new FakeAdminApi();
        $api->invoices[42] = $this->invoiceRow();

        $this->makeAdapter()->getHtml($api, 42, null);

        $this->assertSame([['id' => 42]], $api->payloadsFor('invoice_get'));
    }
}
