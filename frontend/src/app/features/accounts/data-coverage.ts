export interface CoverageAccount {
  id: number; name: string | null; currency: string | null; timezone: string | null;
  last_synced_at: string | null; last_sync_status: string | null; last_sync_error: string | null; days_with_data: number;
}
export interface CoverageTotals { spend: number; conversion_value: number; impressions: number; clicks: number; conversions: number; }
export interface CoveragePlatform {
  provider: 'meta' | 'google' | 'tiktok' | 'snapchat';
  status: 'not_connected' | 'connected_no_data' | 'data_available';
  accounts: CoverageAccount[];
  days_with_data: number; first_date: string | null; last_date: string | null;
  totals_by_currency: Record<string, CoverageTotals>;
  has_conversion_value: boolean; has_campaign_level_rows: boolean; gaps: string[];
}
export interface CoverageResponse { start_date: string; end_date: string; platforms: CoveragePlatform[]; note: string; }

export const PROVIDER_LABEL: Record<CoveragePlatform['provider'], string> = {
  meta: 'Meta Ads', google: 'Google Ads', tiktok: 'TikTok Ads', snapchat: 'Snapchat Ads',
};

export const STATUS_LABEL: Record<CoveragePlatform['status'], string> = {
  not_connected: 'Not connected',
  connected_no_data: 'Connected, no data pulled',
  data_available: 'Data pulled',
};

/** Money is shown per currency and never summed across currencies. */
export function formatTotals(t: Record<string, CoverageTotals>): { currency: string; spend: string; value: string }[] {
  return Object.keys(t).sort().map((c) => ({
    currency: c,
    spend: t[c].spend.toFixed(2),
    value: t[c].conversion_value > 0 ? t[c].conversion_value.toFixed(2) : 'none',
  }));
}

/** Last sync line: states what is known, never invents a time. */
export function syncLine(a: CoverageAccount): string {
  if (a.last_sync_status === 'failed') return `Last sync failed${a.last_sync_error ? ': ' + a.last_sync_error : ''}`;
  if (!a.last_synced_at) return 'Never synced';
  return `Last synced ${a.last_synced_at.slice(0, 16).replace('T', ' ')} UTC`;
}
