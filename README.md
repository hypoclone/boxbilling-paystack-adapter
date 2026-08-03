# FOSSBilling Paystack Payment Adapter

A [Paystack](https://paystack.com/) payment gateway for [FOSSBilling](https://fossbilling.org/)
(and the BoxBilling it forked from). It lets FOSSBilling accept one-time invoice payments via
Paystack — cards and mobile money (MoMo) — with separate test and live modes.

> Community adapter — not an official integration from Paystack or FOSSBilling.

## Features

- One-time invoice payments through Paystack (cards, mobile money)
- Live and test modes (separate public/secret keys)
- Configurable transaction charge (%)
- Optional automatic invoice processing after a successful payment
- Webhook (IPN) handling with signature verification
- Renders inline (loads in an iframe)

> Recurring/subscription billing is not supported.

## Requirements

- FOSSBilling (or legacy BoxBilling)
- PHP 8.1+ with the cURL extension enabled
- A Paystack account and API keys (Paystack Dashboard → **Settings → API Keys & Webhooks**)

## Installation

1. Copy the adapter into your FOSSBilling payment-adapters directory:

   ```bash
   cp src/Paystack.php  <fossbilling>/library/Payment/Adapter/Paystack.php
   ```

   *(Optional: add a `paystack.png` logo to your payment-gateway assets so it shows at checkout.)*

2. In the FOSSBilling admin, go to **System → Payment gateways**, find **Paystack**, and enable it.

3. Configure it:

   | Setting | Description |
   | --- | --- |
   | **Live Public Key** / **Live Secret Key** | From your Paystack dashboard |
   | **Test Public Key** / **Test Secret Key** | For sandbox testing |
   | **Transaction Charge (%)** | Optional fee added at checkout |
   | **Process invoice after payment** | Yes/No |

4. (Recommended) In the Paystack dashboard, set your **webhook URL** to your FOSSBilling IPN
   endpoint so payments are confirmed automatically. Incoming webhooks are validated against the
   `x-paystack-signature` header.

## Usage

Once enabled, Paystack appears as a payment option on client invoices. Customers pay by card or
mobile money; on a successful transaction the payment is recorded against the invoice (and the
invoice is auto-processed if that option is on).

## Development

```bash
composer install
composer test   # runs the PHPUnit suite
```

## License

[Apache-2.0](LICENSE).

## Author

Samuel Apraku
