import { Component, inject, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { CoverageResponse, PROVIDER_LABEL, STATUS_LABEL, formatRecordedTotals, syncLine } from './data-coverage';

@Component({
  selector: 'app-data-coverage',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="section-container cov">
      <div class="cov-head">
        <h3 class="cov-title">Stored reports by platform</h3>
        <button type="button" class="cov-refresh" (click)="load()">Refresh</button>
      </div>
      @if (state() === 'loading') {
        <p class="cov-msg">Loading pulled-report status...</p>
      } @else if (state() === 'error') {
        <p class="cov-msg cov-error">Could not load report status. Nothing is shown rather than guessing. Press Refresh to retry.</p>
      } @else if (data(); as d) {
        <p class="cov-sub">{{ d.start_date }} to {{ d.end_date }}. {{ d.note }}</p>
        @if (allEmpty()) {
          <p class="cov-msg">No platform is connected yet. Connect an account above, then pull reports.</p>
        }
        <div class="cov-grid">
          @for (p of d.platforms; track p.provider) {
            <div class="cov-card" [attr.data-status]="p.status">
              <div class="cov-card-head">
                <strong>{{ label(p.provider) }}</strong>
                <span class="cov-pill" [class.ok]="p.status === 'data_available'">{{ status(p.status) }}</span>
              </div>
              @if (p.status === 'data_available') {
                <p>{{ p.days_with_data }} days with stored data ({{ p.first_date }} to {{ p.last_date }})</p>
                @for (t of totals(p); track t.currency) {
                  <p>{{ t.currency }}: spend {{ t.spend }}, reported outcome value {{ t.value }}</p>
                  <p class="cov-sub">{{ t.note }}</p>
                }
              }
              @for (a of p.accounts; track a.id) {
                <p class="cov-acc">{{ a.name || 'Account ' + a.id }} ({{ a.currency || 'currency unknown' }}): {{ sync(a) }}</p>
              }
              @for (g of p.gaps; track g) {
                <p class="cov-gap">{{ g }}</p>
              }
            </div>
          }
        </div>
      }
    </div>
  `,
  styles: [`
    .cov { margin-top: 1.5rem; }
    .cov-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
    .cov-title { margin: 0; font-size: 1.1rem; }
    .cov-refresh { border: 1px solid var(--border-color, #d0d5dd); background: transparent; border-radius: 8px; padding: .35rem .8rem; cursor: pointer; }
    .cov-sub, .cov-msg { color: var(--text-secondary, #667085); font-size: .85rem; }
    .cov-error { color: #b42318; }
    .cov-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; }
    .cov-card { border: 1px solid var(--border-color, #d0d5dd); border-radius: 12px; padding: 1rem; font-size: .85rem; }
    .cov-card p { margin: .3rem 0; overflow-wrap: anywhere; }
    .cov-card-head { display: flex; justify-content: space-between; align-items: center; gap: .5rem; }
    .cov-pill { font-size: .72rem; padding: .15rem .5rem; border-radius: 999px; background: #f2f4f7; color: #475467; }
    .cov-pill.ok { background: #dcfae6; color: #067647; }
    .cov-gap { color: #93370d; }
  `],
})
export class DataCoverageComponent implements OnInit {
  private readonly http = inject(HttpClient);
  readonly state = signal<'loading' | 'error' | 'loaded'>('loading');
  readonly data = signal<CoverageResponse | null>(null);

  label = (p: any) => PROVIDER_LABEL[p as keyof typeof PROVIDER_LABEL] || String(p);
  status = (s: any) => STATUS_LABEL[s as keyof typeof STATUS_LABEL];
  totals = (p: any) => formatRecordedTotals(p);
  sync = syncLine;
  allEmpty = () => (this.data()?.platforms ?? []).every((p) => p.status === 'not_connected');

  ngOnInit(): void { this.load(); }

  load(): void {
    this.state.set('loading');
    this.http.get<{ success: boolean; data: CoverageResponse }>('/api/v1/analytics/data-coverage').subscribe({
      next: (res) => {
        if (res?.success && res.data?.platforms) { this.data.set(res.data); this.state.set('loaded'); }
        else { this.data.set(null); this.state.set('error'); }
      },
      error: () => { this.data.set(null); this.state.set('error'); },
    });
  }
}
