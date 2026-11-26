import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideRouter } from '@angular/router';
import {provideHttpClientTesting,HttpTestingController} from '@angular/common/http/testing';
import { DashboardFeatureComponent, dqView } from './dashboard.component';

describe('dashboard outcome estimands',()=>{
 const setup=()=>{TestBed.configureTestingModule({providers:[provideHttpClient(),provideHttpClientTesting(),provideRouter([])]});return TestBed.createComponent(DashboardFeatureComponent);};
 it('does not draw a zero-valued outcome curve for withheld observations',()=>{
  const f=setup();f.componentInstance.timeline.set([{date:'2026-11-01',spend:20,clicks:4,conversions:null,roas:null}]);f.componentInstance.selectedMetric.set('roas');expect(f.componentInstance.chartCurvePath()).toBe('');expect(f.componentInstance.chartAreaPath()).toBe('');expect(f.componentInstance.formatMetricValue(f.componentInstance.timeline()[0])).toBe('Unknown');
 });
 it('preserves additive spend and measured-zero delivery chart without claiming unique people',()=>{
  const f=setup();f.componentInstance.timeline.set([{date:'2026-11-01',spend:20,clicks:0,conversions:null,roas:null}]);expect(f.componentInstance.chartCurvePath()).not.toBe('');f.componentInstance.selectedMetric.set('clicks');expect(f.componentInstance.chartCurvePath()).not.toBe('');expect(f.componentInstance.formatMetricValue(f.componentInstance.timeline()[0])).toBe('0');
 });
 it('does not rank source ROAS when the portfolio outcome is withheld',()=>{
  const f=setup();f.componentInstance.outcomeReason.set('Different provider outcomes');f.componentInstance.platforms.set([{provider:'meta',spend:10,impressions:100,clicks:2,conversions:1,roas:4},{provider:'google',spend:10,impressions:100,clicks:2,conversions:1,roas:3}]);expect(f.componentInstance.getBestChannelInsight()).toContain('no cross-provider best-channel verdict');
 });
 it('keeps native strings separate from converted summary and ignores stale overview responses',()=>{
  const f=setup();const c=f.componentInstance;const http=TestBed.inject(HttpTestingController);
  c.fetchOverview();const first=http.expectOne(r=>r.url.includes('/overview'));c.setPlatform('google');const second=http.expectOne(r=>r.url.includes('/overview'));
  second.flush({summary:{spend:1},recorded_native_evidence:{groups:{'google/EGP':{recorded_arithmetic:{metrics:{spend:{decimal:'9007199254740993.123456789'}}}}}}});
  first.flush({summary:{spend:2},recorded_native_evidence:{groups:{old:{}}}});
  expect(c.summary().spend).toBe(1);expect(c.nativeValue(c.nativeGroups()[0].value,'spend')).toBe('9007199254740993.123456789');expect(c.nativeValue(c.nativeGroups()[0].value,'conversion_value')).toBe('unknown');http.verify();
 });

 it('shows the server reason on a 409 instead of zeros, and the Snapchat exclusion note on success',()=>{
  const f=setup();const c=f.componentInstance;const http=TestBed.inject(HttpTestingController);
  c.summary.set({spend:99} as any);
  c.fetchOverview();http.expectOne(r=>r.url.includes('/overview')).flush({success:false,message:'Reason from server'},{status:409,statusText:'Conflict'});
  expect(c.loadError()).toBe('Reason from server');expect(c.platforms()).toEqual([]);
  c.fetchOverview();http.expectOne(r=>r.url.includes('/overview')).flush({summary:{spend:1},platforms:[],timeline:[],excluded_providers:[{provider:'snapchat',reason:'Snapchat excluded note'}]});
  expect(c.loadError()).toBeNull();expect(c.excludedNote()).toBe('Snapchat excluded note');http.verify();
 });
 it('offers all 11 providers in the platform filter',()=>{
  const f=setup();expect(f.componentInstance.platformOptions.length).toBeGreaterThanOrEqual(11);
 });

 it('shows last successful sync per connected account and never a delay verdict',()=>{
  const c=setup().componentInstance;const http=TestBed.inject(HttpTestingController);
  const now=Date.now();const iso=(m:number)=>new Date(now-m*60000).toISOString();
  c.fetchFreshness();
  http.expectOne('/api/v1/analytics/data-coverage').flush({success:true,data:{start_date:'a',end_date:'b',note:'',platforms:[
   {provider:'meta',status:'data_available',accounts:[{id:1,name:'Meta A',sync_provenance:{last_successful_sync_at:iso(8)}}]},
   {provider:'google',status:'connected_no_data',accounts:[{id:2,name:null,sync_provenance:{last_successful_sync_at:null,legacy_sync_timestamp:iso(5)}}]},
   {provider:'tiktok',status:'not_connected',accounts:[]}]}});
  const rows=c.freshness();expect(rows.length).toBe(2);
  expect(rows[0].text).toMatch(/Last successful sync (8|9) minutes ago/);
  expect(rows[1].state).toBe('no_completed_audit');expect(rows[1].account).toBe('Account #2');
  expect(rows[1].text).toBe('No completed sync recorded');
  expect(JSON.stringify(rows)).not.toMatch(/delayed|stale|fresh enough/i);
  http.verify();
 });
 it('reports freshness as unknown on a failed request instead of implying current',()=>{
  const c=setup().componentInstance;const http=TestBed.inject(HttpTestingController);
  c.freshness.set([{provider:'meta',label:'Meta Ads',account:'x',state:'synced',text:'old',note:''}]);
  c.fetchFreshness();http.expectOne('/api/v1/analytics/data-coverage').flush({message:'x'},{status:500,statusText:'err'});
  expect(c.freshness()).toEqual([]);expect(c.freshnessError()).toContain('unknown');http.verify();
 });
 it('formats elapsed time without guessing on bad or future timestamps',async()=>{
  const m=await import('../accounts/data-coverage');const now=Date.parse('2026-11-25T12:00:00Z');
  expect(m.elapsedSince('2026-11-25T11:59:30Z',now)).toBe('less than 1 minute');
  expect(m.elapsedSince('2026-11-25T11:59:00Z',now)).toBe('1 minute');
  expect(m.elapsedSince('2026-11-25T06:00:00Z',now)).toBe('6 hours');
  expect(m.elapsedSince('2026-11-20T12:00:00Z',now)).toBe('5 days');
  expect(m.elapsedSince('nonsense',now)).toBe('unknown');
  expect(m.elapsedSince('2026-11-25T12:05:00Z',now)).toContain('future');
 });
 it('shows the server quality rubric as returned, including the gate, without computing a score',()=>{
  const c=setup().componentInstance;const http=TestBed.inject(HttpTestingController);
  c.fetchQuality();
  http.expectOne('/api/v1/analytics/data-quality').flush({success:true,data:{channels:{meta:{score:42.5,level:'low',rubric_version:'1.0',checks:[{id:'completeness',status:'fail',detail:'Only 3 of 30 window days have data'},{id:'currency',status:'pass',detail:'Single currency: EUR.'}]}},portfolio:{score:42.5,level:'low',optimize_allowed:false,threshold:50,gate_reason:'below threshold'},note:'Rubric note'}});
  const v=c.dq()!;expect(v.portfolioLine).toBe('Portfolio score 42.5/100 (low)');expect(v.gate).toBe('below threshold');
  expect(v.channels[0].issues).toEqual(['Only 3 of 30 window days have data']);expect(v.note).toBe('Rubric note');http.verify();
 });
 it('says no score when there is no channel data and no gate text is invented when allowed',()=>{
  expect(dqView({channels:[],portfolio:{score:null,level:'none',optimize_allowed:false,threshold:50,gate_reason:'No channel data at all.'}})!.portfolioLine).toContain('No quality score');
  expect(dqView({channels:{},portfolio:{score:90,level:'high',optimize_allowed:true,threshold:50,gate_reason:null}})!.gate).toBeNull();
  expect(dqView(null)).toBeNull();
 });
 it('reports quality as unknown on failure and never keeps an old value',()=>{
  const c=setup().componentInstance;const http=TestBed.inject(HttpTestingController);
  c.dq.set({portfolioLine:'old',gate:null,note:'',channels:[]});c.fetchQuality();
  http.expectOne('/api/v1/analytics/data-quality').flush({message:'x'},{status:500,statusText:'e'});
  expect(c.dq()).toBeNull();expect(c.dqError()).toContain('unknown');http.verify();
 });

 it('shows CPA from the server value and never turns withheld or undefined into zero',()=>{
  const c=setup().componentInstance;
  c.summary.set({spend:10,conversions:2,cpa:5} as any);expect(c.cpaValue()).toContain('5');expect(c.cpaValue()).not.toBe('Withheld');
  c.summary.set({spend:10,conversions:null,cpa:null} as any);c.outcomeReason.set('Different provider outcomes');
  expect(c.cpaValue()).toBe('Withheld');expect(c.cpaSubtext()).toContain('not comparable');
  c.outcomeReason.set(null);expect(c.cpaValue()).toBe('Not defined');expect(c.cpaSubtext()).toContain('undefined');
  c.summary.set({spend:10} as any);expect(c.cpaValue()).toBe('Not defined');
 });
});
