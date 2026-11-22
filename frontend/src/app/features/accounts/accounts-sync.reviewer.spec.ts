import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { AccountsFeatureComponent } from './accounts.component';

describe('Independent sync honesty regression', () => {
  function setup() {
    TestBed.configureTestingModule({providers:[provideHttpClient(),provideHttpClientTesting()]});
    const f=TestBed.createComponent(AccountsFeatureComponent);
    return {f,c:f.componentInstance,http:TestBed.inject(HttpTestingController)};
  }
  const account:any={id:19,provider:'snapchat',account_name:'Test'};
  it('preserves a real completed count of zero',()=>{
    const {c,http}=setup();
    c.triggerDynamicSync(account);
    http.expectOne('/api/v1/ad-accounts/19/sync').flush({sync_run:{status:'completed',records_processed:0}});
    expect(c.liveRecordsCount()).toBe(0);
  });
  it('does not report a failed sync run as completed',()=>{
    const {c,http}=setup();
    c.triggerDynamicSync(account);
    http.expectOne('/api/v1/ad-accounts/19/sync').flush({sync_run:{status:'failed',records_processed:0,error_message:'Access denied'}});
    expect(c.currentSyncStage()).not.toContain('Ingestion Complete');
    expect(c.syncLogs().some((l:any)=>l.message.includes('Access denied'))).toBe(true);
  });
});
