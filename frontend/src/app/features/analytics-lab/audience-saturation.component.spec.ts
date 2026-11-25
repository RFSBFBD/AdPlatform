import {TestBed} from '@angular/core/testing';
import {provideHttpClient} from '@angular/common/http';
import {provideHttpClientTesting,HttpTestingController} from '@angular/common/http/testing';
import {AudienceSaturationComponent} from './audience-saturation.component';
describe('Audience saturation view',()=>{
 let http:HttpTestingController;
 beforeEach(()=>{TestBed.configureTestingModule({imports:[AudienceSaturationComponent],providers:[provideHttpClient(),provideHttpClientTesting()]});http=TestBed.inject(HttpTestingController);});afterEach(()=>http.verify());
 it('does not fetch without explicit ids',()=>{const f=TestBed.createComponent(AudienceSaturationComponent);f.detectChanges();f.componentInstance.load();expect(f.componentInstance.result().status).toBe('invalid_input');http.expectNone(()=>true);});
 it('shows estimate, interval and the causal limitation; refusals state no threshold',()=>{const f=TestBed.createComponent(AudienceSaturationComponent);const c=f.componentInstance;c.account.set(1);c.entity.set(2);c.load();const r=http.expectOne(q=>q.url==='/api/v1/analytics/audience-saturation'&&q.params.get('entity_id')==='2'&&q.params.get('days')==='60');r.flush({data:{status:'ok',saturation_threshold:2.5,interval:{low:2.35,high:2.575},label:'ESTIMATED level; not a proven cause.',usable_days:40,deterioration_share:1,limits:['observational association, not a causal effect of frequency']}});f.detectChanges();const t=f.nativeElement.textContent;expect(t).toContain('Estimated threshold: frequency 2.50');expect(t).toContain('2.35 to 2.58');expect(t).toContain('not a causal interval');expect(t).toContain('not a causal effect');c.result.set({status:'insufficient_data',reason:'At least 21 usable days are required.',saturation_threshold:null,interval:null});f.detectChanges();expect(f.nativeElement.textContent).toContain('No saturation threshold is reported.');});
 it('discards stale responses after scope change',()=>{const f=TestBed.createComponent(AudienceSaturationComponent);const c=f.componentInstance;c.account.set(1);c.entity.set(2);c.load();const r=http.expectOne(()=>true);c.clear();r.flush({data:{status:'ok'}});expect(c.result()).toBeNull();});
});
