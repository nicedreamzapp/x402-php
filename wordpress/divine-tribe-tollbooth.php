<?php
/**
 * Plugin Name: Divine Tribe Toll Booth
 * Description: Charges AI agents to read blog content over the x402 protocol. Humans, search engines, and product pages are never affected.
 * Version: 0.1.0
 * Author: Matt Macosko
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Modes:
 *   monitor — log which AI agents visit, charge nobody (safe default)
 *   charge  — answer AI agents with 402 until they pay
 *
 * Flip by defining DT_TOLLBOOTH_MODE in wp-config.php.
 */
if (!defined('DT_TOLLBOOTH_MODE')) {
    define('DT_TOLLBOOTH_MODE', 'monitor');
}

if (!defined('DT_TOLLBOOTH_WALLET')) {
    define('DT_TOLLBOOTH_WALLET', '');
}

if (!defined('DT_TOLLBOOTH_PRICE')) {
    define('DT_TOLLBOOTH_PRICE', '$0.01');
}

if (!defined('DT_TOLLBOOTH_NETWORK')) {
    define('DT_TOLLBOOTH_NETWORK', 'eip155:8453'); // Base mainnet
}

/**
 * Agents that pay or could pay. Matching is case-insensitive substring.
 *
 * Search and social crawlers are deliberately absent — they stay free forever
 * so nothing here can cost the store a search ranking.
 */
function dt_tollbooth_ai_agents(): array
{
    return [
        'gptbot', 'oai-searchbot', 'chatgpt-user',
        'claudebot', 'claude-user', 'claude-searchbot', 'anthropic-ai',
        'perplexitybot', 'perplexity-user',
        'ccbot', 'bytespider', 'amazonbot', 'meta-externalagent', 'meta-externalfetcher',
        'cohere-ai', 'cohere-training-data-crawler', 'diffbot', 'imagesiftbot',
        'omgilibot', 'omgili', 'youbot', 'timpibot', 'duckassistbot',
        'mistralai-user', 'applebot-extended', 'google-extended', 'petalbot',
        'ai2bot', 'firecrawl', 'scrapy', 'x402',
    ];
}

/** Crawlers that must never see a toll, no matter what. */
function dt_tollbooth_always_free_agents(): array
{
    return [
        'googlebot', 'google-inspectiontool', 'adsbot-google', 'mediapartners-google',
        'bingbot', 'bingpreview', 'msnbot', 'duckduckbot', 'slurp', 'baiduspider',
        'yandexbot', 'sogou', 'exabot', 'facebookexternalhit', 'twitterbot',
        'linkedinbot', 'pinterest', 'redditbot', 'discordbot', 'slackbot',
        'whatsapp', 'telegrambot', 'applebot', 'ahrefsbot', 'semrushbot',
        'uptimerobot', 'pingdom', 'wordpress', 'jetpack', 'wp-rocket', 'litespeed',
    ];
}

