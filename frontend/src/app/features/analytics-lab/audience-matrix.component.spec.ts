import {TestBed} from '@angular/core/testing';
import {provideHttpClient} from '@angular/common/http';
import {provideHttpClientTesting,HttpTestingController} from '@angular/common/http/testing';
import {AudienceMatrixComponent} from './audience-matrix.component';
describe('Audience matrix boundaries',()=>{
 let http:HttpTestingController;
 beforeEach(()=>{TestBed.configureTestingModule({imports:[AudienceMatrixComponent],providers:[provideHttpClient(),provideHttpClientTesting()]});http=TestBed.inject(HttpTestingController);});afterEach(()=>http.verify());
 it('does not fetch or guess account',()=>{const f=TestBed.createComponent(AudienceMatrixComponent);f.detectChanges();http.expectNone(()=>true);f.componentInstance.load();expect(f.componentInstance.result().status).toBe('invalid_input');http.expectNone(()=>true);});
 it('discards old scope responses and preserves null versus zero',()=>{const f=TestBed.createComponent(AudienceMatrixComponent);const c=f.componentInstance;c.account.set(1);c.load();const r=http.expectOne('/api/v1/analytics/audience-matrix?account_id=1&entity_level=campaign&days=30');c.clear();r.flush({data:{rows:[{frequency:9}]}});expect(c.result()).toBeNull();c.result.set({status:'ok',provider:'meta',currency:'USD',timezone:'UTC',window:{start:'a',end:'b'},row_count:2,rows:[{entity_id:1,date:'a',reach:null,frequency:null,ctr:null,cpa:null,spend:0,conversions:0},{entity_id:1,date:'b',reach:0,frequency:0,ctr:0,cpa:null,spend:0,conversions:0}]});f.detectChanges();expect(c.plottable().length).toBe(1);expect(f.nativeElement.textContent).toContain('Unknown');expect(f.nativeElement.textContent).toContain('Undefined');expect(f.nativeElement.querySelectorAll('circle').length).toBe(1);});
});
