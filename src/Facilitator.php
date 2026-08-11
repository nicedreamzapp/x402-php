<?php

declare(strict_types=1);

namespace X402;

/**
 * HTTP client for an x402 facilitator — the service that checks an agent's
 * signature and pushes the settlement on-chain.
 *
 * The facilitator never holds your money: settlement moves funds straight from
 * the agent's wallet to yours. No private key ever reaches this library.
 */
final class Facilitator
{
    public const DEFAULT_URL = 'https://x402.org/facilitator';

    private string $url;
    private int $timeout;
    /** @var array<string, string> */
    private array $headers;

    /** @param array<string, string> $headers extra auth headers, if your facilitator needs them */
    public function __construct(
        string $url = self::DEFAULT_URL,
        int $timeout = 30,
        array $headers = []
    ) {
        $this->url = rtrim($url, '/');
        $this->timeout = $timeout;
        $this->headers = $headers;
    }

    /**
     * Payment kinds this facilitator can handle.
     *
     * @return array<int, array<string, mixed>>
     */
    public function supported(): array
    {
        $res = $this->request('GET', '/supported');

        return $res['kinds'] ?? [];
    }

    /**
     * Check a payment without moving money.
     *
     * @return array{isValid: bool, invalidReason: ?string, payer: ?string}
     */
    public function verify(PaymentPayload $payload, PaymentRequirements $requirements): array
    {
        $res = $this->request('POST', '/verify', [
            'x402Version'         => $payload->x402Version,
            'paymentPayload'      => $payload->toArray(),
            'paymentRequirements' => $requirements->toArray(),
        ]);

        return [
            'isValid'       => (bool) ($res['isValid'] ?? false),
            'invalidReason' => $res['invalidReason'] ?? ($res['error'] ?? null),
            'payer'         => $res['payer'] ?? null,
        ];
    }

    /**
     * Move the money. Returns the on-chain transaction on success.
     *
     * @return array{success: bool, transaction: ?string, network: ?string, errorReason: ?string, payer: ?string}
     */
    public function settle(PaymentPayload $payload, PaymentRequirements $requirements): array
    {
        $res = $this->request('POST', '/settle', [
            'x402Version'         => $payload->x402Version,
            'paymentPayload'      => $payload->toArray(),
            'paymentRequirements' => $requirements->toArray(),
        ]);

        return [
            'success'     => (bool) ($res['success'] ?? false),
            'transaction' => $res['transaction'] ?? null,
            'network'     => $res['network'] ?? null,
            'errorReason' => $res['errorReason'] ?? ($res['error'] ?? null),
            'payer'       => $res['payer'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init($this->url . $path);
        if ($ch === false) {
            throw new X402Exception('Could not initialise a HTTP request to the facilitator.');
        }

        $headers = ['Accept: application/json'];
        foreach ($this->headers as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new X402Exception("Facilitator unreachable: {$curlError}");
        }

        $decoded = json_decode((string) $raw, true);

        if (!is_array($decoded)) {
            throw new X402Exception(
                "Facilitator returned non-JSON (HTTP {$status}): " . substr((string) $raw, 0, 200)
            );
        }

        if ($status >= 400 && !isset($decoded['isValid']) && !isset($decoded['success'])) {
            $msg = $decoded['error'] ?? $decoded['message'] ?? 'unknown error';
            throw new X402Exception("Facilitator error (HTTP {$status}): " . (is_string($msg) ? $msg : json_encode($msg)));
        }

        return $decoded;
    }
}
