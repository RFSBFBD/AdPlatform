<?php

namespace App\Services\Currency;

use App\Models\ExchangeRate;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class DatabaseExchangeRateProvider implements ExchangeRateProviderInterface
{
    /**
     * Validate an ISO 4217 3-letter currency code.
     */
    public static function validateCurrencyCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z]{3}$/', $code)) {
            throw new InvalidArgumentException("Invalid ISO 4217 currency code: '{$code}'. Must be a 3-letter alphabetic code.");
        }
        return $code;
    }

    /**
     * Get the exchange rate for base -> quote on a given date.
     * Supports direct rate, inverse rate, and previous closest business day within 7 days.
     */
    public function getRate(string $baseCurrency, string $quoteCurrency, string $date): ?float
    {
        $details = $this->getRateDetails($baseCurrency, $quoteCurrency, $date);
        return $details ? $details['rate'] : null;
    }

    /**
     * Get detailed exchange rate info including the actual effective date used.
     *
     * @return array{rate: float, effective_date: string, is_fallback: bool, source: string}|null
     */
    public function getRateDetails(string $baseCurrency, string $quoteCurrency, string $date): ?array
    {
        $base = self::validateCurrencyCode($baseCurrency);
        $quote = self::validateCurrencyCode($quoteCurrency);

        $formattedDate = Carbon::parse($date)->format('Y-m-d');

        if ($base === $quote) {
            return [
                'rate' => 1.0,
                'effective_date' => $formattedDate,
                'is_fallback' => false,
                'source' => 'identity',
            ];
        }

        // 1. Direct rate on exact date (1 base = X quote)
        $direct = ExchangeRate::where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->where('effective_date', $formattedDate)
            ->where('exchange_rate', '>', 0)
            ->first();

        if ($direct) {
            return [
                'rate' => (float) $direct->exchange_rate,
                'effective_date' => $direct->effective_date instanceof Carbon ? $direct->effective_date->format('Y-m-d') : (string) $direct->effective_date,
                'is_fallback' => false,
                'source' => $direct->source ?? 'database',
            ];
        }

        // 2. Inverse rate on exact date (1 quote = Y base -> rate = 1/Y)
        $inverse = ExchangeRate::where('base_currency', $quote)
            ->where('quote_currency', $base)
            ->where('effective_date', $formattedDate)
            ->where('exchange_rate', '>', 0)
            ->first();

        if ($inverse) {
            return [
                'rate' => (float) (1.0 / $inverse->exchange_rate),
                'effective_date' => $inverse->effective_date instanceof Carbon ? $inverse->effective_date->format('Y-m-d') : (string) $inverse->effective_date,
                'is_fallback' => false,
                'source' => ($inverse->source ?? 'database') . '_inverse',
            ];
        }

        // 3. Fallback to closest prior rate within 7 calendar days
        $priorDirect = ExchangeRate::where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->where('effective_date', '<', $formattedDate)
            ->where('effective_date', '>=', Carbon::parse($formattedDate)->subDays(7)->format('Y-m-d'))
            ->where('exchange_rate', '>', 0)
            ->orderBy('effective_date', 'desc')
            ->first();

        if ($priorDirect) {
            return [
                'rate' => (float) $priorDirect->exchange_rate,
                'effective_date' => $priorDirect->effective_date instanceof Carbon ? $priorDirect->effective_date->format('Y-m-d') : (string) $priorDirect->effective_date,
                'is_fallback' => true,
                'source' => $priorDirect->source ?? 'database_prior',
            ];
        }

        $priorInverse = ExchangeRate::where('base_currency', $quote)
            ->where('quote_currency', $base)
            ->where('effective_date', '<', $formattedDate)
            ->where('effective_date', '>=', Carbon::parse($formattedDate)->subDays(7)->format('Y-m-d'))
            ->where('exchange_rate', '>', 0)
            ->orderBy('effective_date', 'desc')
            ->first();

        if ($priorInverse) {
            return [
                'rate' => (float) (1.0 / $priorInverse->exchange_rate),
                'effective_date' => $priorInverse->effective_date instanceof Carbon ? $priorInverse->effective_date->format('Y-m-d') : (string) $priorInverse->effective_date,
                'is_fallback' => true,
                'source' => ($priorInverse->source ?? 'database_prior') . '_inverse',
            ];
        }

        return null;
    }

    /**
     * Store or update an exchange rate.
     */
    public function storeRate(string $baseCurrency, string $quoteCurrency, float $rate, string $date, string $source = 'manual'): ExchangeRate
    {
        $base = self::validateCurrencyCode($baseCurrency);
        $quote = self::validateCurrencyCode($quoteCurrency);

        if ($rate <= 0) {
            throw new InvalidArgumentException("Exchange rate must be strictly positive (> 0), got: {$rate}");
        }

        $formattedDate = Carbon::parse($date)->format('Y-m-d');

        return ExchangeRate::updateOrCreate(
            [
                'base_currency' => $base,
                'quote_currency' => $quote,
                'effective_date' => $formattedDate,
            ],
            [
                'exchange_rate' => $rate,
                'source' => $source,
                'retrieved_at' => now(),
            ]
        );
    }
}
