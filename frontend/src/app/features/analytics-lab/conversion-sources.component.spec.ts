import {TestBed} from '@angular/core/testing';
import {provideHttpClient} from '@angular/common/http';
import {provideHttpClientTesting,HttpTestingController} from '@angular/common/http/testing';
import {ConversionSourcesComponent} from './conversion-sources.component';
describe('Typed conversion source guards',()=>{
 let http:HttpTestingController;beforeEach(()=>{TestBed.configureTestingModule({imports:[ConversionSourcesComponent],providers:[provideHttpClient(),provideHttpClientTesting()]});http=TestBed.inject(HttpTestingController);});afterEach(()=>http.verify());
 it('does not preload customer data or call without explicit request',()=>{const f=TestBed.createComponent(ConversionSourcesComponent);f.detectChanges();expect(f.componentInstance.dailyText).toBe('');expect(f.componentInstance.definitionText).toBe('');http.expectNone(()=>true);});
 it('requires explicit account and definition and valid JSON',()=>{const c=TestBed.createComponent(ConversionSourcesComponent).componentInstance;c.importRows();expect(c.message()).toContain('account ID');c.account.set(1);c.importRows();expect(c.message()).toContain('definition ID');c.definitionId=2;c.dailyText='[]';c.importRows();expect(c.message()).toContain('JSON object');http.expectNone(()=>true);});
 it('ignores source result after account switch',()=>{const c=TestBed.createComponent(ConversionSourcesComponent).componentInstance;c.account.set(1);c.load();const r=http.expectOne('/api/v1/ad-accounts/1/conversion-definitions');c.account.set(2);r.flush({data:[{id:3}]});expect(c.definitions()).toEqual([]);});
});
