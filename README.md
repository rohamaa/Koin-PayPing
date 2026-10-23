# Koin — PayPing

<p align="center">
  <a href="https://rohamaa.github.io/Koin-Core/">Docs site</a> ·
  <a href="https://github.com/rohamaa/Koin-Core/blob/docs/index.md">Docs on GitHub</a>
</p>

The **PayPing** gateway driver for the Koin package — built on PayPing's REST API v3.

### Install

```bash
composer require rohama/koin-payping
```

### Features

- Payment request — returns code + redirect URL (/pay/)
- Verify
- Refund
- Callback parsing



### Configuration

| Field | Description |
| --- | --- |
| `apiKey` | API token |

### Usage

```php
use Rohama\Koin\Payping\PaypingGateway;
use Rohama\Koin\Support\PaymentStatus;

$gateway = new PaypingGateway('token', 'https://example.com/callback');

$payment = $gateway->request(150000, 'ORDER-1', 'Subscription purchase');

if ($payment->isSuccessful()) {
    header('Location: ' . $payment->redirectUrl);
    exit;
}

// In the callback page — parseCallback runs verify() before reporting success
$callback = $gateway->parseCallback($_GET, $_POST);
if ($callback->status === PaymentStatus::SUCCESS) {
    // Verified
}
```

> `refund()` (in gateways that offer Refund) is available only when the developer-only 5th constructor parameter (`refundable`) is enabled.

Full documentation: <https://rohamaa.github.io/Koin-Core/>