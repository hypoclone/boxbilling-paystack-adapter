<?php

/**
 * Minimal stubs for the FOSSBilling framework pieces the adapter references,
 * so the adapter's pure logic can be unit-tested in isolation (no full platform needed).
 */

namespace FOSSBilling {
    if (!interface_exists(InjectionAwareInterface::class)) {
        interface InjectionAwareInterface
        {
            public function setDi(\Pimple\Container $di): void;

            public function getDi(): ?\Pimple\Container;
        }
    }
}

namespace {
    require __DIR__ . '/../vendor/autoload.php';

    if (!class_exists('Payment_Exception')) {
        class Payment_Exception extends \Exception
        {
        }
    }

    if (!class_exists('Payment_AdapterAbstract')) {
        class Payment_AdapterAbstract
        {
            const TYPE_HTML = 'html';
        }
    }

    require __DIR__ . '/../src/Paystack.php';
}
