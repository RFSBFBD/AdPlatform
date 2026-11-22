import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { OperationsResearchComponent } from './operations-research.component';

describe('OR and suggestions come from one server snapshot', () => {
  beforeEach(() => TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] }));
  const sug = (title: string) => ({ kind: 'data', platform: 'meta', severity: 'info', title, text: title, evidence: [], mode: 'suggestion_only' });

  it('ignores an older OR response that arrives after a newer solve (result, suggestions and snapshot)', () => {
    const f = TestBed.createComponent(OperationsResearchComponent), c = f.componentInstance, h = TestBed.inject(HttpTestingController);
    c.targetBudget.set(100); c.horizon.set('per_30_days'); c.saturation.set(2); c.solveOptimization();
    const old = h.expectOne(r => r.url.includes('budget=100&'));
    c.targetBudget.set(200); c.solveOptimization();
    const current = h.expectOne(r => r.url.includes('budget=200&'));
    current.flush({ success: true, data: { status: 'infeasible', status_message: 'new' }, suggestions: [sug('new')], snapshot_id: 'S2' });
    old.flush({ success: true, data: { status: 'infeasible', status_message: 'old' }, suggestions: [sug('old')], snapshot_id: 'S1' });
    expect(c.data()?.status_message).toBe('new');
    expect(c.sugItems()[0].title).toBe('new');
    expect(c.snapshotId()).toBe('S2');
  });

  it('shows suggestions from the same response with no second request, and hides them when inputs change', () => {
    const f = TestBed.createComponent(OperationsResearchComponent), c = f.componentInstance, h = TestBed.inject(HttpTestingController);
    f.detectChanges();
    h.expectOne(r => r.url.startsWith('/api/v1/analytics/operations-research')).flush({ success: true, data: { status: 'infeasible', status_message: 'x', channel_allocations: [] }, suggestions: [sug('a')], snapshot_id: 'S1' });
    f.detectChanges();
    h.expectNone(r => r.url.startsWith('/api/v1/analytics/suggestions'));
    expect(f.nativeElement.querySelector('app-suggestions')).not.toBeNull();
    c.targetBudget.set(999); f.detectChanges();
    expect(f.nativeElement.querySelector('app-suggestions')).toBeNull();
  });
});
