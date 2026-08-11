<?php

/**
 * Dependency-free test runner: php tests/run.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use X402\Networks;
use X402\PaymentOption;
use X402\PaymentPayload;
use X402\PaymentRequirements;
use X402\Price;
use X402\X402Exception;

$passed = 0;
$failed = 0;

function check(string $name, callable $test): void
{
    global $passed, $failed;

    try {
        $test();
        ++$passed;
        echo "  ok   {$name}\n";
    } catch (\Throwable $e) {
        ++$failed;
        echo "  FAIL {$name}: {$e->getMessage()}\n";
    }
}

function assertSame($expected, $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        throw new \Exception(trim("{$what} expected {$e}, got {$a}"));
    }
}

function assertThrows(callable $fn, string $what = ''): void
{
    try {
        $fn();
    } catch (X402Exception $e) {
        return;
    }

    throw new \Exception("{$what} should have thrown");
}

echo "Price\n";
check('dollar string to atomic', function (): void {
    assertSame('1000', Price::toAtomic('$0.001', 6));
    assertSame('10000', Price::toAtomic('0.01', 6));
    assertSame('1000000', Price::toAtomic('$1', 6));
    assertSame('12340000', Price::toAtomic('$12.34', 6));
});
check('formatting quirks are tolerated', function (): void {
    assertSame('1500000000', Price::toAtomic('$1,500', 6));
    assertSame('0', Price::toAtomic('$0', 6));
    assertSame('1000000', Price::toAtomic('$1.000000', 6));
});
check('integers pass through as atomic', function (): void {
    assertSame('1000', Price::toAtomic(1000, 6));
});
check('sub-precision prices are refused, not rounded', function (): void {
    assertThrows(static fn () => Price::toAtomic('$0.0000001', 6), 'too many decimals');
    assertThrows(static fn () => Price::toAtomic('free', 6), 'garbage price');
    assertThrows(static fn () => Price::toAtomic(-5, 6), 'negative price');
});
check('atomic back to human', function (): void {
    assertSame('0.001', Price::toDecimal('1000', 6));
    assertSame('12.34', Price::toDecimal('12340000', 6));
    assertSame('0', Price::toDecimal('0', 6));
});

echo "Networks\n";
check('aliases resolve to CAIP-2', function (): void {
    assertSame('eip155:8453', Networks::resolve('base'));
    assertSame('eip155:84532', Networks::resolve('base-sepolia'));
    assertSame('eip155:84532', Networks::resolve('eip155:84532'));
});
check('unknown network has no default asset', function (): void {
    assertThrows(static fn () => Networks::asset('eip155:999999'));
});

echo "PaymentOption\n";
check('builds the wire requirements', function (): void {
    $option = new PaymentOption('0x28175685f617Ad6FC90e36cf96048788844C9c32', '$0.001', 'base-sepolia');
    $req = $option->toRequirements()->toArray();

    assertSame('exact', $req['scheme']);
    assertSame('eip155:84532', $req['network']);
    assertSame('0x036CbD53842c5426634e7929541eC2318f3dCF7e', $req['asset']);
    assertSame('1000', $req['amount']);
    assertSame(300, $req['maxTimeoutSeconds']);
    assertSame(['name' => 'USDC', 'version' => '2'], $req['extra']);
});
check('a bad wallet address is caught early', function (): void {
    assertThrows(static fn () => new PaymentOption('not-an-address', '$1'));
});

echo "PaymentRequirements\n";
$mine = (new PaymentOption('0x28175685f617Ad6FC90e36cf96048788844C9c32', '$0.001', 'base-sepolia'))->toRequirements();

check('matching terms are accepted', function () use ($mine): void {
    assertSame(true, $mine->matches(PaymentRequirements::fromArray($mine->toArray())));
});
check('overpayment is accepted', function () use ($mine): void {
    $more = $mine->toArray();
    $more['amount'] = '2000';
    assertSame(true, $mine->matches(PaymentRequirements::fromArray($more)));
});
check('an agent cannot set its own price', function () use ($mine): void {
    $less = $mine->toArray();
    $less['amount'] = '1';
    assertSame(false, $mine->matches(PaymentRequirements::fromArray($less)));
});
check('an agent cannot redirect the money', function () use ($mine): void {
    $elsewhere = $mine->toArray();
    $elsewhere['payTo'] = '0x0000000000000000000000000000000000000bad';
    assertSame(false, $mine->matches(PaymentRequirements::fromArray($elsewhere)));
});
check('an agent cannot swap the asset or chain', function () use ($mine): void {
    $fake = $mine->toArray();
    $fake['asset'] = '0x0000000000000000000000000000000000000001';
    assertSame(false, $mine->matches(PaymentRequirements::fromArray($fake)));

    $wrongChain = $mine->toArray();
    $wrongChain['network'] = 'eip155:1';
    assertSame(false, $mine->matches(PaymentRequirements::fromArray($wrongChain)));
});
check('address case differences still match', function () use ($mine): void {
    $lower = $mine->toArray();
    $lower['payTo'] = strtolower($lower['payTo']);
    $lower['asset'] = strtolower($lower['asset']);
    assertSame(true, $mine->matches(PaymentRequirements::fromArray($lower)));
});

echo "PaymentPayload\n";
check('round-trips through the header encoding', function () use ($mine): void {
    $payload = new PaymentPayload(2, ['signature' => '0xdead'], $mine);
    $header = base64_encode((string) json_encode($payload->toArray()));
    $decoded = PaymentPayload::fromHeader($header);

    assertSame(2, $decoded->x402Version);
    assertSame('0xdead', $decoded->payload['signature']);
    assertSame($mine->amount, $decoded->accepted->amount);
});
check('junk headers are rejected, not trusted', function (): void {
    assertThrows(static fn () => PaymentPayload::fromHeader('!!!not-base64!!!'));
    assertThrows(static fn () => PaymentPayload::fromHeader(base64_encode('{"x402Version":2}')));
});

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
