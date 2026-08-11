<?php

declare(strict_types=1);

namespace X402\Auth;

use X402\X402Exception;

/**
 * Signs requests to Coinbase's hosted facilitator.
 *
 * Coinbase authenticates each call with a short-lived EdDSA JWT rather than a
 * static token, so this is a header *factory* — Facilitator calls it per
 * request and gets a fresh, unexpired signature every time.
 *
 * The key never leaves this process, and nothing here can move funds: it only
 * authorises signature checks and settlement submissions.
 */
final class CdpAuth
{
    private const HOST = 'api.cdp.coinbase.com';
    private const FACILITATOR_URL = 'https://api.cdp.coinbase.com/platform/v2/x402';

    private string $keyId;
    private string $seed;
    private string $publicKey;
    private int $lifetimeSeconds;

    /**
     * @param string $keyId      the API key's UUID
     * @param string $privateKey base64 Ed25519 key material from the downloaded key file
     */
    public function __construct(string $keyId, string $privateKey, int $lifetimeSeconds = 120)
    {
        if (!extension_loaded('sodium')) {
            throw new X402Exception('The sodium extension is required to sign Coinbase requests.');
        }

        $decoded = base64_decode(strtr(trim($privateKey), '-_', '+/'), false);

        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new X402Exception(
                'Expected a 64-byte Ed25519 key from the Coinbase key file, got '
                . ($decoded === false ? 'unparseable base64' : strlen($decoded) . ' bytes')
            );
        }

        $this->keyId = $keyId;
        $this->seed = $decoded;
        $this->publicKey = substr($decoded, 32);
        $this->lifetimeSeconds = $lifetimeSeconds;
    }

    /** Build from the JSON file Coinbase hands you when the key is created. */
    public static function fromKeyFile(string $path): self
    {
        if (!is_file($path)) {
            throw new X402Exception("Coinbase key file not found at {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!is_array($data) || empty($data['id']) || empty($data['privateKey'])) {
            throw new X402Exception("Key file at {$path} is missing 'id' or 'privateKey'.");
        }

        return new self((string) $data['id'], (string) $data['privateKey']);
    }

    public static function facilitatorUrl(): string
    {
        return self::FACILITATOR_URL;
    }

    /**
     * Header factory for Facilitator.
     *
     * @return callable(string, string): array<string, string>
     */
    public function headerFactory(): callable
    {
        return function (string $method, string $path): array {
            return ['Authorization' => 'Bearer ' . $this->token($method, $path)];
        };
    }

    /** A JWT scoped to exactly one method and path, valid for a couple of minutes. */
    public function token(string $method, string $path): string
    {
        $now = time();
        $uri = strtoupper($method) . ' ' . self::HOST . $path;

        $header = [
            'alg'   => 'EdDSA',
            'kid'   => $this->keyId,
            'typ'   => 'JWT',
            'nonce' => bin2hex(random_bytes(16)),
        ];

        $claims = [
            'sub' => $this->keyId,
            'iss' => 'cdp',
            'aud' => ['cdp_service'],
            'nbf' => $now,
            'exp' => $now + $this->lifetimeSeconds,
            'uris' => [$uri],
        ];

        $signingInput = self::b64url((string) json_encode($header))
            . '.' . self::b64url((string) json_encode($claims));

        $signature = sodium_crypto_sign_detached($signingInput, $this->seed);

        return $signingInput . '.' . self::b64url($signature);
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
