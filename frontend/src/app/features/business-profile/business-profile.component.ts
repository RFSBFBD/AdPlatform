import { Component, inject, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';

export interface BusinessProfileDto {
  business_name: string | null;
  business_model: string | null;
  currency: string | null;
  primary_goal: string | null;
  gross_margin_percent: number | null;
  average_order_value: number | null;
  budget_cap: number | null;
  budget_cap_horizon: string | null;
  target_roas: number | null;
  conversion_definition: string | null;
  notes: string | null;
  custom_fields: Record<string, string> | null;
}

const EMPTY: BusinessProfileDto = {
  business_name: null, business_model: null, currency: null, primary_goal: null,
  gross_margin_percent: null, average_order_value: null, budget_cap: null, budget_cap_horizon: null,
  target_roas: null, conversion_definition: null, notes: null, custom_fields: null,
};

@Component({
  selector: 'app-business-profile-feature',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="bp-page">
      <header class="bp-header">
        <h2 class="bp-title">Business profile</h2>
        <p class="bp-sub">You fill these in. Nothing is guessed: empty fields stay empty and the optimizer says what it is missing.</p>
      </header>

      @if (missing().length) {
        <div class="bp-missing" role="status">Still needed for a meaningful scenario: {{ missing().join(', ') }}</div>
      }
      @if (message()) { <div class="bp-message" role="status">{{ message() }}</div> }

      <form (ngSubmit)="save()" class="bp-card">
        <div class="bp-grid">
          <div class="bp-field">
            <label for="bp_name">Business name</label>
            <input id="bp_name" name="business_name" [(ngModel)]="p.business_name" maxlength="255" />
          </div>
          <div class="bp-field">
            <label for="bp_model">Business model</label>
            <select id="bp_model" name="business_model" [(ngModel)]="p.business_model">
              <option [ngValue]="null">Not set</option>
              <option value="ecommerce">E-commerce</option><option value="lead_gen">Lead generation</option>
              <option value="app">App</option><option value="other">Other</option>
            </select>
          </div>
          <div class="bp-field">
            <label for="bp_cur">Currency (ISO code, e.g. USD)</label>
            <input id="bp_cur" name="currency" [(ngModel)]="p.currency" maxlength="3" />
          </div>
          <div class="bp-field">
            <label for="bp_goal">Primary goal</label>
            <select id="bp_goal" name="primary_goal" [(ngModel)]="p.primary_goal">
              <option [ngValue]="null">Not set</option>
              <option value="revenue">Revenue</option><option value="roas">ROAS</option>
              <option value="leads">Leads</option><option value="profit">Profit</option>
            </select>
          </div>
          <div class="bp-field bp-wide">
            <label for="bp_conv">What counts as a conversion</label>
            <input id="bp_conv" name="conversion_definition" [(ngModel)]="p.conversion_definition" maxlength="255" />
          </div>
          <div class="bp-field">
            <label for="bp_margin">Gross margin % (optional)</label>
            <input id="bp_margin" name="gross_margin_percent" type="number" min="0" max="100" [(ngModel)]="p.gross_margin_percent" />
          </div>
          <div class="bp-field">
            <label for="bp_aov">Average order value (optional)</label>
            <input id="bp_aov" name="average_order_value" type="number" min="0" [(ngModel)]="p.average_order_value" />
          </div>
          <div class="bp-field">
            <label for="bp_roas">Target ROAS (optional)</label>
            <input id="bp_roas" name="target_roas" type="number" min="0" [(ngModel)]="p.target_roas" />
          </div>
          <div class="bp-field">
            <label for="bp_cap">Budget cap, never exceeded (optional)</label>
            <div class="bp-pair">
              <input id="bp_cap" name="budget_cap" type="number" min="0" [(ngModel)]="p.budget_cap" />
              <select name="budget_cap_horizon" [(ngModel)]="p.budget_cap_horizon" aria-label="Budget cap period">
                <option [ngValue]="null">Period…</option>
                <option value="per_30_days">per 30 days</option><option value="daily">per day</option>
              </select>
            </div>
          </div>
          <div class="bp-field bp-wide">
            <label for="bp_notes">Notes</label>
            <textarea id="bp_notes" name="notes" [(ngModel)]="p.notes" maxlength="5000" rows="3"></textarea>
          </div>
        </div>
        <p class="bp-hint">Do not enter passwords, keys or card numbers here. Notes are not used by the optimizer.</p>
        <div class="bp-actions">
          <button type="submit" class="bp-save" [disabled]="saving()">{{ saving() ? 'Saving…' : 'Save profile' }}</button>
        </div>
      </form>
    </div>
  `,
  styles: [`
    .bp-page { max-width: 760px; display: flex; flex-direction: column; gap: 1rem; }
    .bp-title { margin: 0; font-size: 1.5rem; font-weight: 700; color: var(--text-main); }
    .bp-sub { margin: .35rem 0 0; color: var(--text-muted); font-size: .95rem; line-height: 1.5; }
    .bp-missing, .bp-message { padding: .75rem 1rem; border-radius: var(--radius-md); font-size: .9rem; }
    .bp-missing { background: var(--warning-bg); color: var(--warning-text); border: 1px solid var(--warning); }
    .bp-message { background: var(--info-bg); color: var(--info-text); border: 1px solid var(--info); }
    .bp-card { background: var(--bg-surface); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg);
      box-shadow: var(--shadow-sm); padding: 1.5rem; }
    .bp-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem 1.25rem; }
    .bp-wide { grid-column: 1 / -1; }
    .bp-field { display: flex; flex-direction: column; gap: .4rem; min-width: 0; }
    .bp-field label { font-size: .8rem; font-weight: 600; color: var(--text-muted); }
    .bp-field input, .bp-field select, .bp-field textarea {
      width: 100%; box-sizing: border-box; padding: .6rem .75rem; border: 1px solid var(--border-strong);
      border-radius: var(--radius-sm); background: var(--bg-surface); color: var(--text-main); font: inherit; font-size: .92rem; }
    .bp-field input:focus, .bp-field select:focus, .bp-field textarea:focus {
      outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-ring); }
    .bp-pair { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: .5rem; }
    .bp-hint { margin: 1rem 0 0; font-size: .8rem; color: var(--text-subtle); }
    .bp-actions { display: flex; justify-content: flex-end; margin-top: 1rem; }
    .bp-save { padding: .65rem 1.25rem; border: 0; border-radius: var(--radius-sm); background: var(--primary);
      color: var(--primary-text); font-weight: 600; cursor: pointer; }
    .bp-save:hover { background: var(--primary-hover); }
    .bp-save:disabled { opacity: .6; cursor: default; }
    @media (max-width: 640px) {
      .bp-grid { grid-template-columns: minmax(0, 1fr); }
      .bp-card { padding: 1rem; }
      .bp-save { width: 100%; }
    }
  `]
})
export class BusinessProfileComponent implements OnInit {
  private readonly http = inject(HttpClient);
  p: BusinessProfileDto = { ...EMPTY };
  readonly missing = signal<string[]>([]);
  readonly message = signal<string | null>(null);
  readonly saving = signal(false);

  ngOnInit(): void {
    this.http.get<{ success: boolean; data: BusinessProfileDto | null; missing_fields: string[] }>('/api/v1/business-profile').subscribe({
      next: (res) => {
        if (res?.success) {
          this.p = { ...EMPTY, ...(res.data ?? {}) };
          this.missing.set(res.missing_fields ?? []);
        } else {
          this.message.set('The profile service did not respond correctly.');
        }
      },
      error: () => this.message.set('Could not load your profile.'),
    });
  }

  save(): void {
    this.saving.set(true);
    this.message.set(null);
    const body: any = { ...this.p };
    if (body.currency) body.currency = String(body.currency).toUpperCase();
    this.http.put<{ success: boolean; data: BusinessProfileDto; missing_fields: string[] }>('/api/v1/business-profile', body).subscribe({
      next: (res) => {
        this.saving.set(false);
        if (res?.success) {
          this.p = { ...EMPTY, ...res.data };
          this.missing.set(res.missing_fields ?? []);
          this.message.set('Saved.');
        } else {
          this.message.set('The profile service did not confirm the save.');
        }
      },
      error: (err) => {
        this.saving.set(false);
        const first = err.error?.errors ? (Object.values(err.error.errors)[0] as string[])[0] : null;
        this.message.set(first || err.error?.message || 'Saving failed.');
      },
    });
  }
}
