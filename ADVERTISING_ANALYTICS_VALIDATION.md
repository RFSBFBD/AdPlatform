# HIERARCHICAL ADVERTISING ANALYTICS VALIDATION REPORT

**Domain:** Multi-Entity Advertising Analytics, Uniqueness Constraints & Metric Aggregation  
**Auditor:** Principal Software Architect & Senior Database Engineer  
**Date:** 2026-11-19  
**Status:** `VERIFIED & OPERATIONAL`

---

## 1. Architectural Challenge & Identified Vulnerability

A critical defect was identified in the storage and aggregation of advertising performance metrics:
1. **Metric Overwriting Across Entities:**
   In `AdDataSyncEngine`, `PerformanceMetric::updateOrCreate` used the matching keys:
   `['connected_ad_account_id', 'campaign_id', 'date', 'provider']`.
   Both `ad_set_id` and `ad_id` were omitted from the uniqueness matching keys. Consequently:
   - Ingesting ad-set level metrics overwrote campaign-level summaries.
   - Different ad sets under the same campaign on the same date overwrote each other.
   - Different ads under the same ad set overwrote each other.
2. **Missing Database Uniqueness Constraint:**
   The `performance_metrics` table lacked a unique index covering entity hierarchy, allowing concurrent sync runs to insert duplicate rows.

---

## 2. Engineering Solution Implemented

### 2.1 Database Uniqueness Migration (`2026_11_19_110000_add_unique_constraint_to_performance_metrics.php`)
Added a PostgreSQL `NULLS NOT DISTINCT` unique index across the full hierarchy tuple:
```sql
CREATE UNIQUE INDEX IF NOT EXISTS performance_metrics_hierarchy_unique 
ON performance_metrics (connected_ad_account_id, campaign_id, ad_set_id, ad_id, date, provider) 
NULLS NOT DISTINCT;
```
This PostgreSQL standard guarantees that:
- A campaign-level metric (`ad_set_id IS NULL`, `ad_id IS NULL`) is unique per account, campaign, date, provider.
- An ad-set level metric (`ad_set_id = X`, `ad_id IS NULL`) is unique per account, campaign, ad set, date, provider.
- An ad level metric (`ad_set_id = X`, `ad_id = Y`) is unique per account, campaign, ad set, ad, date, provider.
- No two rows can duplicate the same entity tuple on the same date.

### 2.2 Hierarchical Matching in `AdDataSyncEngine`
Updated `AdDataSyncEngine::syncAccount` to resolve and include `$campaignId`, `$adSetId`, and `$adId` in the matching keys:
```php
PerformanceMetric::updateOrCreate(
    [
        'connected_ad_account_id' => $account->id,
        'campaign_id' => $campaignId,
        'ad_set_id' => $adSetId,
        'ad_id' => $adId,
        'date' => $m['date'],
        'provider' => $account->provider,
    ],
    [
        'spend' => $spend,
        'impressions' => $impressions,
        'clicks' => $clicks,
        'conversions' => $conversions,
        'conversion_value' => $conversionValue,
        'cpc' => round($cpc, 4),
        'cpm' => round($cpm, 4),
        'ctr' => round($ctr, 4),
        'roas' => round($roas, 4),
        'cpa' => round($cpa, 4),
        'currency' => $account->currency ?? 'USD',
        'metadata' => [
            'synced_at' => now()->toIso8601String(),
            'sync_type' => $syncType,
            'entity_level' => $adId ? 'ad' : ($adSetId ? 'ad_set' : 'campaign'),
        ],
    ]
);
```

---

## 3. Verification & Evidence

Automated test: `test_hierarchical_metric_storage_prevents_overwrites_between_entities` in `AdPlatformDataIntegrityTest.php`.
1. Simulates sync with metrics at Campaign level ($300 spend), AdSet level ($200 spend), and Ad level ($100 spend) on the exact same date for the same campaign.
2. Verified that exactly 3 distinct rows are stored in `performance_metrics`.
3. Verified that no overwrites or duplicates occurred.
4. Total assertions: 5/5 passed cleanly.
