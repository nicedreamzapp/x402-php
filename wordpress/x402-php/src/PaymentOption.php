<?php

declare(strict_types=1);

namespace X402;

/**
 * What you charge, on which network, paid to which wallet.
 *
 * One option becomes one entry in the 402 response's "accepts" list.
 */
final class PaymentOption
{
    public string $scheme;
    public string $payTo;
    /** @var string|int */
    public $price;
    public string $network;
    public int $maxTimeoutSeconds;
    public ?string $asset;
    public ?int $decimals;
    /** @var array<string, mixed> */
    public array $extra;

    /**
     * @param string     $payTo   wallet that receives the money
     * @param string|int $price   "$0.001" or an atomic integer
     * @param array<string, mixed> $extra
     */
    public function __construct(
        string $payTo,
        $price,
        string $network = Networks::BASE,
        string $scheme = 'exact',
        int $maxTimeoutSeconds = 300,
        ?string $asset = null,
        ?int $decimals = null,
        array $extra = []
    ) {
        if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $payTo)) {
            throw new X402Exception("payTo must be a 0x address, got '{$payTo}'");
        }

        $this->scheme = $scheme;
        $this->payTo = $payTo;
        $this->price = $price;
        $this->network = Networks::resolve($network);
        $this->maxTimeoutSeconds = $maxTimeoutSeconds;
        $this->asset = $asset;
        $this->decimals = $decimals;
        $this->extra = $extra;
    }

    /** Build the wire-format requirements this option represents. */
    public function toRequirements(): PaymentRequirements
    {
        if ($this->asset !== null && $this->decimals !== null) {
            $asset = $this->asset;
            $decimals = $this->decimals;
            $extra = $this->extra;
        } else {
            $known = Networks::asset($this->network);
            $asset = $this->asset ?? $known['address'];
            $decimals = $this->decimals ?? $known['decimals'];
            $extra = $this->extra === [] ? $known['extra'] : array_merge($known['extra'], $this->extra);
        }

        return new PaymentRequirements(
            $this->scheme,
            $this->network,
            $asset,
            Price::toAtomic($this->price, $decimals),
            $this->payTo,
            $this->maxTimeoutSeconds,
            $extra
        );
    }
}
