import { Component, Input, OnChanges, OnDestroy, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Subscription } from 'rxjs';

export interface Suggestion {
  kind: 'data' | 'budget' | 'or';
  platform: string | null;
  severity: 'info' | 'attention' | 'scenario';
  title: string;
  text: string;
  evidence: { source: 'pulled_data' | 'or_result'; field: string; value: string }[];
  mode: 'suggestion_only';
  ai_rank?: number;
  ai_note?: string;
}

export interface SuggestionMode {
  requested: boolean;
  result: 'deterministic' | 'deterministic_fallback' | 'ai_ordered';
  reason: string | null;
  snapshot_id: string;
}

export const MODE_LABEL: Record<SuggestionMode['result'], string> = {
  deterministic: 'Mode: rules only (no AI used)',
  deterministic_fallback: 'Mode: rules only. AI was requested but not used',
  ai_ordered: 'Mode: AI only ordered the list and picked a fixed note per item. All wording and numbers come from the rules',
};

/**
 * Shows the rule-based suggestions that arrived in the SAME server response as the OR result on screen (one snapshot).
 * The optional AI step is sent that snapshot_id; the server refuses (409) if the data or result changed, and nothing is shown for it.
 */
@Component({
  selector: 'app-suggestions',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="sug" role="region" aria-label="Suggestions">
      <strong>Suggestions (read-only)</strong>
      <p class="sug-note">Built only from the pulled data and the OR result above, in the same server snapshot. Nothing is applied to any campaign or budget.</p>
      <p class="sug-mode" data-testid="sug-mode">{{ modeText() }}. Snapshot {{ snapshotId }}</p>
      @if (state() === 'idle') {
        <button type="button" class="sug-ai" (click)="explainWithAi()">Order with AI</button>
        <span class="sug-note">Sends only the suggestion titles (no keys, no account IDs, no amounts) to the AI provider you configured. The AI can only reorder the list and choose a fixed note. It cannot write text or change numbers. Optional.</span>
      } @else if (state() === 'loading') {
        <p class="sug-note">Asking AI to order the list...</p>
      } @else if (state() === 'changed') {
        <p class="sug-error" data-testid="sug-changed">The data or the result changed since this one (for example a sync arrived). AI was not used. Solve again for a fresh result.</p>
      } @else if (state() === 'error') {
        <p class="sug-error">AI ordering failed. The rule-based list below is unchanged.</p>
      }
      @if (shown().length === 0) {
        <p class="sug-note">No suggestions: the pulled data and the OR result raise nothing.</p>
      } @else {
        @for (s of shown(); track s.title) {
          <div class="sug-item" [attr.data-severity]="s.severity">
            <div class="sug-title">{{ s.title }} <span class="sug-tag">{{ s.severity === 'scenario' ? 'scenario, assumed curve' : s.severity }}</span></div>
            <div>{{ s.text }}</div>
            @if (s.ai_note) { <div class="sug-ai-text"><span class="sug-tag">Fixed note, AI-selected</span> {{ s.ai_note }}</div> }
            <details>
              <summary>Evidence</summary>
              @for (e of s.evidence; track e.field) {
                <div class="sug-ev">{{ e.source === 'or_result' ? 'OR result' : 'Pulled data' }}: {{ e.field }} = {{ e.value }}</div>
              }
            </details>
          </div>
        }
      }
    </div>
  `,
  styles: [`
    .sug { padding: 12px; margin: 12px 0; border-radius: 8px; background: rgba(99,102,241,.06); font-size: 13px; }
    .sug-note { color: #667085; margin: 4px 0; }
    .sug-mode { font-size: 12px; margin: 4px 0; }
    .sug-ai { border: 1px solid #6366f1; background: transparent; border-radius: 8px; padding: .25rem .7rem; cursor: pointer; margin-right: 8px; }
    .sug-ai-text { margin-top: 4px; }
    .sug-error { color: #b42318; }
    .sug-item { border-top: 1px solid rgba(0,0,0,.08); padding: 8px 0; overflow-wrap: anywhere; }
    .sug-title { font-weight: 600; }
    .sug-tag { font-weight: 400; font-size: 11px; padding: 1px 6px; border-radius: 999px; background: #f2f4f7; margin-left: 6px; }
    .sug-ev { font-family: monospace; font-size: 12px; color: #475467; }
  `],
})
export class SuggestionsComponent implements OnChanges, OnDestroy {
  private readonly http = inject(HttpClient);
  /** Rule-based suggestions from the same response as the OR result. */
  @Input() items: Suggestion[] = [];
  @Input() snapshotId = '';
  /** The OR query string that produced this snapshot. */
  @Input() query = '';

  readonly state = signal<'idle' | 'loading' | 'changed' | 'error'>('idle');
  readonly shown = signal<Suggestion[]>([]);
  readonly mode = signal<SuggestionMode | null>(null);
  private sub = new Subscription();
  private seq = 0;

  modeText = () => { const m = this.mode(); return m ? (MODE_LABEL[m.result] ?? 'Mode: unknown') : MODE_LABEL.deterministic; };

  ngOnChanges(): void {
    // A new snapshot always resets to the rule-based list; an older AI answer can never apply to it.
    this.seq++;
    this.state.set('idle');
    this.mode.set(null);
    this.shown.set(this.items ?? []);
  }

  ngOnDestroy(): void { this.sub.unsubscribe(); }

  explainWithAi(): void {
    const mine = ++this.seq;
    this.state.set('loading');
    const url = '/api/v1/analytics/suggestions?' + this.query + (this.query ? '&' : '') + 'ai=1&snapshot_id=' + encodeURIComponent(this.snapshotId);
    this.sub.add(this.http.get<{ success: boolean; data: { suggestions: Suggestion[]; mode?: SuggestionMode; grounding?: { snapshot_id?: string } } }>(url).subscribe({
      next: (res) => {
        if (mine !== this.seq) return;
        const okShape = res?.success && Array.isArray(res.data?.suggestions);
        // Apply only if the server confirms it worked on the snapshot on screen.
        if (!okShape || res.data.grounding?.snapshot_id !== this.snapshotId) { this.state.set('error'); return; }
        this.shown.set(res.data.suggestions);
        this.mode.set(res.data.mode ?? null);
        this.state.set('idle');
      },
      error: (err) => {
        if (mine !== this.seq) return;
        this.state.set(err?.status === 409 ? 'changed' : 'error');
      },
    }));
  }
}
