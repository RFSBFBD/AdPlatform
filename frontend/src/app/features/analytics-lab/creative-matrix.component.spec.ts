import {TestBed} from '@angular/core/testing';
import {provideHttpClient} from '@angular/common/http';
import {provideHttpClientTesting,HttpTestingController} from '@angular/common/http/testing';
import {CreativeMatrixComponent} from './creative-matrix.component';
describe('Creative proxy matrix boundaries',()=>{
 let http:HttpTestingController;beforeEach(()=>{TestBed.configureTestingModule({imports:[CreativeMatrixComponent],providers:[provideHttpClient(),provideHttpClientTesting()]});http=TestBed.inject(HttpTestingController);});afterEach(()=>http.verify());
 it('does not guess account or fetch before request',()=>{const f=TestBed.createComponent(CreativeMatrixComponent);f.detectChanges();f.componentInstance.load();expect(f.componentInstance.result().status).toBe('invalid_input');http.expectNone(()=>true);});
 it('discards a stale account response and renders abstention without healthy substitution',()=>{const f=TestBed.createComponent(CreativeMatrixComponent);const c=f.componentInstance;c.account.set(1);c.load();const r=http.expectOne('/api/v1/analytics/creative-matrix?account_id=1');c.clear();r.flush({data:{status:'ok',rows:[]}});expect(c.result()).toBeNull();c.result.set({status:'ok',window:['a','b'],rows:[{ad_id:1,ad_name:'Same name',status:'insufficient_data',reason:'No frequency'},{ad_id:2,ad_name:'Same name',status:'insufficient_data',reason:'No history'}]});f.detectChanges();expect(f.nativeElement.querySelectorAll('tbody tr').length).toBe(2);expect(f.nativeElement.textContent).toContain('No frequency');});
});
