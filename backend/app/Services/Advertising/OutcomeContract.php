<?php

namespace App\Services\Advertising;

use App\Models\ConnectedAdAccount;
use App\Services\Analytics\OutcomeSemanticGuard;

/** Exact selected fields, not universal business outcomes. No user equivalence override. */
final class OutcomeContract
{
    public static function forRow(ConnectedAdAccount $account, array $row): ?array
    {
        [$count,$value,$attribution] = match ($account->provider) {
            'meta' => ['offsite_conversion.fb_pixel_purchase', 'offsite_conversion.fb_pixel_purchase', 'provider_default_unverified'],
            'google' => ['metrics.conversions', 'metrics.conversions_value', 'account_configured_unverified'],
            'tiktok' => ['conversion', 'total_purchase_value', 'account_configured_unverified_different_metric_families'],
            'snapchat' => [null, null, null], // Implemented bare account endpoint is spend-only.
            'pinterest' => ['TOTAL_CHECKOUT', 'TOTAL_CHECKOUT_VALUE_IN_MICRO_DOLLAR', 'click30d_engagement30d_view1d_ad_action_date'],
            'reddit' => ['conversion_purchase_clicks+conversion_purchase_views', 'conversion_purchase_total_value', 'account_configured_unverified'],
            'spotify' => ['PURCHASES', 'REVENUE', 'provider_reported_unverified'],
            'linkedin' => ['externalWebsiteConversions', 'conversionValueInLocalCurrency', 'deployment_declared_not_independently_verified'],
            'taboola' => ['cpa_actions_num', 'conversions_value', 'provider_reported_unverified'],
            default => [null, null, null],
        };
        if ($count === null) {
            return null;
        }

        return ['version' => OutcomeSemanticGuard::VERSION, 'provider' => $account->provider, 'count_metric' => $count, 'value_metric' => $value, 'attribution_basis' => $attribution, 'configured_account_scope'=>in_array($account->provider,['google','tiktok','reddit','spotify','linkedin','taboola'],true)?$account->external_account_id:null,
            'source_definition' => $row['metric_definition'] ?? null, 'value_truth' => 'provider_reported_not_verified_sales', 'cross_source_equivalence' => false];
    }
}
