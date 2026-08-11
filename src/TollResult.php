<?php

declare(strict_types=1);

namespace X402;

/**
 * The outcome of a toll check: either the agent paid, or here are the terms.
 */
final class TollResult
{
    public bool $paid;
    /** @var PaymentRequirements[] */
    public array $accepts;
    /** @var array<string, string> */
    public array $resource;
    public ?string $error;
    public ?PaymentPayload $payload;
    public ?PaymentRequirements $requirements;
    public ?string $payer;

    /**
     * @param PaymentRequirements[] $accepts
     * @param array<string, string> $resource
     */
    private function __construct(
        bool $paid,
        array $accepts,
        array $resource,
        ?string $error = null,
        ?PaymentPayload $payload = null,
        ?PaymentRequirements $requirements = null,
        ?string $payer = null
    ) {
        $this->paid = $paid;
        $this->accepts = $accepts;
        $this->resource = $resource;
        $this->error = $error;
        $this->payload = $payload;
        $this->requirements = $requirements;
        $this->payer = $payer;
    }

    /**
     * @param PaymentRequirements[] $accepts
     * @param array<string, string> $resource
     */
    public static function unpaid(array $accepts, array $resource, string $error): self
    {
        return new self(false, $accepts, $resource, $error);
    }

    /** @param array<string, string> $resource */
    public static function paid(
        PaymentPayload $payload,
        PaymentRequirements $requirements,
        array $resource,
        ?string $payer
    ): self {
        return new self(true, [$requirements], $resource, null, $payload, $requirements, $payer);
    }

    /** The 402 body an agent parses to learn the price. */
    public function challenge(): string
    {
        return (string) json_encode([
            'x402Version' => TollBooth::VERSION,
            'error'       => $this->error ?? 'Payment required',
            'resource'    => $this->resource,
            'accepts'     => array_map(
                static fn (PaymentRequirements $r): array => $r->toArray(),
                $this->accepts
            ),
        ], JSON_UNESCAPED_SLASHES);
    }

    /** Emit the 402 response: status, headers, and body. */
    public function send(): void
    {
        if ($this->paid) {
            throw new X402Exception('send() is for unpaid requests; this one paid.');
        }

        $body = $this->challenge();

        if (!headers_sent()) {
            http_response_code(402);
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            header(TollBooth::REQUIRED_HEADER . ': ' . base64_encode($body));
            header('Access-Control-Expose-Headers: ' . TollBooth::REQUIRED_HEADER . ', ' . TollBooth::RESPONSE_HEADER);
        }

        echo $body;
    }
}
