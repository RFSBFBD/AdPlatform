# FINANCIAL DATA INTEGRITY & CURRENCY VALIDATION REPORT

**Project:** Multi-Tenant Advertising Management Platform  
**Auditor:** Data Engineering & Analytics Specialist, Financial Systems Auditor  
**Audit Date:** 2026-11-19  

---

## 1. Executive Summary

Financial metrics within advertising management platforms (ad spend, conversion values, ROAS, CPC, CPM, and outstanding billing balances) represent critical decision-making data. Incorrect aggregation or currency confusion can lead to severe marketing misallocations or reporting inaccuracies.

### Integrity Rating: **ARCHITECTURALLY COMPLIANT WITH DESIGNATED MULTI-CURRENCY CONSTRAINTS**

---

## 2. Multi-Currency Detection & Handling

### 2.1 Currency Ingestion by Provider
Each advertising platform reports spend and currency at the account level during OAuth discovery and insights synchronization:

| Provider | Currency Source | Field Extracted | Normalization Rule |
|---|---|---|---|
| **Meta Ads** | Graph API Ad Account metadata | `currency` (e.g. `USD`, `EUR`, `GBP`) | Directly assigned to `connected_ad_accounts.currency` and stored in `performance_metrics.currency`. |
| **Google Ads** | Google Ads API Customer Resource | `currencyCode` (or defaults to account currency) | Extracted during account discovery. Spend is converted from `costMicros` by dividing by `1,000,000.0`. |
| **TikTok Ads** | Business API Advertiser metadata | `currency` (e.g. `USD`, `EUR`) | Extracted in `/oauth2/advertiser/get/` and assigned to `connected_ad_accounts.currency`. |

### 2.2 Currency Aggregation Logic in Analytics
In [AnalyticsController.php](file:///d:/soc%20platform/backend/app/Http/Controllers/Api/V1/AnalyticsController.php) (`overview`, lines 53-140):

1. **Partitioned Currency Breakdown:**
   ```php
   $currencyBreakdown = (clone $query)
       ->join('connected_ad_accounts', 'performance_metrics.connected_ad_account_id', '=', 'connected_ad_accounts.id')
       ->select([
           'connected_ad_accounts.currency',
           DB::raw('COALESCE(SUM(performance_metrics.spend), 0) as spend'),
           DB::raw('COALESCE(SUM(performance_metrics.conversion_value), 0) as conversion_value'),
       ])
       ->groupBy('connected_ad_accounts.currency')
       ->get();
   ```
2. **Explicit Currency Filtering:**
   The API provides a `currency` query parameter (`?currency=USD`, `?currency=EUR`). When supplied:
   ```php
   if (!empty($validated['currency'])) {
       $query->whereHas('connectedAdAccount', function ($q) use ($validated) {
           $q->where('currency', strtoupper($validated['currency']));
       });
   }
   ```
   All aggregates (`spend`, `cpc`, `cpm`, `roas`, `conversion_value`) are filtered strictly to the selected currency.

3. **Behavior When Unfiltered (Mixed Currencies):**
   - If a tenant has accounts in multiple currencies (e.g., Account 1 in USD and Account 2 in EUR) and calls the endpoint without `?currency=XYZ`, `summary.spend` represents the arithmetic sum of values across both accounts.
   - To prevent deception, the response exposes:
     - `summary.primary_currency`: The primary currency of the tenant's largest account.
     - `currency_breakdown`: An explicit array breaking down spend and conversion value by currency code.

---

## 3. Absence of Live FX Conversion Engine

### Verified System Reality:
- There is **no automated foreign exchange (FX) conversion table or real-time conversion service** (such as European Central Bank or Open Exchange Rates API) in the repository.
- Historical metrics are stored in the original currency reported by the ad platform.
- **Architectural Recommendation for Production:** If client accounts require consolidated cross-currency reporting into a single presentation currency (e.g. converting 1,000 EUR to USD at the exchange rate of the metric's date), an exchange-rate ingestion service should be scheduled. Currently, users must filter by currency for mathematically sound single-currency reports.

---

## 4. Timezone Normalization & Reporting Windows

### 4.1 Timezone Ingestion
- Each connected ad account captures and persists its native timezone string (e.g. `America/New_York`, `Europe/London`, `UTC`) from the provider API.
- Daily performance metrics are partitioned by `date` (`YYYY-MM-DD`) as reported by the advertising provider in that account's native reporting timezone.

### 4.2 Incremental Sync Attribution Window
In [AdDataSyncEngine.php](file:///d:/soc%20platform/backend/app/Services/Advertising/Sync/AdDataSyncEngine.php) (lines 64-69):
- Incremental syncs do not merely query today's date; they query the preceding **3-day rolling window** (`subDays(3)`).
- **Rationale:** Digital advertising platforms (especially Meta and TikTok) use delayed conversion attribution windows (e.g. 1-day click, 7-day click). Re-syncing the last 3 days ensures that delayed conversion actions and adjusted spend numbers are backfilled and reconciled.

---

## 5. Derived Metric Calculation Formulas

All derived financial and efficiency metrics are calculated using strict zero-division guards:

| Metric | Formula | Zero Division Guard |
|---|---|---|
| **CPC (Cost Per Click)** | `spend / clicks` | `$clicks > 0 ? round($spend / $clicks, 2) : 0.0` |
| **CPM (Cost Per Mille / 1,000 Impressions)** | `(spend / impressions) * 1000` | `$impressions > 0 ? round(($spend / $impressions) * 1000, 2) : 0.0` |
| **CTR (Click-Through Rate %)** | `(clicks / impressions) * 100` | `$impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0` |
| **ROAS (Return on Ad Spend)** | `conversion_value / spend` | `$spend > 0 ? round($conversionValue / $spend, 2) : 0.0` |
| **CPA (Cost Per Acquisition)** | `spend / conversions` | `$conversions > 0 ? round($spend / $conversions, 2) : 0.0` |

---

## 6. Treatment of Missing or Incomplete Data
- All metric aggregations use `COALESCE(SUM(...), 0)` to guarantee that database queries return numeric values rather than `null`.
- Missing daily metrics on inactive campaigns are represented as zero rows rather than omissions.
- Raw API payloads are preserved in `raw_provider_payloads` table to allow audit and re-calculation if providers adjust historical reporting figures.
