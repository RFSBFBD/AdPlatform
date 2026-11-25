import {TestBed} from '@angular/core/testing';
import {provideHttpClient} from '@angular/common/http';
import {provideHttpClientTesting,HttpTestingController} from '@angular/common/http/testing';
import {PlatformComparisonComponent} from './platform-comparison.component';
describe('Platform comparability view',()=>{
 let http:HttpTestingController;
 beforeEach(()=>{TestBed.configureTestingModule({imports:[PlatformComparisonComponent],providers:[provideHttpClient(),provideHttpClientTesting()]});http=TestBed.inject(HttpTestingController);});afterEach(()=>http.verify());
 it('rejects bad or duplicate ids without fetching',()=>{const f=TestBed.createComponent(PlatformComparisonComponent);const c=f.componentInstance;for(const v of ['','a','1,1','0','1.5']){c.ids.set(v);c.load();expect(c.result().status).toBe('invalid_input');}http.expectNone(()=>true);});
 it('shows blockers and never a ranking',()=>{const f=TestBed.createComponent(PlatformComparisonComponent);const c=f.componentInstance;c.ids.set('1, 2');c.load();const r=http.expectOne(q=>q.url==='/api/v1/analytics/platform-comparison'&&q.params.getAll('account_ids[]')!.join()==='1,2');r.flush({data:{status:'ok',ranking:null,platforms:[{account_id:1,provider:'meta',currency:'USD',spend:'100.0000',conversions:'5.0000',window:{start:'a',end:'b',timezone:'UTC'},definition:{conversion:null,attribution:null}}],excluded:[],pairs:[{accounts:[1,2],verdict:'not_comparable',blockers:['currency: different','objective: unknown'],allowed_use:'Read each platform on its own definition.'}],note:'No platform is ranked.'}});f.detectChanges();const t=f.nativeElement.textContent;expect(t).toContain('Not comparable');expect(t).toContain('currency: different');expect(t).toContain('Unknown');expect(t.toLowerCase()).not.toContain('best');});
});
