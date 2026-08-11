<?php

declare(strict_types=1);

namespace X402;

/**
 * Known networks and their default settlement asset.
 *
 * Networks are named with CAIP-2 identifiers ("eip155:8453"). The short
 * aliases exist because humans write "base", not "eip155:8453".
 */
final class Networks
{
    public const BASE = 'eip155:8453';
    public const BASE_SEPOLIA = 'eip155:84532';

    private const ALIASES = [
        'base'         => self::BASE,
        'base-mainnet' => self::BASE,
        'base-sepolia' => self::BASE_SEPOLIA,
        'sepolia'      => self::BASE_SEPOLIA,
    ];

    /**
     * USDC contract, decimals, and EIP-712 domain per network.
     *
     * The domain name is the token contract's own name and differs by network —
     * mainnet USDC signs as "USD Coin" while the Sepolia deployment signs as
     * "USDC". Get it wrong and every signature verifies against the wrong
     * domain, which the facilitator reports only as "invalid_payload".
     */
    private const ASSETS = [
        self::BASE => [
            'address'  => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
            'decimals' => 6,
            'extra'    => ['name' => 'USD Coin', 'version' => '2'],
        ],
        self::BASE_SEPOLIA => [
            'address'  => '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
            'decimals' => 6,
            'extra'    => ['name' => 'USDC', 'version' => '2'],
        ],
    ];

    /** Resolve an alias or CAIP-2 id to a canonical CAIP-2 id. */
    public static function resolve(string $network): string
    {
        $key = strtolower(trim($network));

        return self::ALIASES[$key] ?? $network;
    }

    /**
     * Default asset descriptor for a network.
     *
     * @return array{address: string, decimals: int, extra: array<string, string>}
     */
    public static function asset(string $network): array
    {
        $id = self::resolve($network);

        if (!isset(self::ASSETS[$id])) {
            throw new X402Exception(
                "No default asset known for network '{$id}'. "
                . 'Pass an explicit asset and decimals to PaymentOption.'
            );
        }

        return self::ASSETS[$id];
    }

    public static function isKnown(string $network): bool
    {
        return isset(self::ASSETS[self::resolve($network)]);
    }
}
