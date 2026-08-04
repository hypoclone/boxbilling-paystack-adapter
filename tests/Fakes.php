<?php

/**
 * Hand-rolled test doubles for the FOSSBilling collaborators the adapter talks to.
 *
 * They are deliberately simple recorders: the adapter's contract with the platform
 * is "which API calls, with which payload", so the tests assert on that.
 */

/**
 * Stand-in for the FOSSBilling admin API service the adapter is handed.
 */
class FakeAdminApi
{
    /** @var array<int, array{0:string, 1:array}> every call, in order */
    public array $calls = [];

    /** @var array<mixed, array> invoice id => invoice row */
    public array $invoices = [];

    /** @var array<mixed, array> transaction id => transaction row */
    public array $transactions = [];

    public function invoice_get(array $params)
    {
        $this->calls[] = ['invoice_get', $params];

        return $this->invoices[$params['id']] ?? [];
    }

    public function invoice_transaction_get(array $params)
    {
        $this->calls[] = ['invoice_transaction_get', $params];

        return $this->transactions[$params['id']] ?? [];
    }

    public function invoice_transaction_update(array $params)
    {
        $this->calls[] = ['invoice_transaction_update', $params];
        $id = $params['id'];
        $this->transactions[$id] = array_merge($this->transactions[$id] ?? [], $params);

        return true;
    }

    public function invoice_mark_as_paid(array $params)
    {
        $this->calls[] = ['invoice_mark_as_paid', $params];
        $id = $params['id'];
        // Mirror the platform: a paid invoice comes back as paid on the next read.
        $this->invoices[$id]['status'] = Model_Invoice::STATUS_PAID;

        return true;
    }

    /**
     * @return array<int, array> payloads of every call to $name, in order
     */
    public function payloadsFor(string $name): array
    {
        $payloads = [];
        foreach ($this->calls as [$called, $params]) {
            if ($called === $name) {
                $payloads[] = $params;
            }
        }

        return $payloads;
    }

    public function wasCalled(string $name): bool
    {
        return $this->payloadsFor($name) !== [];
    }

    /**
     * @return array|null the payload of the last call to $name
     */
    public function lastPayloadFor(string $name): ?array
    {
        $payloads = $this->payloadsFor($name);

        return $payloads === [] ? null : end($payloads);
    }
}

class FakeLogger
{
    /** @var array<int, string> */
    public array $messages = [];

    public function info($message)
    {
        $this->messages[] = $message;
    }

    public function debug($message)
    {
        $this->messages[] = $message;
    }

    public function error($message)
    {
        $this->messages[] = $message;
    }
}

class FakeDb
{
    /** @var array<int, array> rows returned by getAll() */
    public array $rows = [];

    public ?string $lastSql = null;

    public ?array $lastBindings = null;

    public function getAll($sql, $bindings = [])
    {
        $this->lastSql = $sql;
        $this->lastBindings = $bindings;

        return $this->rows;
    }
}

class FakeCurrencyService
{
    public function __construct(
        private string $defaultCode = 'GHS',
        private ?float $convertedAmount = null,
    ) {
    }

    /** @var array<int, array{0:string, 1:float}> */
    public array $conversions = [];

    public function toBaseCurrency($currency, $amount)
    {
        $this->conversions[] = [$currency, (float) $amount];

        return $this->convertedAmount ?? $amount;
    }

    public function getDefault()
    {
        return (object) ['code' => $this->defaultCode];
    }
}

/**
 * Adapter subclass that intercepts the outbound HTTP call, so verification logic
 * can be exercised without touching the network.
 */
class StubbedRequestPaystack extends Payment_Adapter_Paystack
{
    /** @var array<int, string> every path requested, in order */
    public array $requestedPaths = [];

    /** @var string|false canned response body */
    public $response = false;

    protected function request($path)
    {
        $this->requestedPaths[] = $path;

        return $this->response;
    }
}
