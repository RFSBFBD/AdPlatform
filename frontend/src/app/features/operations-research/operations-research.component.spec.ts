import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { OperationsResearchComponent } from './operations-research.component';

describe('OperationsResearchComponent honesty', () => {
  const setup = () => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    const f = TestBed.createComponent(OperationsResearchComponent);
    const http = TestBed.inject(HttpTestingController);
    f.detectChanges();
    return { f, http };
  };

  it('shows no KPIs or recommendations on insufficient data', () => {
    const { f, http } = setup();
    http.expectOne((r) => r.url.startsWith('/api/v1/analytics/operations-research')).flush({
      success: true,
      data: { status: 'insufficient_data', status_message: 'Need data', recommendations: [], channel_allocations: [] },
    });
    f.detectChanges();
    const el: HTMLElement = f.nativeElement;
    expect(el.querySelector('.kpi-grid')).toBeNull();
    expect(el.querySelector('.or-status')?.textContent).toContain('Need data');
  });

  it('shows an unavailable notice (not numbers) when the API returns HTML', () => {
    const { f, http } = setup();
    http.expectOne((r) => r.url.startsWith('/api/v1/analytics/operations-research')).flush('<!doctype html>');
    f.detectChanges();
    const el: HTMLElement = f.nativeElement;
    expect(el.querySelector('.kpi-grid')).toBeNull();
    expect(el.querySelector('.or-status')).not.toBeNull();
  });

  const flushFirst = (http: HttpTestingController) =>
    http.expectOne((r) => r.url.startsWith('/api/v1/analytics/operations-research')).flush({ success: true, data: { status: 'insufficient_data', status_message: 'x', recommendations: [], channel_allocations: [] } });

  it('blocks submit and shows errors when a channel min is above max', () => {
    const { f, http } = setup();
    flushFirst(http);
    const c = f.componentInstance as any;
    c.limitsHorizon.set('per_30_days');
    c.setLimit('meta', 'min', 500);
    c.setLimit('meta', 'max', 100);
    f.detectChanges();
    expect(c.validationErrors().join(' ')).toContain('meta: min is above max');
    expect((f.nativeElement as HTMLElement).querySelector('.or-validation')).not.toBeNull();
    c.solveOptimization();
    http.expectNone((r) => r.url.startsWith('/api/v1/analytics/operations-research'));
  });

  it('sends mode, min ROAS and per-channel limits with their period', () => {
    const { f, http } = setup();
    flushFirst(http);
    const c = f.componentInstance as any;
    c.targetBudget.set(1000); c.horizon.set('per_30_days'); c.saturation.set(3);
    c.mode.set('at_most'); c.minRoas.set(1.5);
    c.limitsHorizon.set('per_30_days');
    c.setLimit('meta', 'min', 100); c.setLimit('google', 'max', 700);
    c.solveOptimization();
    const req = http.expectOne((r) => r.url.startsWith('/api/v1/analytics/operations-research'));
    const q = new URLSearchParams(req.request.url.split('?')[1]);
    expect(q.get('budget_mode')).toBe('at_most');
    expect(q.get('min_marginal_roas')).toBe('1.5');
    expect(q.get('channel_limits_horizon')).toBe('per_30_days');
    expect(q.get('channel_limits[meta][min]')).toBe('100');
    expect(q.get('channel_limits[google][max]')).toBe('700');
    expect(q.get('channel_limits[tiktok][min]')).toBeNull();
  });

  it('blocks min ROAS with exact or unset mode and never sends it', () => {
    const { f, http } = setup();
    flushFirst(http);
    const c = f.componentInstance as any;
    c.mode.set('exact'); c.minRoas.set(1.2);
    expect(c.validationErrors().join(' ')).toContain('used only with budget mode');
    c.solveOptimization();
    http.expectNone((r) => r.url.startsWith('/api/v1/analytics/operations-research'));
    c.mode.set('at_most'); c.minRoas.set(null);
    expect(c.validationErrors().join(' ')).toContain('needs a minimum marginal ROAS');
  });

  it('hides the old result as soon as any input changes, even an invalid one', () => {
    const { f, http } = setup();
    http.expectOne((r) => r.url.startsWith('/api/v1/analytics/operations-research')).flush({
      success: true, data: { status: 'infeasible', status_message: 'old result', recommendations: [], channel_allocations: [] },
    });
    f.detectChanges();
    const el: HTMLElement = f.nativeElement;
    expect(el.querySelector('.or-status')?.textContent).toContain('old result');
    (f.componentInstance as any).limitsHorizon.set('daily');
    (f.componentInstance as any).setLimit('meta', 'min', -5);
    f.detectChanges();
    expect(el.querySelector('.or-status')).toBeNull();
    expect(el.querySelector('.or-stale')?.textContent).toContain('Inputs changed');
  });

  it('shows no_allocation clearly and not as a success', () => {
    const { f, http } = setup();
    http.expectOne((r) => r.url.startsWith('/api/v1/analytics/operations-research')).flush({
      success: true, data: { status: 'no_allocation', status_message: 'nothing clears the floor', recommendations: [], channel_allocations: [] },
    });
    f.detectChanges();
    const el: HTMLElement = f.nativeElement;
    expect(el.querySelector('.kpi-grid')).toBeNull();
    expect(el.querySelector('.or-status')?.textContent).toContain('No allocation');
  });

  it('says a channel minimum exceeds the budget', () => {
    const { f, http } = setup();
    flushFirst(http);
    const c = f.componentInstance as any;
    c.targetBudget.set(1000); c.horizon.set('per_30_days'); c.limitsHorizon.set('per_30_days');
    c.setLimit('meta', 'min', 5000);
    expect(c.validationErrors().join(' ')).toContain('meta: minimum exceeds the budget');
  });
});
