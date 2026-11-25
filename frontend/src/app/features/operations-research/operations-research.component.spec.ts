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

  it('does not preselect or send an objective or outcome basis on initial load', () => {
    const { f, http } = setup();
    const req = http.expectOne(r => r.url.startsWith('/api/v1/analytics/operations-research'));
    const q = new URLSearchParams(req.request.url.split('?')[1]);
    expect(q.has('objective')).toBe(false); expect(q.has('outcome_basis')).toBe(false);
    req.flush({success:true,data:{status:'objective_basis_required',status_message:'Select objective and basis',recommendations:[],channel_allocations:[]}});
    f.detectChanges();
    expect((f.nativeElement as HTMLElement).querySelector<HTMLSelectElement>('#or_objective')?.selectedIndex).toBe(0);
    expect((f.nativeElement as HTMLElement).querySelector<HTMLSelectElement>('#or_basis')?.selectedIndex).toBe(0);
  });

  it('sends explicit selection and hides a stored result when its basis changes', () => {
    const { f, http } = setup();
    http.expectOne(r => r.url.startsWith('/api/v1/analytics/operations-research')).flush({success:true,data:{status:'objective_basis_required',recommendations:[],channel_allocations:[]}});
    const c=f.componentInstance;
    c.objective.set('MAXIMIZE_REPORTED_VALUE'); c.outcomeBasis.set('platform_reported_credit');
    c.solveOptimization();
    const req=http.expectOne(r=>r.url.startsWith('/api/v1/analytics/operations-research'));
    const q=new URLSearchParams(req.request.url.split('?')[1]);
    expect(q.get('objective')).toBe('MAXIMIZE_REPORTED_VALUE');expect(q.get('outcome_basis')).toBe('platform_reported_credit');
    req.flush({success:true,data:{status:'incomparable_metric_definitions',recommendations:[],channel_allocations:[]}});
    expect(c.data()).not.toBeNull();c.outcomeBasis.set('common_business_events');expect(c.data()).toBeNull();
  });

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

  it('keeps budget bar amounts readable outside the bar track', () => {
    const { f, http } = setup();
    flushFirst(http);
    const c = f.componentInstance as any;
    c.targetBudget.set(9000); c.horizon.set('per_30_days'); c.saturation.set(2);
    c.solveOptimization();
    const alloc = (channel: string, display_name: string, cur: number, opt: number, action: string, pct: number) => ({
      channel, display_name, current_spend: cur, current_revenue: cur * 2, current_roas: 2,
      optimal_spend: opt, optimal_revenue: opt * 2, optimal_roas: 2,
      delta_spend: opt - cur, delta_spend_percentage: pct, delta_vs_baseline: opt - cur,
      delta_vs_baseline_percentage: pct, lift_vs_baseline: 0,
      marginal_roas_current: 1.5, marginal_roas_optimal: 1.5, action,
    });
    http.expectOne((r) => r.url.startsWith('/api/v1/analytics/operations-research')).flush({
      success: true, data: {
        status: 'ok', total_budget: 9000, current_total_revenue: 21000, current_blended_roas: 2.33,
        optimal_total_revenue: 21395, optimal_blended_roas: 2.38, revenue_gain_delta: 395,
        roas_gain_delta: 0.05, revenue_gain_percentage: 1.88, equilibrium_lambda: 1.587,
        channel_allocations: [
          alloc('meta', 'Meta Ads', 3000, 4169, 'SCALE_UP', 39),
          alloc('google', 'Google Ads', 6000, 4831, 'SCALE_DOWN', -19.5),
        ],
        recommendations: [], frontier_curve: [], sensitivity_points: {}, mathematical_model: 'x',
        comparison: { observed_spend: 9000, observed_revenue_on_curve: 21000, reference_spend: 9000, baseline_revenue_same_spend: 21000, baseline_roas_same_spend: 2.33, reallocation_gain: 395, spend_change_vs_observed: 0, revenue_change_vs_observed: 395, definition: 'x' },
      },
    });
    f.detectChanges();
    const el: HTMLElement = f.nativeElement;
    const values = el.querySelectorAll('.bar-value');
    expect(values.length).toBe(4);
    // Amounts must live outside the colored bar track so they stay readable at any bar width.
    expect(el.querySelector('.bar-track .bar-value')).toBeNull();
    values.forEach((v) => expect(v.parentElement!.classList.contains('bar-row')).toBe(true));
    expect(el.querySelector('.bars-container')!.textContent).toContain('4,169');
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

describe('OperationsResearchComponent strengthened OR surface', () => {
  const setup = () => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    const f = TestBed.createComponent(OperationsResearchComponent);
    const http = TestBed.inject(HttpTestingController);
    f.detectChanges();
    return { f, http };
  };
  const flush = (http: HttpTestingController, data: any) =>
    http.expectOne((r) => r.url.startsWith('/api/v1/analytics/operations-research')).flush({ success: true, data });

  const okPayload = (extra: any = {}) => Object.assign({
    status: 'ok', total_budget: 3000, current_total_revenue: 5748, current_blended_roas: 0.34,
    optimal_total_revenue: 3596, optimal_blended_roas: 1.2, revenue_gain_delta: 9.2, roas_gain_delta: 0,
    revenue_gain_percentage: 0.26, equilibrium_lambda: 0.405,
    channel_allocations: [{ channel: 'meta', display_name: 'Meta', current_spend: 1600, current_revenue: 1950, current_roas: 1.2,
      optimal_spend: 1597, optimal_revenue: 1947, optimal_roas: 1.2, delta_spend: -3, delta_spend_percentage: -0.2,
      delta_vs_baseline: 10, delta_vs_baseline_percentage: 0.8, lift_vs_baseline: 5, marginal_roas_current: 0.1,
      marginal_roas_optimal: 0.4, action: 'MAINTAIN', model: 'saturation', extrapolates_beyond_support: true }],
    recommendations: [], frontier_curve: [], sensitivity_points: {}, mathematical_model: 'fitted',
  }, extra);

  it('sends curve_mode and omits saturation when fitted', () => {
    const { f, http } = setup();
    flush(http, { status: 'constraints_required', status_message: 'x', recommendations: [], channel_allocations: [] });
    const c = f.componentInstance as any;
    expect(c.curveMode()).toBe('fitted');
    c.targetBudget.set(1000); c.horizon.set('per_30_days'); c.saturation.set(3);
    c.solveOptimization();
    const req = http.expectOne((r) => r.url.startsWith('/api/v1/analytics/operations-research'));
    const q = new URLSearchParams(req.request.url.split('?')[1]);
    expect(q.get('curve_mode')).toBe('fitted');
    expect(q.get('saturation_multiplier')).toBeNull();
    c.curveMode.set('assumed');
    c.solveOptimization();
    const req2 = http.expectOne((r) => r.url.startsWith('/api/v1/analytics/operations-research'));
    const q2 = new URLSearchParams(req2.request.url.split('?')[1]);
    expect(q2.get('curve_mode')).toBe('assumed');
    expect(q2.get('saturation_multiplier')).toBe('3');
  });

  it('renders structured recommendations with an explicit confidence reason instead of a number', () => {
    const { f, http } = setup();
    flush(http, okPayload({
      structured_recommendations: [{
        id: 'abc123', type: 'BUDGET_REALLOCATION', entity: { platform: 'meta', campaign_id: null },
        severity: 'MEDIUM', observed: { metric: 'marginal_roas', value: 0.1 },
        baseline: { metric: 'spend_same_budget', value: 1200 }, scenario: { metric: 'spend', value: 1387.2 }, change_percent: 15.6, change_vs_actual_percent: -13.3,
        model: { name: 'fitted:log', version: '7.0.0' }, confidence: null,
        confidence_reason: 'No calibrated confidence for allocation shifts.', stability: 'stable',
        action: { type: 'SIMULATION_ONLY' }, title: 'Scenario: raise Meta', description: 'Scenario under the stated curve.',
        evidence: {},
      }],
    }));
    f.detectChanges();
    const el: HTMLElement = f.nativeElement;
    const rec = el.querySelector('.struct-recs [data-rec-id="abc123"]');
    expect(rec).not.toBeNull();
    expect(rec!.textContent).toContain('Simulation only');
    expect(rec!.textContent).toContain('Same-budget mix baseline 1,200');
    expect(rec!.textContent).toContain('Scenario spend 1,387.2');
    expect(rec!.textContent).toContain('change vs same-budget baseline 15.6%');
    expect(rec!.textContent).toContain('Change vs ACTUAL spend -13.3%');
    expect(rec!.textContent).toContain('Confidence: not available');
    expect(rec!.textContent).toContain('No calibrated confidence for allocation shifts.');
  });

  it('renders stress ranges labeled as not a prediction interval', () => {
    const { f, http } = setup();
    flush(http, okPayload({
      stress: { status: 'ok', label: 'Deterministic stress range. Not a prediction interval; no confidence level applies.',
        channels: [{ channel: 'meta', spend: 1597, expected: 1947, best: 1997, worst: 1896, u_source: 'fitted model validation relative RMSE', u: 0.026 }] },
    }));
    f.detectChanges();
    const el: HTMLElement = f.nativeElement;
    const card = el.querySelector('.stress-card');
    expect(card).not.toBeNull();
    expect(card!.textContent).toContain('Not a prediction interval');
    expect(card!.textContent).toContain('1,997');
  });

  it('shows the stability label without any probabilistic claim', () => {
    const { f, http } = setup();
    flush(http, okPayload({
      sensitivity: { status: 'ok', perturbation_percent: 10, max_share_shift_pp: 2.8, stability: 'stable',
        thresholds_pp: { stable_below: 10, moderate_below: 30 },
        label: 'Deterministic sensitivity. Not a probability, not a confidence statement.' },
    }));
    f.detectChanges();
    const el: HTMLElement = f.nativeElement;
    const panel = el.querySelector('.sens-panel');
    expect(panel).not.toBeNull();
    expect(panel!.textContent).toContain('Stability: stable');
    expect(panel!.textContent).toContain('Not a probability');
  });

  it('shows the data-quality gate and blocked banner', () => {
    const { f, http } = setup();
    flush(http, {
      status: 'insufficient_data_quality', status_message: 'Data quality 40/100 is below the optimization threshold 50/100.',
      recommendations: [], channel_allocations: [],
      data_quality: {
        window: { start: '2026-10-25', end: '2026-11-22', days: 30 },
        channels: { meta: { score: 40, level: 'low', rubric_version: '1.0', checks: [
          { id: 'completeness', status: 'fail', deduction: 40, detail: 'Only 10 of 30 window days have data.' },
          { id: 'freshness', status: 'pass', deduction: 0, detail: 'ok' },
        ] } },
        portfolio: { score: 40, optimize_allowed: false, threshold: 50, gate_reason: 'below threshold' },
      },
    });
    f.detectChanges();
    const el: HTMLElement = f.nativeElement;
    expect(el.querySelector('.or-status')?.textContent).toContain('Data quality too low to optimize');
    const dq = el.querySelector('.dq-panel');
    expect(dq).not.toBeNull();
    expect(dq!.textContent).toContain('Optimization is blocked');
    expect(dq!.textContent).toContain('Only 10 of 30 window days have data.');
    expect(el.querySelector('.kpi-grid')).toBeNull();
  });

  it('shows the reproducibility footer with the run id', () => {
    const { f, http } = setup();
    flush(http, okPayload({ run_id: 42, reproducibility: { run_id: 42, engine: 'adplatform-or', engine_version: '7.0.0', input_hash: 'a7939d83b1575e5c2d70e4640f9ff745', created_at: '2026-11-23T14:00:00+00:00' } }));
    f.detectChanges();
    const footer = (f.nativeElement as HTMLElement).querySelector('.repro-footer');
    expect(footer).not.toBeNull();
    expect(footer!.textContent).toContain('Run #42');
    expect(footer!.textContent).toContain('a7939d83b157');
  });

  it('labels observed vs scenario numbers', () => {
    const { f, http } = setup();
    flush(http, okPayload());
    f.detectChanges();
    const legend = (f.nativeElement as HTMLElement).querySelector('.typing-legend');
    expect(legend).not.toBeNull();
    expect(legend!.textContent).toContain('OBSERVED');
    expect(legend!.textContent).toContain('SCENARIO');
  });
});
