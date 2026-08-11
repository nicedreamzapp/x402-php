<?php

declare(strict_types=1);

namespace X402;

/**
 * What the agent sends back: a signed authorization plus the terms it accepted.
 *
 * Arrives base64-encoded in the PAYMENT-SIGNATURE request header.
 */
final class PaymentPayload
{
    public int $x402Version;
    /** @var array<string, mixed> scheme-specific signature data */
    public array $payload;
    public PaymentRequirements $accepted;
    /** @var array<string, mixed>|null */
    public ?array $resource;
    /** @var array<string, mixed>|null */
    public ?array $extensions;

    /**
     * @param array<string, mixed>      $payload
     * @param array<string, mixed>|null $resource
     * @param array<string, mixed>|null $extensions
     */
    public function __construct(
        int $x402Version,
        array $payload,
        PaymentRequirements $accepted,
        ?array $resource = null,
        ?array $extensions = null
    ) {
        $this->x402Version = $x402Version;
        $this->payload = $payload;
        $this->accepted = $accepted;
        $this->resource = $resource;
        $this->extensions = $extensions;
    }

    /** Decode the PAYMENT-SIGNATURE header an agent sent. */
    public static function fromHeader(string $header): self
    {
        $json = base64_decode(trim($header), true);

        if ($json === false) {
            throw new X402Exception('Payment header is not valid base64.');
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new X402Exception('Payment header did not contain a JSON object.');
        }

        return self::fromArray($data);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (!isset($data['accepted']) || !is_array($data['accepted'])) {
            throw new X402Exception("Payment payload missing 'accepted' terms.");
        }

        return new self(
            (int) ($data['x402Version'] ?? 2),
            (array) ($data['payload'] ?? []),
            PaymentRequirements::fromArray($data['accepted']),
            isset($data['resource']) ? (array) $data['resource'] : null,
            isset($data['extensions']) ? (array) $data['extensions'] : null
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'x402Version' => $this->x402Version,
            'payload'     => $this->payload,
            'accepted'    => $this->accepted->toArray(),
        ];

        if ($this->resource !== null) {
            $out['resource'] = $this->resource;
        }

        if ($this->extensions !== null) {
            $out['extensions'] = $this->extensions;
        }

        return $out;
    }
}
