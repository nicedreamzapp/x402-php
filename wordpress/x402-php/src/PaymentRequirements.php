<?php

declare(strict_types=1);

namespace X402;

/**
 * The wire-format payment terms sent to an agent inside a 402 response.
 *
 * Field names are camelCase on the wire; that is the protocol's shape, not a
 * style choice, so they are spelled out explicitly in toArray().
 */
final class PaymentRequirements implements \JsonSerializable
{
    public string $scheme;
    public string $network;
    public string $asset;
    public string $amount;
    public string $payTo;
    public int $maxTimeoutSeconds;
    /** @var array<string, mixed> */
    public array $extra;

    /** @param array<string, mixed> $extra */
    public function __construct(
        string $scheme,
        string $network,
        string $asset,
        string $amount,
        string $payTo,
        int $maxTimeoutSeconds = 300,
        array $extra = []
    ) {
        $this->scheme = $scheme;
        $this->network = $network;
        $this->asset = $asset;
        $this->amount = $amount;
        $this->payTo = $payTo;
        $this->maxTimeoutSeconds = $maxTimeoutSeconds;
        $this->extra = $extra;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['scheme', 'network', 'asset', 'amount', 'payTo'] as $required) {
            if (!isset($data[$required])) {
                throw new X402Exception("Payment requirements missing '{$required}'.");
            }
        }

        return new self(
            (string) $data['scheme'],
            (string) $data['network'],
            (string) $data['asset'],
            (string) $data['amount'],
            (string) $data['payTo'],
            (int) ($data['maxTimeoutSeconds'] ?? 300),
            (array) ($data['extra'] ?? [])
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'scheme'            => $this->scheme,
            'network'           => $this->network,
            'asset'             => $this->asset,
            'amount'            => $this->amount,
            'payTo'             => $this->payTo,
            'maxTimeoutSeconds' => $this->maxTimeoutSeconds,
        ];

        if ($this->extra !== []) {
            $out['extra'] = $this->extra;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * True when an agent's chosen terms are the ones we actually offered.
     *
     * The agent echoes back the requirements it is paying; trusting that echo
     * without comparing it to our own would let a client set its own price.
     */
    public function matches(PaymentRequirements $claimed): bool
    {
        return $this->scheme === $claimed->scheme
            && $this->network === $claimed->network
            && strcasecmp($this->asset, $claimed->asset) === 0
            && strcasecmp($this->payTo, $claimed->payTo) === 0
            && $this->amountAtLeast($claimed->amount);
    }

    /** Compare decimal strings without float rounding. */
    private function amountAtLeast(string $claimed): bool
    {
        $ours = ltrim($this->amount, '0');
        $theirs = ltrim($claimed, '0');
        $ours = $ours === '' ? '0' : $ours;
        $theirs = $theirs === '' ? '0' : $theirs;

        if (strlen($theirs) !== strlen($ours)) {
            return strlen($theirs) > strlen($ours);
        }

        return strcmp($theirs, $ours) >= 0;
    }
}
