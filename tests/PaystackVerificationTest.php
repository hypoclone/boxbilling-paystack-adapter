<?php

/**
 * verifyTransaction()/processAction()/isIpnDuplicate(): confirming a payment
 * against the Paystack API rather than trusting the webhook body.
 */
class PaystackVerificationTest extends PaystackTestCase
{
    private FakeAdminApi $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeAdminApi();
        $this->api->transactions[1] = $this->transactionRow();
    }

    private function ipn(array $overrides = []): array
    {
        return ['http_raw_post_data' => $this->chargeSuccessPayload($overrides)];
    }

    private function verificationResponse(bool $status = true, array $overrides = []): string
    {
        if (!$status) {
            return json_encode(['status' => false, 'message' => 'Transaction reference not found']);
        }

        return json_encode(array_merge([
            'status' => true,
            'message' => 'Verification successful',
            'data' => ['status' => 'success', 'reference' => 'ref_123', 'amount' => 10000],
        ], $overrides));
    }

    public function testVerificationCallsPaystackWithTheReference(): void
    {
        $adapter = $this->makeStubbedAdapter([], $this->verificationResponse());

        $adapter->verifyTransaction($this->api, 1, $this->ipn());

        $this->assertSame(['/verify/ref_123'], $adapter->requestedPaths);
    }

    /**
     * A reference is attacker-influenced data that lands in a URL path, so it
     * must not be able to escape the /verify/ endpoint.
     */
    public function testReferenceIsUrlEncodedIntoTheRequestPath(): void
    {
        $adapter = $this->makeStubbedAdapter([], $this->verificationResponse());

        $adapter->verifyTransaction($this->api, 1, $this->ipn(['reference' => '../../refund/ref 1?x=y']));

        $this->assertSame(['/verify/..%2F..%2Frefund%2Fref%201%3Fx%3Dy'], $adapter->requestedPaths);
    }

    public function testSuccessfulVerificationApprovesTheTransaction(): void
    {
        $adapter = $this->makeStubbedAdapter([], $this->verificationResponse());

        $result = $adapter->verifyTransaction($this->api, 1, $this->ipn());

        $this->assertTrue($result);
        $update = $this->api->lastPayloadFor('invoice_transaction_update');
        $this->assertSame(1, $update['id']);
        $this->assertSame(Model_Transaction::STATUS_APPROVED, $update['status']);
        $this->assertSame('success', $update['txn_status']);
        $this->assertSame('Verification successful', $update['note']);
        $this->assertSame('', $update['error']);
        $this->assertNull($update['error_code']);
        $this->assertArrayHasKey('updated_at', $update);
    }

    public function testAlreadyProcessedTransactionKeepsItsStatus(): void
    {
        $this->api->transactions[1] = $this->transactionRow(['status' => Model_Transaction::STATUS_PROCESSED]);
        $adapter = $this->makeStubbedAdapter([], $this->verificationResponse());

        $adapter->verifyTransaction($this->api, 1, $this->ipn());

        $this->assertSame(
            Model_Transaction::STATUS_PROCESSED,
            $this->api->lastPayloadFor('invoice_transaction_update')['status'],
            'Verifying again must not demote a processed transaction to approved'
        );
    }

    public function testFailedVerificationLeavesTheTransactionUnapproved(): void
    {
        $adapter = $this->makeStubbedAdapter([], $this->verificationResponse(false));

        $result = $adapter->verifyTransaction($this->api, 1, $this->ipn());

        $this->assertFalse($result);
        $update = $this->api->lastPayloadFor('invoice_transaction_update');
        $this->assertSame(Model_Transaction::STATUS_RECEIVED, $update['status']);
        $this->assertSame('unknown', $update['txn_status']);
        $this->assertSame('Transaction reference not found', $update['error']);
    }

    public function testNonSuccessEventIsNotVerifiedAtAll(): void
    {
        $payload = json_encode(['event' => 'charge.failed', 'data' => ['reference' => 'ref_123']]);
        $adapter = $this->makeStubbedAdapter([], $this->verificationResponse());

        $result = $adapter->verifyTransaction($this->api, 1, ['http_raw_post_data' => $payload]);

        $this->assertFalse($result);
        $this->assertSame([], $adapter->requestedPaths);
        $this->assertSame([], $this->api->calls);
    }

    /**
     * @dataProvider unusableResponseProvider
     */
    public function testUnusableApiResponsesLeaveTheTransactionUntouched($response): void
    {
        $adapter = $this->makeStubbedAdapter([], $response);

        $this->assertFalse($adapter->verifyTransaction($this->api, 1, $this->ipn()));
        $this->assertSame([], $this->api->calls);
    }

    public static function unusableResponseProvider(): array
    {
        return [
            'network failure' => [false],
            'empty body' => [''],
            'html error page' => ['<html><body>502 Bad Gateway</body></html>'],
            'json array, not object' => ['[1,2,3]'],
            'json null' => ['null'],
        ];
    }

    /**
     * @dataProvider malformedWebhookBodyProvider
     */
    public function testMalformedWebhookBodyIsRejected(?string $body): void
    {
        $adapter = $this->makeStubbedAdapter([], $this->verificationResponse());

        $this->assertFalse($adapter->verifyTransaction($this->api, 1, ['http_raw_post_data' => $body]));
        $this->assertSame([], $adapter->requestedPaths);
    }

    public static function malformedWebhookBodyProvider(): array
    {
        return [
            'empty body' => [''],
            'not json' => ['not json at all'],
            'json without event' => ['{"data":{"reference":"ref_1"}}'],
            'json scalar' => ['"charge.success"'],
            'null body' => [null],
        ];
    }

    public function testProcessActionVerifyDelegatesToVerification(): void
    {
        $adapter = $this->makeStubbedAdapter([], $this->verificationResponse());

        $result = $adapter->processAction($this->api, 1, $this->ipn(), 7, 'verify');

        $this->assertTrue($result);
        $this->assertSame(['/verify/ref_123'], $adapter->requestedPaths);
    }

    public function testUnknownActionDoesNothing(): void
    {
        $adapter = $this->makeStubbedAdapter([], $this->verificationResponse());

        $this->assertNull($adapter->processAction($this->api, 1, $this->ipn(), 7, 'refund'));
        $this->assertSame([], $adapter->requestedPaths);
        $this->assertSame([], $this->api->calls);
    }

    public function testDuplicateIpnDetectedWhenMoreThanOneMatchingRowExists(): void
    {
        $this->db->rows = [['id' => 1], ['id' => 2]];

        $this->assertTrue($this->duplicateCheck());
    }

    /**
     * @dataProvider nonDuplicateRowsProvider
     */
    public function testNotADuplicateWhenAtMostOneRowMatches(array $rows): void
    {
        $this->db->rows = $rows;

        $this->assertFalse($this->duplicateCheck());
    }

    public static function nonDuplicateRowsProvider(): array
    {
        return [
            'no rows' => [[]],
            'single row' => [[['id' => 1]]],
        ];
    }

    public function testDuplicateCheckIsFullyParameterised(): void
    {
        $this->duplicateCheck();

        $this->assertSame([
            ':transaction_id' => 'ref_123',
            ':transaction_status' => 'success',
            ':transaction_type' => Payment_Transaction::TXTYPE_PAYMENT,
            ':transaction_amount' => 100.0,
        ], $this->db->lastBindings);
        $this->assertStringNotContainsString('ref_123', (string) $this->db->lastSql, 'Values must be bound, never interpolated');
    }

    private function duplicateCheck(): bool
    {
        $ipn = [
            'data' => ['status' => 'success'],
            'txn_type' => Payment_Transaction::TXTYPE_PAYMENT,
        ];

        return $this->makeAdapter()->isIpnDuplicate('ref_123', 42, 7, 100.0, $ipn);
    }
}
