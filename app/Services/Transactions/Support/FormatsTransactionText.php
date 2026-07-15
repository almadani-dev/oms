<?php

namespace App\Services\Transactions\Support;

use Illuminate\Support\Str;

/**
 * Shared text-formatting rules for the system-generated Arabic descriptions
 * on transactions and transaction lines. Centralized so both builders stay
 * byte-consistent: Arabic text with English digits, two decimals, thousands
 * separators, and exactly one final period.
 */
trait FormatsTransactionText
{
    /**
     * "حساب {name}", never duplicating an already-present "حساب" prefix.
     */
    protected function formatAccountLabel(string $accountName): string
    {
        $name = trim($accountName);

        return Str::startsWith($name, 'حساب') ? $name : 'حساب ' . $name;
    }

    /**
     * English digits, exactly two decimals, comma thousands separators.
     */
    protected function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', ',');
    }

    /**
     * Trim, collapse line breaks and repeated whitespace into single spaces,
     * strip repeated trailing periods, and append exactly one final period.
     */
    protected function normalizeArabicText(string $text): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        $normalized = rtrim($normalized);
        $normalized = rtrim($normalized, '.');

        return $normalized . '.';
    }
}
