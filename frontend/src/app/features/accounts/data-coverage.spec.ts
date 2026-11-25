import { formatRecordedTotals, formatTotals, syncLine, STATUS_LABEL, provenanceLine } from './data-coverage';

describe('data coverage helpers', () => {
  it('keeps currencies separate and distinguishes observed zero from unknown purchase value', () => {
    const r = formatTotals({
      USD: { spend: 10, conversion_value: 40, impressions: 1, clicks: 1, conversions: 1 },
      EUR: { spend: 7, conversion_value: 0, impressions: 1, clicks: 1, conversions: 0 },
    });
    expect(r).toEqual([
      { currency: 'EUR', spend: '7.00', value: '0.00' },
      { currency: 'USD', spend: '10.00', value: '40.00' },
    ]);
  });
  it('does not turn unavailable metrics into zeros',()=>{expect(formatTotals({USD:{spend:null,conversion_value:null,impressions:null,clicks:null,conversions:null}})).toEqual([{currency:'USD',spend:'unknown',value:'unknown'}]);});
  it('never invents a sync time', () => {
    const base = { id: 1, name: 'a', currency: 'USD', timezone: 'UTC', last_synced_at: null, last_sync_status: null, last_sync_error: null, days_with_data: 0 };
    expect(syncLine(base)).toBe('No successful sync timestamp recorded');
    expect(syncLine({ ...base, last_sync_status: 'failed', last_sync_error: 'token_expired' })).toBe('Last sync failed: token_expired');
    expect(syncLine({ ...base, last_synced_at: '2026-11-22T10:00:00+00:00' })).toBe('Last synced 2026-11-22 10:00 UTC');
  });
  it('labels all three states', () => {
    expect(Object.keys(STATUS_LABEL).length).toBe(3);
  });
  it('separates import rows, failed attempts and completed successful audit',()=>{
    const p={stored_rows:30,row_provenance:'unverified_import_or_seed',last_attempt_status:null,last_successful_sync_at:null,legacy_sync_timestamp:null,success_basis:'no_completed_sync_audit'};
    expect(provenanceLine(p)).toContain('No completed sync audit');expect(provenanceLine(p)).toContain('import/seed unverified');expect(provenanceLine({...p,last_attempt_status:'failed',last_successful_sync_at:'2026-11-21T08:00:00Z'})).toContain('Last successful sync 2026-11-21 08:00 UTC; latest attempt failed');expect(provenanceLine({...p,stored_rows:0})).not.toContain('stored rows');
  });
});

describe('recorded coverage display',()=>{
 it('preserves exact original operands and refuses rounded fallback',()=>{
  expect(formatRecordedTotals({})).toEqual([]);
  const p={recorded_arithmetic_by_currency:{EGP:{metrics:{spend:{decimal:'1.000001'},conversion_value:{decimal:null}},arithmetic:'exact_over_original_reported_delivery_operands',source_precision:'original'}}};
  expect(formatRecordedTotals(p)[0].spend).toBe('1.000001');expect(formatRecordedTotals(p)[0].value).toBe('unknown');expect(formatRecordedTotals(p)[0].note).toContain('Not complete inventory');
 });
 it('does not substitute legacy totals after original source revalidation fails',()=>{const p={recorded_arithmetic_by_currency:{EGP:{metrics:{spend:{decimal:null},conversion_value:{decimal:null}},arithmetic:'unavailable_original_source_integrity',source_precision:'failed'}}};const r=formatRecordedTotals(p)[0];expect(r.spend).toBe('unknown');expect(r.note).toContain('Legacy values are not substituted');});

});
