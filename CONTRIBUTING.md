# Contributing

Thanks for taking a look. This library moves real money, so careful bug reports and small,
well-tested changes are the most useful kind of help.

## Reporting a problem

Open an [issue](https://github.com/nicedreamzapp/x402-php/issues/new/choose). The things that
make it quick to fix:

- your PHP version and whether `json`, `curl` and `sodium` are loaded
- whether you used the library directly or one of the WordPress plugins (and the WordPress,
  WooCommerce and plugin mode)
- the network (Base or Base Sepolia) and the facilitator you used
- the 402 response or error you got, and which x402 client sent the payment

Never paste a Coinbase key file, a private key or a wallet seed into an issue.

## Running it locally

```bash
php -S 127.0.0.1:4022 examples/toll_server.php
curl -i http://127.0.0.1:4022/blog     # 402, with terms
```

Set `X402_WALLET` to your own address (the default is the author's), and optionally `X402_PRICE`,
`X402_NETWORK` or `X402_CDP_KEY`.

## Tests

```bash
php tests/run.php
```

No test framework is needed, and the tests run offline without calling a facilitator. The
WordPress plugins use a copy of the library in `wordpress/x402-php/`, so if you change `src/`,
copy the change there too.

## Pull requests

Small and focused is easiest to review. For big changes, open an issue first so we can talk it
through. If you tested against a live facilitator, say which one and which network.

Everyone taking part is expected to follow the [code of conduct](CODE_OF_CONDUCT.md).
