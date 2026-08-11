<?php

declare(strict_types=1);

namespace X402;

/**
 * Converts human prices into the integer atomic amounts the protocol carries.
 *
 * Money is parsed as a decimal string, never a float — "$0.001" through a
 * float would settle the wrong amount often enough to matter.
 */
final class Price
{
    /**
     * @param string|int $price   "$0.001", "0.001", or an already-atomic int
     * @param int        $decimals asset decimals (USDC is 6)
     *
     * @return string atomic amount, e.g. "1000"
     */
    public static function toAtomic($price, int $decimals): string
    {
        if (is_int($price)) {
            if ($price < 0) {
                throw new X402Exception('Price cannot be negative.');
            }

            return (string) $price;
        }

        $clean = trim((string) $price);
        $clean = ltrim($clean, '$');
        $clean = str_replace([',', '_', ' '], '', $clean);

        if ($clean === '' || !preg_match('/^\d*(?:\.\d*)?$/', $clean)) {
            throw new X402Exception("Unparseable price: '{$price}'");
        }

        [$whole, $frac] = array_pad(explode('.', $clean, 2), 2, '');

        if (strlen($frac) > $decimals) {
            $dropped = rtrim(substr($frac, $decimals), '0');
            if ($dropped !== '') {
                throw new X402Exception(
                    "Price '{$price}' is finer than the asset's {$decimals} decimals; "
                    . 'it would silently round down.'
                );
            }
            $frac = substr($frac, 0, $decimals);
        }

        $atomic = ltrim($whole . str_pad($frac, $decimals, '0'), '0');

        return $atomic === '' ? '0' : $atomic;
    }

    /** Atomic amount back to a human string, for logs and receipts. */
    public static function toDecimal(string $atomic, int $decimals): string
    {
        $atomic = ltrim($atomic, '0');
        $atomic = $atomic === '' ? '0' : $atomic;

        if ($decimals === 0) {
            return $atomic;
        }

        $padded = str_pad($atomic, $decimals + 1, '0', STR_PAD_LEFT);
        $whole = substr($padded, 0, -$decimals);
        $frac = rtrim(substr($padded, -$decimals), '0');

        return $frac === '' ? $whole : "{$whole}.{$frac}";
    }
}
