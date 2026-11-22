import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { BusinessProfileComponent } from './business-profile.component';

describe('BusinessProfileComponent', () => {
  const setup = () => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    const f = TestBed.createComponent(BusinessProfileComponent);
    const http = TestBed.inject(HttpTestingController);
    f.detectChanges();
    return { f, http };
  };

  it('shows missing fields and invents no values for an empty profile', () => {
    const { f, http } = setup();
    http.expectOne('/api/v1/business-profile').flush({ success: true, data: null, missing_fields: ['currency'] });
    f.detectChanges();
    const c = f.componentInstance;
    expect(c.p.currency).toBeNull();
    expect(c.p.target_roas).toBeNull();
    expect(c.p.gross_margin_percent).toBeNull();
    expect((f.nativeElement as HTMLElement).querySelector('.bp-missing')?.textContent).toContain('currency');
  });

  it('uppercases currency on save and shows validation errors', () => {
    const { f, http } = setup();
    http.expectOne('/api/v1/business-profile').flush({ success: true, data: null, missing_fields: [] });
    f.componentInstance.p.currency = 'usd';
    f.componentInstance.save();
    const req = http.expectOne((r) => r.method === 'PUT');
    expect(req.request.body.currency).toBe('USD');
    req.flush({ message: 'bad', errors: { budget_cap: ['The budget cap period is required.'] } }, { status: 422, statusText: 'x' });
    expect(f.componentInstance.message()).toContain('budget cap period');
  });
});
