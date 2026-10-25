# Security policy

## Reporting a vulnerability

Please do not open a public issue for security problems.

Report them privately through GitHub: go to the **Security** tab of this repo and click **Report a vulnerability** ([direct link](https://github.com/nicedreamzapp/x402-php/security/advisories/new)). Only the maintainer sees the report.

Helpful details: what you found, how to reproduce it, which commit, your PHP version, whether it affects the library or a WordPress plugin, and the network and facilitator involved. Please use Base Sepolia and test keys in any example, never real key files.

I aim to reply within 3 days and to ship a fix or a clear plan within 30 days, depending on severity. You will be credited in the advisory unless you ask not to be.

## Supported versions

Fixes go into the latest version on `main`. Please update before reporting if you are on an older version.

## What matters most here

The parts where a mistake could hurt someone:

- payment verification in `src/TollBooth.php`, which checks that the terms an agent echoes back (scheme, network, asset, `payTo` and amount) match what was offered
- reading the `PAYMENT-SIGNATURE` and `X-PAYMENT` headers (`src/TollBooth.php`) and decoding them in `src/PaymentPayload.php`, since they come straight from the client
- the integer money math in `src/Price.php`
- Coinbase API key loading and JWT signing in `src/Auth/CdpAuth.php`
- the WordPress plugins in `wordpress/`, including the paid `/order` endpoint and age attestation in `divine-tribe-agent-commerce.php`
