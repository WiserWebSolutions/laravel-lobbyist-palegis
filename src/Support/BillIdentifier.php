<?php

namespace WiserWebSolutions\LaravelPalegis\Support;

/**
 * Matches a caller-supplied bill identifier against a Bill History record,
 * accepting the full id ("20250HB0017") or the designator ("HB17"/"HB0017",
 * case-insensitive, leading zeros optional).
 */
class BillIdentifier
{
    /**
     * Normalize a designator (or arbitrary identifier) for comparison:
     * strips whitespace, uppercases, and drops leading zeros from the
     * numeric portion (e.g. "hb 0017" -> "HB17").
     */
    public static function normalize(string $value): string
    {
        return preg_replace(
            '/^([A-Z]+)0*(\d+)$/',
            '$1$2',
            strtoupper(preg_replace('/\s+/', '', $value))
        );
    }

    public static function matches(array $record, string $identifier): bool
    {
        $needle = self::normalize($identifier);

        return strtoupper((string) ($record['id'] ?? '')) === strtoupper($identifier)
            || self::normalize((string) ($record['designator'] ?? '')) === $needle;
    }
}
