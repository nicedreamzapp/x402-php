<?php

/**
 * A toll booth in 20 lines. Run it with PHP's built-in server:
 *
 *     php -S 127.0.0.1:4022 examples/toll_server.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use X402\Facilitator;
use X402\Networks;
use X402\PaymentOption;
use X402\TollBooth;

$wallet = getenv('X402_WALLET') ?: '0x28175685f617Ad6FC90e36cf96048788844C9c32';
$network = getenv('X402_NETWORK') ?: Networks::BASE_SEPOLIA;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Product pages stay free so shopping agents are never turned away.
if ($path !== '/blog') {
    header('Content-Type: application/json');
    echo json_encode(['article' => 'product page', 'toll' => 'none']);

    return;
}

$booth = new TollBooth(new Facilitator());
$option = new PaymentOption($wallet, '$0.001', $network);

$result = $booth->collect($option, TollBooth::currentUrl(), null, 'Divine Tribe technical article');

if (!$result->paid) {
    $result->send();

    return;
}

header('Content-Type: application/json');
echo json_encode(['article' => 'wire science: why set it and forget it', 'toll' => 'paid']);

$receipt = $booth->settle($result);
error_log('x402 settled: ' . json_encode($receipt));
