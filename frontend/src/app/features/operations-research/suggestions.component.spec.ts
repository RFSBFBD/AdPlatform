import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { SuggestionsComponent, Suggestion } from './suggestions.component';

const sug = (title: string): Suggestion => ({ kind: 'data', platform: 'meta', severity: 'info', title, text: title, evidence: [], mode: 'suggestion_only' });

describe('SuggestionsComponent (one snapshot)', () => {
  function setup() {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    const f = TestBed.createComponent(SuggestionsComponent);
    const c = f.componentInstance;
    c.items = [sug('A'), sug('B')]; c.snapshotId = 'snap1'; c.query = 'budget=100';
    c.ngOnChanges();
    return { f, c, http: TestBed.inject(HttpTestingController) };
  }
  it('shows the rule-based items with no request at all', () => {
    const { c, http } = setup();
    http.expectNone(() => true);
    expect(c.shown().map(s => s.title)).toEqual(['A', 'B']);
    expect(c.modeText()).toContain('rules only (no AI used)');
  });
  it('AI request carries the snapshot id and applies only a matching answer', () => {
    const { c, http } = setup();
    c.explainWithAi();
    const r = http.expectOne('/api/v1/analytics/suggestions?budget=100&ai=1&snapshot_id=snap1');
    r.flush({ success: true, data: { suggestions: [{ ...sug('B'), ai_rank: 1 }, { ...sug('A'), ai_rank: 2 }], mode: { requested: true, result: 'ai_ordered', reason: null, snapshot_id: 'snap1' }, grounding: { snapshot_id: 'snap1' } } });
    expect(c.shown()[0].title).toBe('B');
    expect(c.modeText()).toContain('only ordered the list');
  });
  it('an answer for a different snapshot is not shown', () => {
    const { c, http } = setup();
    c.explainWithAi();
    http.expectOne(() => true).flush({ success: true, data: { suggestions: [sug('X')], grounding: { snapshot_id: 'other' } } });
    expect(c.state()).toBe('error');
    expect(c.shown().map(s => s.title)).toEqual(['A', 'B']);
  });
  it('409 snapshot changed: AI not used, rules list kept, message shown', () => {
    const { f, c, http } = setup();
    c.explainWithAi();
    http.expectOne(() => true).flush({ success: false }, { status: 409, statusText: 'Conflict' });
    f.detectChanges();
    expect(c.state()).toBe('changed');
    expect(f.nativeElement.querySelector('[data-testid="sug-changed"]')).not.toBeNull();
    expect(c.shown().length).toBe(2);
  });
  it('a new snapshot resets AI and ignores a late answer to the old one', () => {
    const { c, http } = setup();
    c.explainWithAi();
    const old = http.expectOne(() => true);
    c.items = [sug('N')]; c.snapshotId = 'snap2'; c.ngOnChanges();
    old.flush({ success: true, data: { suggestions: [sug('OLD')], grounding: { snapshot_id: 'snap1' } } });
    expect(c.shown().map(s => s.title)).toEqual(['N']);
    expect(c.state()).toBe('idle');
  });
});
