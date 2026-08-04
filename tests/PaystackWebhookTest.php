<?php

/**
 * processTransaction(): what the adapter does with an incoming Paystack webhook.
 */
class PaystackWebhookTest extends PaystackTestCase
{
    private FakeAdminApi $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeAdminApi();
        $this->api->invoices[42] = $this->invoiceRow();
        $this->api->transactions[1] = $this->transactionRow();
    }

    private function successResponse(): string
    {
        return json_encode([
            'status' => true,
            'message' => 'Verification successful',
            'data' => ['status' => 'success', 'reference' => 'ref_123', 'amount' => 10000],
        ]);
    }

    public function testUnsignedWebhookIsRejectedBeforeAnythingIsTouched(): void
    {
        $adapter = $this->makeStubbedAdapter([], $this->successResponse());
        $data = [
            'server' => ['HTTP_X_PAYSTACK_SIGNATURE' => 'forged'],
            'http_raw_post_data' => $this->chargeSuccessPayload(),
        ];

        try {
            $adapter->processTransaction($this->api, 1, $data, 7);
            $this->fail('Expected a Payment_Exception for an invalid IPN signature');
        } catch (Payment_Exception $e) {
            $this->assertStringContainsString('IPN is not valid', $e->getMessage());
        }

        $this->assertSame([], $this->api->calls, 'No platform call may happen for an unverified webhook');
        $this->assertSame([], $adapter->requestedPaths);
    }

    public function testSuccessfulChargeRecordsTheTransactionAndMarksTheInvoicePaid(): void
    {
        $adapter = $this->makeStubbedAdapter(['auto_process_invoice' => '1'], $this->successResponse());

        $adapter->processTransaction($this->api, 1, $this->signedIpn($this->chargeSuccessPayload()), 7);

        $this->assertTrue($this->api->wasCalled('invoice_mark_as_paid'));
        $this->assertSame(
            ['id' => 42, 'check_product_setup' => true],
            $this->api->lastPayloadFor('invoice_mark_as_paid')
        );

        $final = $this->api->lastPayloadFor('invoice_transaction_update');
        $this->assertSame(1, $final['id']);
        $this->assertSame(42, $final['invoice_id']);
        $this->assertEquals(100.0, $final['amount'], 'Subunits from Paystack must be converted back to major units');
        $this->assertSame('GHS', $final['currency']);
        $this->assertSame('ref_123', $final['txn_id']);
        $this->assertSame(Payment_Transaction::TXTYPE_PAYMENT, $final['type']);
        $this->assertSame(Model_Transaction::STATUS_PROCESSED, $final['status']);
    }

    public function testSubunitAmountWithACentsRemainderIsConvertedExactly(): void
    {
        $adapter = $this->makeStubbedAdapter([], $this->successResponse());

        $adapter->processTransaction($this->api, 1, $this->signedIpn($this->chargeSuccessPayload(['amount' => 10055])), 7);

        $this->assertEquals(100.55, $this->api->lastPayloadFor('invoice_transaction_update')['amount']);
    }

    public function testInvoiceIdIsTakenFromTheWebhookMetadataWhenTheTransactionHasNone(): void
    {
        $adapter = $this->makeStubbedAdapter([], $this->successResponse());

        $adapter->processTransaction($this->api, 1, $this->signedIpn($this->chargeSuccessPayload()), 7);

        $this->assertSame(['id' => 42], $this->api->payloadsFor('invoice_get')[0]);
        $this->assertSame(42, $this->api->payloadsFor('invoice_transaction_update')[0]['invoice_id']);
    }

    public function testExistingInvoiceLinkIsKept(): void
    {
        $this->api->transactions[1] = $this->transactionRow(['invoice_id' => 99]);
        $this->api->invoices[99] = $this->invoiceRow(['id' => 99]);
        $adapter = $this->makeStubbedAdapter([], $this->successResponse());

        $adapter->processTransaction($this->api, 1, $this->signedIpn($this->chargeSuccessPayload()), 7);

        foreach ($this->api->payloadsFor('invoice_get') as $payload) {
            $this->assertSame(99, $payload['id'], 'Webhook metadata must not override the linked invoice');
        }
        $this->assertArrayNotHasKey('invoice_id', $this->api->lastPayloadFor('invoice_transaction_update'));
    }

    public function testAlreadyProcessedTransactionIsIgnored(): void
    {
        $this->api->transactions[1] = $this->transactionRow([
            'invoice_id' => 42,
            'status' => Model_Transaction::STATUS_PROCESSED,
        ]);
        $adapter = $this->makeStubbedAdapter(['auto_process_invoice' => '1'], $this->successResponse());

        $adapter->processTransaction($this->api, 1, $this->signedIpn($this->chargeSuccessPayload()), 7);

        // A replayed webhook must not pay the invoice a second time.
        $this->assertFalse($this->api->wasCalled('invoice_mark_as_paid'));
        $this->assertFalse($this->api->wasCalled('invoice_transaction_update'));
        $this->assertSame([], $adapter->requestedPaths);
    }

    public function testReceivedTransactionIsVerifiedAgainstPaystack(): void
    {
        $adapter = $this->makeStubbedAdapter([], $this->successResponse());

        $adapter->processTransaction($this->api, 1, $this->signedIpn($this->chargeSuccessPayload()), 7);

        $this->assertSame(['/verify/ref_123'], $adapter->requestedPaths);
    }

    public function testApprovedTransactionIsNotVerifiedAgain(): void
    {
        $this->api->transactions[1] = $this->transactionRow(['status' => Model_Transaction::STATUS_APPROVED]);
        $adapter = $this->makeStubbedAdapter([], $this->successResponse());

        $adapter->processTransaction($this->api, 1, $this->signedIpn($this->chargeSuccessPayload()), 7);

        $this->assertSame([], $adapter->requestedPaths);
    }

    public function testInvoiceIsNotPaidWhenAutoProcessingIsOff(): void
    {
        $adapter = $this->makeStubbedAdapter(['auto_process_invoice' => '0'], $this->successResponse());

        $adapter->processTransaction($this->api, 1, $this->signedIpn($this->chargeSuccessPayload()), 7);

        $this->assertFalse($this->api->wasCalled('invoice_mark_as_paid'));
        $this->assertArrayNotHasKey('status', $this->api->lastPayloadFor('invoice_transaction_update'));
    }

    public function testInvoiceIsNotPaidWhenAutoProcessingIsUnset(): void
    {
        $adapter = $this->makeStubbedAdapter([], $this->successResponse());

        $adapter->processTransaction($this->api, 1, $this->signedIpn($this->chargeSuccessPayload()), 7);

        $this->assertFalse($this->api->wasCalled('invoice_mark_as_paid'));
    }

    public function testInvoiceIsNotPaidWhenTheChargeItselfDidNotSucceed(): void
    {
        $payload = $this->chargeSuccessPayload(['status' => 'failed']);
        $adapter = $this->makeStubbedAdapter(['auto_process_invoice' => '1'], $this->successResponse());

        $adapter->processTransaction($this->api, 1, $this->signedIpn($payload), 7);

        $this->assertFalse($this->api->wasCalled('invoice_mark_as_paid'));
    }

    public function testNonChargeSuccessEventDoesNotPayTheInvoice(): void
    {
        $payload = json_encode([
            'event' => 'charge.failed',
            'data' => [
                'reference' => 'ref_123',
                'amount' => 10000,
                'currency' => 'GHS',
                'status' => 'failed',
                'metadata' => ['bb_invoice_id' => 42],
            ],
        ]);
        $adapter = $this->makeStubbedAdapter(['auto_process_invoice' => '1'], $this->successResponse());

        $adapter->processTransaction($this->api, 1, $this->signedIpn($payload), 7);

        $this->assertFalse($this->api->wasCalled('invoice_mark_as_paid'));
        $this->assertSame([], $adapter->requestedPaths, 'Only successful charges are worth verifying');
        $this->assertSame([], $this->logger->messages);
    }

    public function testTransactionFieldsAlreadyRecordedAreNotOverwritten(): void
    {
        $this->api->transactions[1] = $this->transactionRow([
            'invoice_id' => 42,
            'status' => Model_Transaction::STATUS_APPROVED,
            'amount' => 100.0,
            'currency' => 'GHS',
            'txn_id' => 'ref_original',
            'type' => Payment_Transaction::TXTYPE_PAYMENT,
        ]);
        $adapter = $this->makeStubbedAdapter([], $this->successResponse());

        $adapter->processTransaction($this->api, 1, $this->signedIpn($this->chargeSuccessPayload(['reference' => 'ref_replay'])), 7);

        $update = $this->api->lastPayloadFor('invoice_transaction_update');
        $this->assertArrayNotHasKey('txn_id', $update);
        $this->assertArrayNotHasKey('amount', $update);
        $this->assertArrayNotHasKey('currency', $update);
        $this->assertArrayNotHasKey('type', $update);
    }

    public function testTransactionIsMarkedProcessedOnceTheInvoiceIsPaid(): void
    {
        $this->api->invoices[42] = $this->invoiceRow(['status' => Model_Invoice::STATUS_PAID]);
        $adapter = $this->makeStubbedAdapter([], $this->successResponse());

        $adapter->processTransaction($this->api, 1, $this->signedIpn($this->chargeSuccessPayload()), 7);

        $this->assertSame(
            Model_Transaction::STATUS_PROCESSED,
            $this->api->lastPayloadFor('invoice_transaction_update')['status']
        );
    }

    public function testProcessingIsLoggedWithTheReference(): void
    {
        $adapter = $this->makeStubbedAdapter([], $this->successResponse());

        $adapter->processTransaction($this->api, 1, $this->signedIpn($this->chargeSuccessPayload()), 7);

        $this->assertNotEmpty($this->logger->messages);
        $this->assertStringContainsString('ref_123', $this->logger->messages[0]);
    }
}
