import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideRouter } from '@angular/router';
import {provideHttpClientTesting,HttpTestingController} from '@angular/common/http/testing';
import { DashboardFeatureComponent } from './dashboard.component';

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

});
