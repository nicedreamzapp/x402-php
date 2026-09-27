# x402-php

**Let AI agents pay your site.** A PHP implementation of the [x402 payment protocol](https://x402.org) — the Linux Foundation standard for machine-to-machine payments over HTTP.

It answers an AI agent with HTTP 402 and a price, checks the signed USDC payment the agent sends back, serves the content, then settles the payment on Base.

Zero dependencies. No account needed to test on Base Sepolia. Money settles from the agent's wallet straight to yours.

**Proof it works:** `php tests/run.php` runs 17 offline checks (price math, term matching, header parsing), all passing. [`examples/toll_server.php`](examples/toll_server.php) is a runnable paywall you can hit with `curl`. [`wordpress/`](wordpress) holds two WordPress plugins built on the library.

```php
$booth  = new TollBooth(new Facilitator());
$option = new PaymentOption('0xYourWallet', '$0.01', Networks::BASE_SEPOLIA);

$result = $booth->collect($option, TollBooth::currentUrl());

if (!$result->paid) {
    $result->send();   // 402 Payment Required, with your price attached
    return;
}

echo $article;          // deliver first
$booth->settle($result); // then take the money
```

## What I built

All code in this repo is by Matt Macosko (listed as author in `composer.json` and both plugin headers). Upstream: the x402 protocol, the x402.org and Coinbase facilitators, and USDC.

- **Payment gate**: [`src/TollBooth.php`](src/TollBooth.php) quotes terms, checks the agent's echo, verifies, and settles. [`src/TollResult.php`](src/TollResult.php) sends the 402 response.
- **Wire format**: [`src/PaymentRequirements.php`](src/PaymentRequirements.php), [`src/PaymentPayload.php`](src/PaymentPayload.php), [`src/PaymentOption.php`](src/PaymentOption.php).
- **Integer money math**: [`src/Price.php`](src/Price.php).
- **Networks and USDC domains**: [`src/Networks.php`](src/Networks.php).
- **Facilitator client**: [`src/Facilitator.php`](src/Facilitator.php) talks to the upstream x402.org or Coinbase facilitator over curl.
- **Coinbase auth**: [`src/Auth/CdpAuth.php`](src/Auth/CdpAuth.php) signs a fresh Ed25519 JWT per request with PHP's `sodium`, no JWT library.
- **WordPress toll booth**: [`wordpress/divine-tribe-tollbooth.php`](wordpress/divine-tribe-tollbooth.php) charges AI crawlers for blog posts, keeps search crawlers and humans free, and starts in `monitor` mode.
- **WordPress agent commerce**: [`wordpress/divine-tribe-agent-commerce.php`](wordpress/divine-tribe-agent-commerce.php) exposes a WooCommerce catalog at `/wp-json/agent/v1/catalog` and an x402-paid `/order` endpoint with a 21+ age attestation. Starts in `catalog` mode.
- **Tests and example**: [`tests/run.php`](tests/run.php), [`examples/toll_server.php`](examples/toll_server.php).

## Why this exists

The official x402 SDKs are TypeScript, Python, and Go. PHP runs roughly 40% of the web and every WordPress and WooCommerce site on it — the largest population of site owners on the internet had no first-class way to speak the protocol. This fills that gap.

## How it works

1. An AI agent requests a page. No payment attached, so you answer **402 Payment Required** with your terms: price, asset, network, and the wallet to pay.
2. The agent signs an authorization for that exact amount and retries with it in a `PAYMENT-SIGNATURE` header (the v1 `X-PAYMENT` header is also read).
3. You verify it with a facilitator (a stateless service that checks signatures and pushes settlement on-chain), serve the content, then settle.

Your private key is never involved — this library only ever handles signatures the agent produced. A facilitator never custodies your funds either; settlement moves money directly from the payer's wallet to `payTo`.

## Install

```bash
composer require divinetribe/x402-php
```

Or drop the folder in and `require_once 'x402-php/autoload.php';` — the bundled autoloader means Composer is optional, which matters inside WordPress.

Requires PHP 8.0+ with `json` and `curl`. Settling through Coinbase also needs the `sodium` extension.

## Charging for something

```php
use X402\{TollBooth, Facilitator, PaymentOption, Networks};

$booth = new TollBooth(new Facilitator());   // defaults to https://x402.org/facilitator (Base Sepolia)

$option = new PaymentOption(
    payTo:   '0xYourWallet',
    price:   '$0.01',
    network: Networks::BASE_SEPOLIA   // Networks::BASE needs the Coinbase facilitator below
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

The agent picks whichever it can pay. The facilitator you pass to `TollBooth` has to support every network you list.

### Settling on Base mainnet

```php
$booth = new TollBooth(Facilitator::coinbase('/path/to/cdp_api_key.json'));
```

`Facilitator::coinbase()` reads the key file Coinbase gives you (`id` and `privateKey`) and signs each request with a short-lived JWT. This is the one place you need an account: a Coinbase Developer Platform API key.

### Running your own facilitator

```php
new Facilitator('https://facilitator.example.com', 30, ['Authorization' => 'Bearer …']);
```

Headers can also be a callable `(method, path) => [...]` for per-request tokens. Nothing in this library assumes a particular provider.

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

Only `/blog` is paid; every other path is free. Set `X402_WALLET` to your own address (the default is the author's), and optionally `X402_PRICE`, `X402_NETWORK`, or `X402_CDP_KEY` (a Coinbase key file, which switches to Base mainnet).

Point any x402 client at it. Verified end-to-end against the official Python client and the live `x402.org` facilitator on Base Sepolia: the PHP booth's terms are byte-identical to the reference implementation's, and a real signed payment round-trips through verification.

## Tests

```bash
php tests/run.php
```

No test framework required. The tests run offline and do not call a facilitator.

## Known limits

- Only the `exact` scheme with default assets for Base and Base Sepolia. Other EVM chains need `asset` and `decimals` passed by hand; non-EVM chains are untested.
- The live end-to-end run described under "Try it" is not scripted in this repo. `tests/run.php` covers only the offline logic.
- Both WordPress plugins default to Base mainnet but build the default `new Facilitator()` (x402.org). Before switching them to `charge` or `live` mode on mainnet, change that line to `Facilitator::coinbase(...)`.
- The WordPress plugins expect the library copied to `wordpress/x402-php/` next to them. That copy is checked in and must be kept in sync with `src/` by hand.
- Settlement runs after delivery, so a failed settle means one page served unpaid. That is by design, see above.

## License

Apache-2.0, matching the x402 protocol itself.

---

Built by [Matt Macosko](https://ineedhemp.com) — running in production on ineedhemp.com, an independent e-commerce store charging AI agents since 2026.
