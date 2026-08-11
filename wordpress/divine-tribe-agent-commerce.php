<?php
/**
 * Plugin Name: Divine Tribe Agent Commerce
 * Description: Lets an AI agent discover products and buy them with x402 stablecoin payment, including the age attestation an age-restricted store requires.
 * Version: 0.1.0
 * Author: Matt Macosko
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Modes:
 *   catalog — agents can read the catalog; ordering is refused (safe default)
 *   live    — agents can also place real orders
 */
if (!defined('DT_AGENT_COMMERCE_MODE')) {
    define('DT_AGENT_COMMERCE_MODE', 'catalog');
}

if (!defined('DT_AGENT_COMMERCE_WALLET')) {
    define('DT_AGENT_COMMERCE_WALLET', defined('DT_TOLLBOOTH_WALLET') ? DT_TOLLBOOTH_WALLET : '');
}

if (!defined('DT_AGENT_COMMERCE_NETWORK')) {
    define('DT_AGENT_COMMERCE_NETWORK', 'eip155:8453');
}

/** Minimum age required to buy anything here. */
if (!defined('DT_AGENT_COMMERCE_MIN_AGE')) {
    define('DT_AGENT_COMMERCE_MIN_AGE', 21);
}

add_action('rest_api_init', static function (): void {
    register_rest_route('agent/v1', '/catalog', [
        'methods'             => 'GET',
        'callback'            => 'dt_agent_catalog',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('agent/v1', '/order', [
        'methods'             => 'POST',
        'callback'            => 'dt_agent_order',
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Everything an agent needs to decide what to buy and how to pay for it.
 */
function dt_agent_catalog(WP_REST_Request $request): WP_REST_Response
{
    if (!function_exists('wc_get_products')) {
        return new WP_REST_Response(['error' => 'store_unavailable'], 503);
    }

    $products = wc_get_products([
        'status'  => 'publish',
        'limit'   => min(100, (int) ($request->get_param('limit') ?: 50)),
        'orderby' => 'popularity',
    ]);

    $items = [];

    foreach ($products as $product) {
        if (!$product->is_purchasable() || !$product->is_in_stock()) {
            continue;
        }

        $items[] = [
            'id'          => $product->get_id(),
            'sku'         => $product->get_sku(),
            'name'        => $product->get_name(),
            'description' => wp_strip_all_tags((string) $product->get_short_description()),
            'price_usd'   => $product->get_price(),
            'url'         => get_permalink($product->get_id()),
            'image'       => wp_get_attachment_url((int) $product->get_image_id()) ?: null,
            'in_stock'    => true,
            'variations'  => $product->is_type('variable')
                ? array_map('strval', $product->get_children())
                : [],
        ];
    }

    return new WP_REST_Response([
        'store'    => get_bloginfo('name'),
        'currency' => get_woocommerce_currency(),
        'payment'  => [
            'protocol' => 'x402',
            'version'  => 2,
            'network'  => DT_AGENT_COMMERCE_NETWORK,
            'asset'    => 'USDC',
            'endpoint' => rest_url('agent/v1/order'),
            'mode'     => DT_AGENT_COMMERCE_MODE,
        ],
        'restrictions' => [
            'age_verification_required' => true,
            'minimum_age'               => DT_AGENT_COMMERCE_MIN_AGE,
            'attestation_field'         => 'age_attestation',
            'notice'                    => sprintf(
                'Vaporizer products are age-restricted. An agent must attest that its human principal is %d or older, and that attestation is recorded with the order.',
                DT_AGENT_COMMERCE_MIN_AGE
            ),
        ],
        'shipping' => [
            'required_fields' => ['name', 'address_1', 'city', 'state', 'postcode', 'country'],
            'countries'       => ['US'],
        ],
        'count'    => count($items),
        'products' => $items,
    ], 200);
}

/**
 * The age gate.
 *
 * An agent cannot show a driver's licence, so what it can do is carry a signed
 * statement from the human it acts for. We record who attested, when, and to
 * what — the same evidence trail a checkout checkbox produces, kept with the
 * order rather than thrown away.
 *
 * @param array<string, mixed> $attestation
 *
 * @return array{ok: bool, reason?: string, record?: array<string, mixed>}
 */
function dt_agent_check_age($attestation): array
{
    if (!is_array($attestation)) {
        return ['ok' => false, 'reason' => 'age_attestation_missing'];
    }

    $confirmed = $attestation['over_minimum_age'] ?? null;

    if ($confirmed !== true) {
        return ['ok' => false, 'reason' => 'age_not_attested'];
    }

    $principal = trim((string) ($attestation['principal'] ?? ''));

    if ($principal === '') {
        return ['ok' => false, 'reason' => 'age_attestation_needs_principal'];
    }

    $claimed = (int) ($attestation['minimum_age'] ?? 0);

    if ($claimed < DT_AGENT_COMMERCE_MIN_AGE) {
        return ['ok' => false, 'reason' => 'age_below_minimum'];
    }

    return [
        'ok'     => true,
        'record' => [
            'principal'    => substr($principal, 0, 200),
            'minimum_age'  => $claimed,
            'agent'        => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
            'attested_at'  => gmdate('c'),
            'attested_ip'  => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'method'       => 'x402-agent-attestation-v1',
        ],
    ];
}

/**
 * Buy something. Quotes a price with 402, then creates the order once paid.
 */
function dt_agent_order(WP_REST_Request $request)
{
    if (!function_exists('wc_create_order')) {
        return new WP_REST_Response(['error' => 'store_unavailable'], 503);
    }

    $body = $request->get_json_params() ?: [];
    $items = $body['items'] ?? [];
    $shipping = $body['shipping'] ?? [];

    if (!is_array($items) || $items === []) {
        return new WP_REST_Response(['error' => 'no_items'], 400);
    }

    $age = dt_agent_check_age($body['age_attestation'] ?? null);

    if (!$age['ok']) {
        return new WP_REST_Response([
            'error'        => $age['reason'],
            'minimum_age'  => DT_AGENT_COMMERCE_MIN_AGE,
            'how_to_fix'   => 'Include age_attestation: {over_minimum_age: true, minimum_age: 21, principal: "<who you act for>"}',
        ], 403);
    }

    // Price the basket from our own catalog. An agent never sets the price.
    $total = 0.0;
    $lines = [];

    foreach ($items as $item) {
        $productId = (int) ($item['id'] ?? 0);
        $qty = max(1, (int) ($item['quantity'] ?? 1));
        $product = $productId ? wc_get_product($productId) : null;

        if (!$product || !$product->is_purchasable() || !$product->is_in_stock()) {
            return new WP_REST_Response(['error' => 'unavailable_item', 'id' => $productId], 400);
        }

        $total += (float) $product->get_price() * $qty;
        $lines[] = ['product' => $product, 'quantity' => $qty];
    }

    foreach (['name', 'address_1', 'city', 'state', 'postcode'] as $field) {
        if (empty($shipping[$field])) {
            return new WP_REST_Response(['error' => 'shipping_incomplete', 'missing' => $field], 400);
        }
    }

    if (DT_AGENT_COMMERCE_MODE !== 'live' || DT_AGENT_COMMERCE_WALLET === '') {
        return new WP_REST_Response([
            'error'   => 'ordering_disabled',
            'message' => 'Catalog is readable; agent ordering is not switched on yet.',
            'quote'   => ['total_usd' => number_format($total, 2, '.', '')],
        ], 503);
    }

    $lib = __DIR__ . '/x402-php/autoload.php';

    if (!is_file($lib)) {
        return new WP_REST_Response(['error' => 'x402_unavailable'], 500);
    }

    require_once $lib;

    $booth = new X402\TollBooth(new X402\Facilitator());
    $option = new X402\PaymentOption(
        DT_AGENT_COMMERCE_WALLET,
        '$' . number_format($total, 2, '.', ''),
        DT_AGENT_COMMERCE_NETWORK
    );

    $result = $booth->collect($option, rest_url('agent/v1/order'), null, 'Divine Tribe order');

    if (!$result->paid) {
        $result->send();
        exit;
    }

    $receipt = $booth->settle($result, false);

    if (!$receipt['success']) {
        return new WP_REST_Response([
            'error'  => 'settlement_failed',
            'reason' => $receipt['errorReason'],
        ], 402);
    }

    $order = wc_create_order();

    foreach ($lines as $line) {
        $order->add_product($line['product'], $line['quantity']);
    }

    $order->set_address([
        'first_name' => (string) $shipping['name'],
        'address_1'  => (string) $shipping['address_1'],
        'address_2'  => (string) ($shipping['address_2'] ?? ''),
        'city'       => (string) $shipping['city'],
        'state'      => (string) $shipping['state'],
        'postcode'   => (string) $shipping['postcode'],
        'country'    => (string) ($shipping['country'] ?? 'US'),
    ], 'shipping');

    if (!empty($body['email'])) {
        $order->set_billing_email(sanitize_email((string) $body['email']));
    }

    $order->update_meta_data('_dt_agent_purchase', 'yes');
    $order->update_meta_data('_dt_agent_age_attestation', wp_json_encode($age['record']));
    $order->update_meta_data('_dt_x402_transaction', (string) $receipt['transaction']);
    $order->update_meta_data('_dt_x402_payer', (string) ($receipt['payer'] ?? $result->payer));
    $order->update_meta_data('_dt_x402_network', DT_AGENT_COMMERCE_NETWORK);
    $order->update_meta_data('_no_coupon', 'yes');

    $order->calculate_totals();
    $order->set_status('processing', 'Paid by AI agent over x402.');
    $order->save();

    $order->add_order_note(sprintf(
        "Autonomous agent purchase.\nAgent: %s\nPrincipal: %s (attested %d+)\nx402 tx: %s",
        $age['record']['agent'],
        $age['record']['principal'],
        $age['record']['minimum_age'],
        (string) $receipt['transaction']
    ));

    return new WP_REST_Response([
        'ok'          => true,
        'order_id'    => $order->get_id(),
        'total_usd'   => number_format($total, 2, '.', ''),
        'transaction' => $receipt['transaction'],
        'status'      => 'processing',
        'message'     => 'Order placed. It ships like any other order.',
    ], 201);
}

/**
 * Point agents at the catalog from robots.txt, so discovery does not depend on
 * anyone indexing us first.
 */
add_filter('robots_txt', static function (string $output): string {
    return $output . "\n# AI agents: machine-readable catalog and x402 payment endpoint\n"
        . 'X402-Catalog: ' . rest_url('agent/v1/catalog') . "\n";
}, 10, 1);
