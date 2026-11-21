<?php

namespace App\Services\Currency;

use App\Models\ExchangeRate;

interface ExchangeRateProviderInterface
{
    /**
     * Get the exchange rate for converting baseCurrency to quoteCurrency on a specific date.
     * 1 unit of baseCurrency = $rate units of quoteCurrency.
     */
    public function getRate(string $baseCurrency, string $quoteCurrency, string $date): ?float;

    /**
     * Get detailed exchange rate info (rate, actual effective date, source, fallback status).
     *
     * @return array{rate: float, effective_date: string, is_fallback: bool, source: string}|null
     */
    public function getRateDetails(string $baseCurrency, string $quoteCurrency, string $date): ?array;

    /**
     * Persist or update an exchange rate in storage.
     */
    public function storeRate(string $baseCurrency, string $quoteCurrency, float $rate, string $date, string $source = 'manual'): ExchangeRate;
}
