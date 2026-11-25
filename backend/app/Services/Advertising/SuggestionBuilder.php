<?php

namespace App\Services\Advertising;

/**
 * Read-only suggestions built only from (a) the pulled-data coverage and (b) the OR result.
 * Every item cites the exact fields it came from. No numbers are computed here and nothing is
 * applied: the OR numbers are relayed as the OR engine returned them, with their assumption label.
 */
class SuggestionBuilder
{
    private const LABEL = ['meta' => 'Meta Ads', 'google' => 'Google Ads', 'tiktok' => 'TikTok Ads', 'snapchat' => 'Snapchat Ads'];

    public function build(array $coverage, array $or): array
    {
        $out = [];
        foreach ($coverage['platforms'] ?? [] as $p) {
            $name = self::LABEL[$p['provider']] ?? $p['provider'];
            $pv = $p['provider'];
            if ($p['status'] === 'not_connected') {
                $out[] = $this->item('data', $pv, 'info', "Connect $name to include it", "$name has no connected account, so it has no data and is not part of any analysis.", [['pulled_data', "$pv.status", 'not_connected']]);
                continue;
            }
            foreach ($p['accounts'] as $a) {
                if (($a['last_sync_status'] ?? null) === 'failed') {
                    $out[] = $this->item('data', $pv, 'attention', "Fix the failed sync for $name", 'The last sync of ' . ($a['name'] ?? 'this account') . ' failed. Reconnect or retry before relying on its numbers.', [['pulled_data', "$pv.accounts.last_sync_error", (string) ($a['last_sync_error'] ?? '')]]);
                }
            }
            if ($p['status'] === 'connected_no_data') {
                $out[] = $this->item('data', $pv, 'attention', "No report rows pulled for $name", "$name is connected but no report rows exist in the period, so nothing can be said about its performance.", [['pulled_data', "$pv.status", 'connected_no_data']]);
            } elseif (empty($p['has_conversion_value'])) {
                $out[] = $this->item('data', $pv, 'attention', "$name has no purchase value", "Without purchase value, $name cannot be scored for return and is left out of the OR model. Check that purchase tracking is set up for it.", [['pulled_data', "$pv.has_conversion_value", 'false']]);
            }
            if ($pv === 'snapchat' && $p['status'] === 'data_available') {
                $out[] = $this->item('data', $pv, 'info', 'Read Snapchat numbers with its limits', 'The implemented bare Snapchat account endpoint supports spend inspection only. Stored delivery and purchase rows are not a supported analytical source; no budget recommendation is available.', [['pulled_data', 'snapchat.has_campaign_level_rows', $p['has_campaign_level_rows'] ? 'true' : 'false']]);
            }
        }

        $status = $or['status'] ?? 'insufficient_data';
        if ($status !== 'ok') {
            $out[] = $this->item('or', null, 'info', 'No budget scenario was produced', (string) ($or['status_message'] ?? 'The OR engine produced no allocation.') . ' No budget suggestion is made without it.', [['or_result', 'status', (string) $status]]);
            return $out;
        }

        foreach ($or['channel_allocations'] ?? [] as $al) {
            $ch = $al['channel'];
            $name = $al['display_name'] ?? $ch;
            $act = $al['action'] ?? null;
            if ($act === 'SCALE_UP' || $act === 'SCALE_DOWN') {
                $dir = $act === 'SCALE_UP' ? 'Consider raising' : 'Consider holding back';
                $out[] = $this->item('budget', $ch, 'scenario', "$dir $name spend (scenario)",
                    sprintf('Under the assumed response curve, the OR scenario puts %s at %s versus %s in its same-total-spend baseline (marginal ROAS %sx now, %sx at the scenario). This is an assumption-based scenario, not a prediction.',
                        $name, $al['optimal_spend'], $al['baseline_spend_same_budget'], $al['marginal_roas_current'], $al['marginal_roas_optimal']),
                    [['or_result', "channel_allocations.$ch.optimal_spend", (string) $al['optimal_spend']], ['or_result', "channel_allocations.$ch.baseline_spend_same_budget", (string) $al['baseline_spend_same_budget']], ['or_result', "channel_allocations.$ch.marginal_roas_current", (string) $al['marginal_roas_current']]]);
            }
        }
        foreach ($or['warnings'] ?? [] as $w) {
            if (stripos((string) $w, 'excluded') !== false) {
                $out[] = $this->item('budget', null, 'attention', 'A channel was left out of the OR scenario', (string) $w, [['or_result', 'warnings', 'excluded channel']]);
            }
        }
        return $out;
    }

    private function item(string $kind, ?string $platform, string $severity, string $title, string $text, array $evidence): array
    {
        return [
            'kind' => $kind,
            'platform' => $platform,
            'severity' => $severity,
            'title' => $title,
            'text' => $text,
            'evidence' => array_map(fn($e) => ['source' => $e[0], 'field' => $e[1], 'value' => $e[2]], $evidence),
            'mode' => 'suggestion_only',
        ];
    }
}
