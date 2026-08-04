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
            const TYPE_FORM = 'form';
            const TYPE_API = 'api';
        }
    }

    if (!class_exists('Payment_Transaction')) {
        class Payment_Transaction
        {
            const TXTYPE_PAYMENT = 'payment';
            const TXTYPE_REFUND = 'refund';
        }
    }

    if (!class_exists('Model_Transaction')) {
        class Model_Transaction
        {
            const STATUS_RECEIVED = 'received';
            const STATUS_APPROVED = 'approved';
            const STATUS_PROCESSED = 'processed';
            const STATUS_ERROR = 'error';
        }
    }

    if (!class_exists('Model_Invoice')) {
        class Model_Invoice
        {
            const STATUS_UNPAID = 'unpaid';
            const STATUS_PAID = 'paid';
            const STATUS_REFUNDED = 'refunded';
        }
    }

    if (!function_exists('__')) {
        /**
         * FOSSBilling's translation helper: substitutes :placeholders into a string.
         */
        function __($string, $params = [])
        {
            return strtr($string, $params);
        }
    }

    require __DIR__ . '/../src/Paystack.php';
    require __DIR__ . '/Fakes.php';
    require __DIR__ . '/PaystackTestCase.php';
}
