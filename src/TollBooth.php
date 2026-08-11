<?php

declare(strict_types=1);

namespace X402;

/**
 * The gate itself: quote a price to an agent, check what it sends back, and
 * settle after the content is served.
 *
 * Typical use inside any PHP app:
 *
 *     $booth = new TollBooth(new Facilitator());
 *     $option = new PaymentOption('0xYourWallet', '$0.01', Networks::BASE);
 *
 *     $result = $booth->collect($option, 'https://example.com/article');
 *     if (!$result->paid) {
 *         $result->send();   // emits 402 + terms, then you stop
 *         exit;
 *     }
 *     echo $article;
 *     $booth->settle($result);   // money moves after delivery
 */
final class TollBooth
{
    public const PAYMENT_HEADER = 'PAYMENT-SIGNATURE';
    public const REQUIRED_HEADER = 'PAYMENT-REQUIRED';
    public const RESPONSE_HEADER = 'PAYMENT-RESPONSE';
    public const VERSION = 2;

    private Facilitator $facilitator;

    public function __construct(?Facilitator $facilitator = null)
    {
        $this->facilitator = $facilitator ?? new Facilitator();
    }

    /**
     * Decide whether this request has paid.
     *
     * @param PaymentOption|PaymentOption[] $options    what you accept
     * @param string                        $resourceUrl canonical URL being sold
     * @param string|null                   $header     raw payment header; read from the request when null
     */
    public function collect(
        $options,
        string $resourceUrl,
        ?string $header = null,
        string $description = '',
        string $mimeType = ''
    ): TollResult {
        $optionList = is_array($options) ? $options : [$options];
        if ($optionList === []) {
            throw new X402Exception('At least one PaymentOption is required.');
        }

        $requirements = array_map(
            static fn (PaymentOption $o): PaymentRequirements => $o->toRequirements(),
            $optionList
        );

        $resource = [
            'url'         => $resourceUrl,
            'description' => $description,
            'mimeType'    => $mimeType,
        ];

        $header = $header ?? self::readPaymentHeader();

        if ($header === null || trim($header) === '') {
            return TollResult::unpaid($requirements, $resource, 'Payment required');
        }

        try {
            $payload = PaymentPayload::fromHeader($header);
        } catch (X402Exception $e) {
            return TollResult::unpaid($requirements, $resource, 'malformed_payment_header');
        }

        $offered = null;
        foreach ($requirements as $candidate) {
            if ($candidate->matches($payload->accepted)) {
                $offered = $candidate;
                break;
            }
        }

        if ($offered === null) {
            return TollResult::unpaid($requirements, $resource, 'payment_terms_mismatch');
        }

        $verdict = $this->facilitator->verify($payload, $offered);

        if (!$verdict['isValid']) {
            return TollResult::unpaid(
                $requirements,
                $resource,
                $verdict['invalidReason'] ?? 'payment_invalid'
            );
        }

        return TollResult::paid($payload, $offered, $resource, $verdict['payer'] ?? null);
    }

    /**
     * Move the money for a verified payment. Call after the content is sent.
     *
     * Settlement failing does not un-deliver the article; log it and move on,
     * which is why this returns the facilitator's answer rather than throwing.
     *
     * @return array{success: bool, transaction: ?string, network: ?string, errorReason: ?string, payer: ?string}
     */
    public function settle(TollResult $result, bool $sendHeader = true): array
    {
        if (!$result->paid || $result->payload === null || $result->requirements === null) {
            throw new X402Exception('Cannot settle a payment that was never verified.');
        }

        $receipt = $this->facilitator->settle($result->payload, $result->requirements);

        if ($sendHeader && !headers_sent()) {
            header(self::RESPONSE_HEADER . ': ' . base64_encode((string) json_encode($receipt)));
        }

        return $receipt;
    }

    /** Read the agent's payment header out of the current request. */
    public static function readPaymentHeader(): ?string
    {
        $keys = [
            'HTTP_PAYMENT_SIGNATURE',
            'HTTP_X_PAYMENT',           // v1 legacy
        ];

        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                return (string) $_SERVER[$key];
            }
        }

        return null;
    }

    /** The URL of the request being served, for the resource field. */
    public static function currentUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $path = $_SERVER['REQUEST_URI'] ?? '/';

        return "{$scheme}://{$host}{$path}";
    }
}
