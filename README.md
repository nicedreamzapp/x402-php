# x402-php

**Let AI agents pay your site.** A PHP implementation of the [x402 payment protocol](https://x402.org) — the Linux Foundation standard for machine-to-machine payments over HTTP.

Zero dependencies. No account with anyone. Money settles from the agent's wallet straight to yours.

```php
$booth  = new TollBooth(new Facilitator());
$option = new PaymentOption('0xYourWallet', '$0.01', Networks::BASE);

$result = $booth->collect($option, TollBooth::currentUrl());

if (!$result->paid) {
    $result->send();   // 402 Payment Required, with your price attached
    return;
}

echo $article;          // deliver first
$booth->settle($result); // then take the money
```

## Why this exists

The official x402 SDKs are TypeScript, Python, and Go. PHP runs roughly 40% of the web and every WordPress and WooCommerce site on it — the largest population of site owners on the internet had no first-class way to speak the protocol. This fills that gap.

## How it works

1. An AI agent requests a page. No payment attached, so you answer **402 Payment Required** with your terms: price, asset, network, and the wallet to pay.
2. The agent signs an authorization for that exact amount and retries with it in a `PAYMENT-SIGNATURE` header.
3. You verify it with a facilitator (a stateless service that checks signatures and pushes settlement on-chain), serve the content, then settle.

Your private key is never involved — this library only ever handles signatures the agent produced. A facilitator never custodies your funds either; settlement moves money directly from the payer's wallet to `payTo`.

## Install

```bash
composer require divinetribe/x402-php
```

Or drop the folder in and `require_once 'x402-php/autoload.php';` — the bundled autoloader means Composer is optional, which matters inside WordPress.

Requires PHP 8.0+ with `json` and `curl`.

## Charging for something

```php
use X402\{TollBooth, Facilitator, PaymentOption, Networks};

$booth = new TollBooth(new Facilitator());   // defaults to https://x402.org/facilitator

$option = new PaymentOption(
    payTo:   '0xYourWallet',
    price:   '$0.01',
    network: Networks::BASE      // or Networks::BASE_SEPOLIA to test for free
);

$result = $booth->collect($option, 'https://example.com/article');

if (!$result->paid) {
    $result->send();
    exit;
}

echo $content;

$receipt = $booth->settle($result);
// ['success' => true, 'transaction' => '0x…', 'payer' => '0x…']
```

Settle **after** delivering. If settlement fails you have already served one cheap page; if you settled first and delivery failed, you charged for nothing.

### Accepting more than one currency or chain

```php
$result = $booth->collect([
    new PaymentOption('0xYourWallet', '$0.01', Networks::BASE),
    new PaymentOption('0xYourWallet', '$0.01', Networks::BASE_SEPOLIA),
], $url);
```

The agent picks whichever it can pay.

### Running your own facilitator

```php
new Facilitator('https://facilitator.example.com', 30, ['Authorization' => 'Bearer …']);
```

Nothing in this library assumes a particular provider.

## Prices are strings, deliberately

`'$0.001'` is parsed as a decimal string and converted to atomic units with integer math. Floats are never involved, because `0.1 + 0.2` is not `0.3` and money that is off by a rounding error is money that is off.

A price finer than the asset's precision throws rather than silently rounding to zero:

```php
Price::toAtomic('$0.001', 6);      // "1000"
Price::toAtomic('$0.0000001', 6);  // X402Exception — would have rounded away
```

## What is verified

An agent echoes back the terms it is paying. Trusting that echo is how you let a client set its own price, so `collect()` checks the echo against the terms you actually offered — scheme, network, asset, destination wallet, and amount — before the facilitator is ever called. Overpayment is accepted; underpayment, a swapped asset, a different chain, or a redirected `payTo` are all rejected.

## Networks

| Network | CAIP-2 | Default asset |
|---|---|---|
| Base | `eip155:8453` | USDC |
| Base Sepolia (testnet) | `eip155:84532` | USDC |

Any other chain works by passing `asset` and `decimals` to `PaymentOption` explicitly.

## Try it

```bash
php -S 127.0.0.1:4022 examples/toll_server.php
curl -i http://127.0.0.1:4022/blog     # 402, with terms
```

Point any x402 client at it. Verified end-to-end against the official Python client and the live `x402.org` facilitator on Base Sepolia: the PHP booth's terms are byte-identical to the reference implementation's, and a real signed payment round-trips through verification.

## Tests

```bash
php tests/run.php
```

No test framework required.

## License

Apache-2.0, matching the x402 protocol itself.

---

Built by [Matt Macosko](https://ineedhemp.com) — running in production on ineedhemp.com, an independent e-commerce store charging AI agents since 2026.
