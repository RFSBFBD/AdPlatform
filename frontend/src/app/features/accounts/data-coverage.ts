export interface SyncProvenance { stored_rows:number; row_provenance:string; last_attempt_status:string|null; last_successful_sync_at:string|null; legacy_sync_timestamp:string|null; success_basis:string; }
export function provenanceLine(p:SyncProvenance):string {
 const d=(v:string)=>new Date(v).toISOString().slice(0,16).replace('T',' ')+' UTC';
 const success=p.last_successful_sync_at?'Last successful sync '+d(p.last_successful_sync_at):'No completed sync audit';
 const attempt=p.last_attempt_status && p.last_attempt_status!=='completed'?'; latest attempt '+p.last_attempt_status:'';
 const rows=p.stored_rows>0?'; '+(p.row_provenance==='provider_lineage_recorded'?'stored rows have provider lineage':p.row_provenance==='unverified_import_or_seed'?'stored rows have no provider-pull lineage (import/seed unverified)':'stored rows have mixed or partial lineage'):'';
 const legacy=!p.last_successful_sync_at&&p.legacy_sync_timestamp?'; legacy timestamp '+d(p.legacy_sync_timestamp)+' is not a completed audit':'';
 return success+attempt+rows+legacy;
}
export interface CoverageAccount {
  sync_provenance?:SyncProvenance; id: number; name: string | null; currency: string | null; timezone: string | null;
  last_synced_at: string | null; last_sync_status: string | null; last_sync_error: string | null; days_with_data: number;
}
export interface CoverageTotals { spend: number | null; conversion_value: number | null; impressions: number | null; clicks: number | null; conversions: number | null; }
export interface CoveragePlatform {
  provider: 'meta' | 'google' | 'tiktok' | 'snapchat' | 'taboola' | 'pinterest' | 'reddit' | 'spotify' | 'linkedin' | 'microsoft' | 'amazon' | 'x' | 'apple' | 'outbrain' | 'walmart';
  status: 'not_connected' | 'connected_no_data' | 'data_available';
  accounts: CoverageAccount[];
  days_with_data: number; first_date: string | null; last_date: string | null;
  totals_by_currency: Record<string, CoverageTotals>;
  recorded_arithmetic_by_currency?: Record<string, {metrics:Record<string,{decimal:string|null}>,arithmetic:string,source_precision:string,population?:string,finality?:string}>;
  has_conversion_value: boolean; has_campaign_level_rows: boolean; gaps: string[];
}
export interface CoverageResponse { start_date: string; end_date: string; platforms: CoveragePlatform[]; note: string; }

export const PROVIDER_LABEL: Record<CoveragePlatform['provider'], string> = {
  meta: 'Meta Ads', google: 'Google Ads', tiktok: 'TikTok Ads', snapchat: 'Snapchat Ads',
  taboola:'Taboola Ads',pinterest:'Pinterest Ads',reddit:'Reddit Ads',spotify:'Spotify Ads',linkedin:'LinkedIn Ads',microsoft:'Microsoft Advertising',amazon:'Amazon Ads',x:'X Ads',apple:'Apple Search Ads',outbrain:'Outbrain',walmart:'Walmart Connect',
};

export const STATUS_LABEL: Record<CoveragePlatform['status'], string> = {
  not_connected: 'Not connected',
  connected_no_data: 'Connected, no data pulled',
  data_available: 'Stored report rows',
};

/** Money is shown per currency and never summed across currencies. */
export function formatTotals(t: Record<string, CoverageTotals>): { currency: string; spend: string; value: string }[] {
  return Object.keys(t).sort().map((c) => ({
    currency: c,
    spend: t[c].spend == null ? 'unknown' : t[c].spend.toFixed(2),
    value: t[c].conversion_value == null ? 'unknown' : t[c].conversion_value.toFixed(2),
  }));
}

/** Last sync line: states what is known, never invents a time. */
export function syncLine(a: CoverageAccount): string {
  if(a.sync_provenance)return provenanceLine(a.sync_provenance);
  if (a.last_sync_status === 'failed') return `Last sync failed${a.last_sync_error ? ': ' + a.last_sync_error : ''}`;
  if (!a.last_synced_at) return 'No successful sync timestamp recorded';
  return `Last synced ${a.last_synced_at.slice(0, 16).replace('T', ' ')} UTC`;
}

/** Source-preserving display never falls back to rounded legacy coverage totals. */
export function formatRecordedTotals(p: Pick<CoveragePlatform,'recorded_arithmetic_by_currency'>): {currency:string;spend:string;value:string;note:string}[] {
  return Object.entries(p.recorded_arithmetic_by_currency??{}).sort(([a],[b])=>a.localeCompare(b)).map(([currency,r])=>({
    currency,spend:r.metrics['spend']?.decimal??'unknown',value:r.metrics['conversion_value']?.decimal??'unknown',
    note:r.arithmetic==='unavailable_original_source_integrity'?'Current original source is unavailable or failed revalidation. Legacy values are not substituted.':r.arithmetic==='exact_over_original_reported_delivery_operands'?'Original delivery tokens, returned campaign/date rows only. Not complete inventory or final billing. Canonical outcome value unavailable.':'Exact stored operands, original source precision not verified. Provider-reported outcome value is not verified sales.'
  }));
}

export function elapsedSince(iso:string,now:number):string {
 const t=Date.parse(iso);
 if(!Number.isFinite(t))return 'unknown';
 const sec=Math.floor((now-t)/1000);
 if(sec<0)return 'timestamp is in the future (clock mismatch)';
 if(sec<60)return 'less than 1 minute';
 const min=Math.floor(sec/60);
 if(min<60)return min+(min===1?' minute':' minutes');
 const h=Math.floor(min/60);
 if(h<48)return h+(h===1?' hour':' hours');
 const d=Math.floor(h/24);
 return d+' days';
}
export interface FreshnessRow { provider:string; label:string; account:string; state:'synced'|'no_completed_audit'; text:string; note:string; }
/** Spec section 65: last successful sync per connected account. No delay threshold is invented: age is shown, not judged. */
export function freshnessRows(c:CoverageResponse|null|undefined,now:number):FreshnessRow[] {
 const out:FreshnessRow[]=[];
 for(const p of c?.platforms??[]){
  if(p.status==='not_connected')continue;
  for(const a of p.accounts??[]){
   const at=a.sync_provenance?.last_successful_sync_at??null;
   const label=PROVIDER_LABEL[p.provider]??p.provider;
   const name=a.name||('Account #'+a.id);
   if(at){out.push({provider:p.provider,label,account:name,state:'synced',text:'Last successful sync '+elapsedSince(at,now)+' ago',note:'Provider-side reporting may lag or change after this time.'});}
   else{out.push({provider:p.provider,label,account:name,state:'no_completed_audit',text:'No completed sync recorded',note:'Stored rows, if any, are not proof of a provider pull.'});}
  }
 }
 return out;
}
