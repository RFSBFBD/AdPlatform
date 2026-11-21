<?php

namespace App\Services\Currency;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

class CurrencyConversionService
{
    public function __construct(
        protected ExchangeRateProviderInterface $rateProvider
    ) {}

    /**
     * Validate an ISO 4217 3-letter currency code.
     */
    public static function validateCurrency(string $code): string
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z]{3}$/', $code)) {
            throw new InvalidArgumentException("Invalid ISO 4217 currency code: '{$code}'. Must be a 3-letter alphabetic code.");
        }
        return $code;
    }

    /**
     * Convert an individual monetary amount from one currency to another on a specific date.
     *
     * @param float $amount
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param string $date (YYYY-MM-DD)
     * @param bool $throwOnMissing
     * @return array
     */
    public function convert(
        float $amount,
        string $fromCurrency,
        string $toCurrency,
        string $date,
        bool $throwOnMissing = false
    ): array {
        $from = self::validateCurrency($fromCurrency);
        $to = self::validateCurrency($toCurrency);
        $formattedDate = substr(trim($date), 0, 10);

        if ($from === $to) {
            return [
                'original_amount' => $amount,
                'original_currency' => $from,
                'converted_amount' => $amount,
                'target_currency' => $to,
                'exchange_rate' => 1.0,
                'requested_date' => $formattedDate,
                'effective_date' => $formattedDate,
                'is_fallback' => false,
                'is_converted' => false,
                'status' => 'success',
            ];
        }

        $details = method_exists($this->rateProvider, 'getRateDetails')
            ? $this->rateProvider->getRateDetails($from, $to, $formattedDate)
            : null;

        $rate = $details ? $details['rate'] : $this->rateProvider->getRate($from, $to, $formattedDate);

        if ($rate === null || $rate <= 0) {
            if ($throwOnMissing) {
                throw new RuntimeException("Missing or invalid exchange rate for {$from} to {$to} on date {$formattedDate}.");
            }

            return [
                'original_amount' => $amount,
                'original_currency' => $from,
                'converted_amount' => null,
                'target_currency' => $to,
                'exchange_rate' => null,
                'requested_date' => $formattedDate,
                'effective_date' => null,
                'is_fallback' => false,
                'is_converted' => false,
                'status' => 'missing_rate',
                'error' => "Exchange rate unavailable for {$from} to {$to} on {$formattedDate}",
            ];
        }

        $converted = round($amount * $rate, 4);

        return [
            'original_amount' => $amount,
            'original_currency' => $from,
            'converted_amount' => $converted,
            'target_currency' => $to,
            'exchange_rate' => $rate,
            'requested_date' => $formattedDate,
            'effective_date' => $details['effective_date'] ?? $formattedDate,
            'is_fallback' => $details['is_fallback'] ?? false,
            'is_converted' => true,
            'status' => 'success',
        ];
    }

    /**
     * Convert and normalize a collection of performance metric rows into a unified target reporting currency.
     *
     * @param iterable $metricsRows
     * @param string $targetCurrency
     * @return array
     */
    public function convertMetricsRows(iterable $metricsRows, string $targetCurrency): array
    {
        $target = self::validateCurrency($targetCurrency);
        $convertedRows = [];
        $missingRates = [];
        $missingRatesDetails = [];
        $unconvertedTotals = [];

        $totalConvertedSpend = 0.0;
        $totalConvertedValue = 0.0;

        $convertedImpressions = 0;
        $convertedClicks = 0;
        $convertedConversions = 0.0;

        $totalImpressions = 0;
        $totalClicks = 0;
        $totalConversions = 0.0;

        $currencyBreakdown = [];

        foreach ($metricsRows as $row) {
            $rowObj = is_array($row) ? (object) $row : $row;
            $rawCurrency = $rowObj->currency ?? 'USD';
            $rowCurrency = self::validateCurrency($rawCurrency);
            $date = is_string($rowObj->date) ? substr($rowObj->date, 0, 10) : $rowObj->date->format('Y-m-d');
            $spend = (float) ($rowObj->spend ?? 0);
            $convValue = (float) ($rowObj->conversion_value ?? 0);
            $impressions = (int) ($rowObj->impressions ?? 0);
            $clicks = (int) ($rowObj->clicks ?? 0);
            $conversions = (float) ($rowObj->conversions ?? 0);

            // Record native currency breakdown (unmixed sums per source currency)
            if (!isset($currencyBreakdown[$rowCurrency])) {
                $currencyBreakdown[$rowCurrency] = [
                    'currency' => $rowCurrency,
                    'original_spend' => 0.0,
                    'original_conversion_value' => 0.0,
                    'impressions' => 0,
                    'clicks' => 0,
                    'conversions' => 0.0,
                    'record_count' => 0,
                ];
            }
            $currencyBreakdown[$rowCurrency]['original_spend'] += $spend;
            $currencyBreakdown[$rowCurrency]['original_conversion_value'] += $convValue;
            $currencyBreakdown[$rowCurrency]['impressions'] += $impressions;
            $currencyBreakdown[$rowCurrency]['clicks'] += $clicks;
            $currencyBreakdown[$rowCurrency]['conversions'] += $conversions;
            $currencyBreakdown[$rowCurrency]['record_count']++;

            // Perform historical daily rate conversion
            $spendConv = $this->convert($spend, $rowCurrency, $target, $date);
            $valConv = $this->convert($convValue, $rowCurrency, $target, $date);

            $isConvertible = ($spendConv['status'] === 'success' && $valConv['status'] === 'success');

            if (!$isConvertible) {
                $missingRates[$rowCurrency] = ($missingRates[$rowCurrency] ?? 0) + 1;
                $missingRatesDetails[] = [
                    'source_currency' => $rowCurrency,
                    'target_currency' => $target,
                    'date' => $date,
                ];

                if (!isset($unconvertedTotals[$rowCurrency])) {
                    $unconvertedTotals[$rowCurrency] = [
                        'currency' => $rowCurrency,
                        'unconverted_spend' => 0.0,
                        'unconverted_conversion_value' => 0.0,
                        'unconverted_impressions' => 0,
                        'unconverted_clicks' => 0,
                        'unconverted_conversions' => 0.0,
                        'row_count' => 0,
                    ];
                }
                $unconvertedTotals[$rowCurrency]['unconverted_spend'] += $spend;
                $unconvertedTotals[$rowCurrency]['unconverted_conversion_value'] += $convValue;
                $unconvertedTotals[$rowCurrency]['unconverted_impressions'] += $impressions;
                $unconvertedTotals[$rowCurrency]['unconverted_clicks'] += $clicks;
                $unconvertedTotals[$rowCurrency]['unconverted_conversions'] += $conversions;
                $unconvertedTotals[$rowCurrency]['row_count']++;
            } else {
                $totalConvertedSpend += $spendConv['converted_amount'];
                $totalConvertedValue += $valConv['converted_amount'];
                $convertedImpressions += $impressions;
                $convertedClicks += $clicks;
                $convertedConversions += $conversions;
            }

            // Global totals (volume)
            $totalImpressions += $impressions;
            $totalClicks += $clicks;
            $totalConversions += $conversions;

            $convertedRows[] = [
                'date' => $date,
                'provider' => $rowObj->provider ?? null,
                'campaign_id' => $rowObj->campaign_id ?? null,
                'campaign_name' => $rowObj->campaign_name ?? null,
                'ad_set_id' => $rowObj->ad_set_id ?? null,
                'ad_id' => $rowObj->ad_id ?? null,
                'original_currency' => $rowCurrency,
                'original_spend' => $spend,
                'original_conversion_value' => $convValue,
                'target_currency' => $target,
                'converted_spend' => $spendConv['converted_amount'],
                'converted_conversion_value' => $valConv['converted_amount'],
                'exchange_rate' => $spendConv['exchange_rate'],
                'requested_date' => $spendConv['requested_date'] ?? $date,
                'effective_date' => $spendConv['effective_date'] ?? $date,
                'is_fallback' => $spendConv['is_fallback'] ?? false,
                'conversion_status' => $spendConv['status'],
                'impressions' => $impressions,
                'clicks' => $clicks,
                'conversions' => $conversions,
            ];
        }

        // Prevent mathematically invalid aggregate metrics by calculating financial ratios strictly against the converted volume
        $cpc = $convertedClicks > 0 ? round($totalConvertedSpend / $convertedClicks, 2) : 0.0;
        $cpm = $convertedImpressions > 0 ? round(($totalConvertedSpend / $convertedImpressions) * 1000, 2) : 0.0;
        $ctr = $totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : 0.0;
        $roas = $totalConvertedSpend > 0 ? round($totalConvertedValue / $totalConvertedSpend, 2) : 0.0;
        $cpa = $convertedConversions > 0 ? round($totalConvertedSpend / $convertedConversions, 2) : 0.0;

        $hasMissingRates = !empty($missingRates);
        $isPartial = $hasMissingRates;
        $conversionComplete = !$hasMissingRates;

        return [
            'reporting_currency' => $target,
            'summary' => [
                'converted_spend' => round($totalConvertedSpend, 2),
                'converted_conversion_value' => round($totalConvertedValue, 2),
                'reporting_currency' => $target,
                'converted_impressions' => $convertedImpressions,
                'converted_clicks' => $convertedClicks,
                'converted_conversions' => $convertedConversions,
                'impressions' => $totalImpressions,
                'clicks' => $totalClicks,
                'conversions' => $totalConversions,
                'cpc' => $cpc,
                'cpm' => $cpm,
                'ctr' => $ctr,
                'roas' => $roas,
                'cpa' => $cpa,
                'is_partial' => $isPartial,
            ],
            'currency_breakdown' => array_values($currencyBreakdown),
            'conversion_metadata' => [
                'has_mixed_currencies' => count($currencyBreakdown) > 1,
                'conversion_complete' => $conversionComplete,
                'is_partial' => $isPartial,
                'missing_rates_count' => array_sum($missingRates),
                'missing_rate_currencies' => array_keys($missingRates),
                'missing_rates_details' => array_values(array_unique($missingRatesDetails, SORT_REGULAR)),
                'unconverted_totals' => array_values($unconvertedTotals),
                'partial_reporting_warning' => $isPartial
                    ? 'Aggregate financial metrics reflect converted subset only. Unconverted rows omitted from spend ratios.'
                    : null,
            ],
            'rows' => $convertedRows,
        ];
    }
}