function dt_tollbooth_user_agent(): string
{
    return strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function dt_tollbooth_matches(string $ua, array $needles): ?string
{
    foreach ($needles as $needle) {
        if ($ua !== '' && strpos($ua, $needle) !== false) {
            return $needle;
        }
    }

    return null;
}

/** True when this request is an AI agent we would consider charging. */
function dt_tollbooth_is_ai_agent(): ?string
{
    $ua = dt_tollbooth_user_agent();

    if (dt_tollbooth_matches($ua, dt_tollbooth_always_free_agents()) !== null) {
        return null;
    }

    // An agent carrying a payment identifies itself more reliably than any UA string.
    if (!empty($_SERVER['HTTP_PAYMENT_SIGNATURE']) || !empty($_SERVER['HTTP_X_PAYMENT'])) {
        return 'x402-payer';
    }

    return dt_tollbooth_matches($ua, dt_tollbooth_ai_agents());
}

/**
 * Only long-form content is ever tolled.
 *
 * Product, cart, checkout, and account pages stay free so a shopping agent can
 * always reach the thing it wants to buy — the store makes more from the sale
 * than from the toll.
 */
function dt_tollbooth_is_tollable(): bool
{
    if (is_admin() || wp_doing_ajax() || wp_doing_cron() || is_user_logged_in()) {
        return false;
    }

    if (defined('REST_REQUEST') && REST_REQUEST) {
        return false;
    }

    $path = strtolower((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

    $freePaths = [
        '/robots.txt', '/sitemap', '/security.txt', '/.well-known/',
        '/favicon', '/wp-json/', '/wp-admin', '/wp-login', '/xmlrpc.php',
        '/cart', '/checkout', '/my-account', '/shop', '/product',
    ];

    foreach ($freePaths as $free) {
        if (strpos($path, $free) === 0) {
            return false;
        }
    }

    if (function_exists('is_woocommerce') && (is_woocommerce() || is_cart() || is_checkout() || is_account_page())) {
        return false;
    }

    return is_singular('post');
}

function dt_tollbooth_log(string $event, array $data = []): void
{
    $uploads = wp_upload_dir();
    $dir = trailingslashit($uploads['basedir']) . 'tollbooth';

    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }

    $line = wp_json_encode(array_merge([
        'ts'    => gmdate('c'),
        'event' => $event,
        'ua'    => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
        'ip'    => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'path'  => (string) ($_SERVER['REQUEST_URI'] ?? ''),
        'mode'  => DT_TOLLBOOTH_MODE,
    ], $data));

    @file_put_contents($dir . '/agents.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
}

/** Running tallies, so the dashboard does not have to parse the log. */
function dt_tollbooth_bump(string $key): void
{
    $counts = get_option('dt_tollbooth_counts', []);
    $counts[$key] = (int) ($counts[$key] ?? 0) + 1;
    $counts['updated'] = gmdate('c');
    update_option('dt_tollbooth_counts', $counts, false);
}

function dt_tollbooth_run(): void
{
    $agent = dt_tollbooth_is_ai_agent();

    if ($agent === null || !dt_tollbooth_is_tollable()) {
        return;
    }

    dt_tollbooth_bump('agent_visits');

    if (DT_TOLLBOOTH_MODE !== 'charge' || DT_TOLLBOOTH_WALLET === '') {
        dt_tollbooth_log('seen', ['agent' => $agent]);

        return;
    }

    $lib = __DIR__ . '/x402-php/autoload.php';

    if (!is_file($lib)) {
        dt_tollbooth_log('error', ['agent' => $agent, 'reason' => 'x402 library missing']);

        return;
    }

    require_once $lib;

    try {
        $booth = new X402\TollBooth(new X402\Facilitator());
        $option = new X402\PaymentOption(
            DT_TOLLBOOTH_WALLET,
            DT_TOLLBOOTH_PRICE,
            DT_TOLLBOOTH_NETWORK
        );

        $result = $booth->collect(
            $option,
            X402\TollBooth::currentUrl(),
            null,
            (string) get_the_title()
        );
    } catch (Throwable $e) {
        // A toll booth must never take the store down. Serve the page free.
        dt_tollbooth_log('error', ['agent' => $agent, 'reason' => $e->getMessage()]);

        return;
    }

    if (!$result->paid) {
        dt_tollbooth_bump('challenged');
        dt_tollbooth_log('challenged', ['agent' => $agent, 'reason' => $result->error]);
        $result->send();
        exit;
    }

    dt_tollbooth_bump('paid');
    dt_tollbooth_log('paid', ['agent' => $agent, 'payer' => $result->payer]);

    // Settle once the page has actually gone out the door.
    add_action('shutdown', static function () use ($booth, $result): void {
        try {
            $receipt = $booth->settle($result, false);
            dt_tollbooth_bump($receipt['success'] ? 'settled' : 'settle_failed');
            dt_tollbooth_log('settled', [
                'success'     => $receipt['success'],
                'transaction' => $receipt['transaction'],
                'reason'      => $receipt['errorReason'],
            ]);
        } catch (Throwable $e) {
            dt_tollbooth_log('settle_error', ['reason' => $e->getMessage()]);
        }
    }, 0);
}

add_action('template_redirect', 'dt_tollbooth_run', 1);
