import { formatTotals, syncLine, STATUS_LABEL } from './data-coverage';

describe('data coverage helpers', () => {
  it('keeps currencies separate and shows missing purchase value as none', () => {
    const r = formatTotals({
      USD: { spend: 10, conversion_value: 40, impressions: 1, clicks: 1, conversions: 1 },
      EUR: { spend: 7, conversion_value: 0, impressions: 1, clicks: 1, conversions: 0 },
    });
    expect(r).toEqual([
      { currency: 'EUR', spend: '7.00', value: 'none' },
      { currency: 'USD', spend: '10.00', value: '40.00' },
    ]);
  });
  it('never invents a sync time', () => {
    const base = { id: 1, name: 'a', currency: 'USD', timezone: 'UTC', last_synced_at: null, last_sync_status: null, last_sync_error: null, days_with_data: 0 };
    expect(syncLine(base)).toBe('Never synced');
    expect(syncLine({ ...base, last_sync_status: 'failed', last_sync_error: 'token_expired' })).toBe('Last sync failed: token_expired');
    expect(syncLine({ ...base, last_synced_at: '2026-11-22T10:00:00+00:00' })).toBe('Last synced 2026-11-22 10:00 UTC');
  });
  it('labels all three states', () => {
    expect(Object.keys(STATUS_LABEL).length).toBe(3);
  });
});
