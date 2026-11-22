import { SuggestionsComponent, Suggestion } from './suggestions.component';
import { Component, computed, inject, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ButtonComponent } from '../../shared/components/button/button.component';
import { IconComponent } from '../../shared/components/icon/icon.component';

export interface ChannelAllocation {
  channel: string;
  display_name: string;
  current_spend: number;
  current_revenue: number;
  current_roas: number;
  optimal_spend: number;
  optimal_revenue: number;
  optimal_roas: number;
  delta_spend: number;
  delta_spend_percentage: number;
  delta_vs_baseline: number;
  delta_vs_baseline_percentage: number;
  lift_vs_baseline: number;
  marginal_roas_current: number;
  marginal_roas_optimal: number;
  action: 'SCALE_UP' | 'SCALE_DOWN' | 'MAINTAIN';
}

export interface Recommendation {
  type: string;
  severity: string;
  title: string;
  description: string;
  projected_revenue_lift: number;
}

export interface FrontierPoint {
  budget: number;
  expected_revenue: number;
  blended_roas: number;
  is_current: boolean;
}

export interface OperationsResearchData {
  total_budget: number;
  current_total_revenue: number;
  current_blended_roas: number;
  optimal_total_revenue: number;
  optimal_blended_roas: number;
  revenue_gain_delta: number;
  roas_gain_delta: number;
  revenue_gain_percentage: number;
  equilibrium_lambda: number;
  channel_allocations: ChannelAllocation[];
  recommendations: Recommendation[];
  frontier_curve: FrontierPoint[];
  sensitivity_points: Record<string, { channel: string; display_name: string; curve: any[] }>;
  mathematical_model: string;
  status?: 'ok' | 'insufficient_data' | 'infeasible' | 'constraints_required' | 'no_allocation' | 'mixed_currency' | 'currency_mismatch' | 'exceeds_user_cap';
  status_message?: string | null;
  warnings?: string[];
  comparison?: { observed_spend: number; observed_revenue_on_curve: number; reference_spend: number; baseline_revenue_same_spend: number; baseline_roas_same_spend: number; reallocation_gain: number; spend_change_vs_observed: number; revenue_change_vs_observed: number; definition: string };
  solver_certificate?: { budget_residual: number; budget_tolerance: number; stationarity_residual: number; stationarity_tolerance: number; within_bounds: boolean; scope: string };
  data_sources?: string[];
}

@Component({
  selector: 'app-operations-research-feature',
  standalone: true,
  imports: [CommonModule, FormsModule, ButtonComponent, IconComponent, SuggestionsComponent],
  template: `
    <div class="or-page">
      <!-- Header -->
      <div class="page-header">
        <div>
          <div class="header-badge-row">
            <h2 class="title">Operations Research Budget Optimizer</h2>
            <span class="math-badge">Deterministic solver on an assumed response curve (estimate)</span>
          </div>
          <p class="subtitle">
Suggests how to split one budget across your channels (Meta, Google, TikTok, Snapchat) using a deterministic solver on an assumed response curve you set. The result is an estimate from your data and that assumption, not a forecast, and it never changes your campaigns.
          </p>
        </div>

        <div class="budget-controller">
          <div class="bc-field">
            <label class="budget-label" for="or_budget">Budget amount (profile currency)</label>
            <input id="or_budget" type="number" [ngModel]="targetBudget()" (ngModelChange)="targetBudget.set($event)"
              (keyup.enter)="solveOptimization()" min="100" max="500000" step="500" class="budget-input" />
          </div>
          <div class="bc-field">
            <label class="budget-label" for="or_horizon">Budget period</label>
            <select id="or_horizon" [ngModel]="horizon()" (ngModelChange)="horizon.set($event)" class="budget-input">
              <option [ngValue]="null">Select…</option>
              <option value="per_30_days">per 30 days</option>
              <option value="daily">per day (x30)</option>
            </select>
          </div>
          <div class="bc-field">
            <label class="budget-label" for="or_sat" title="Explicit assumption: revenue ceiling as a multiple of observed revenue. Not defaulted.">Saturation multiplier (assumption)</label>
            <input id="or_sat" type="number" [ngModel]="saturation()" (ngModelChange)="saturation.set($event)"
              (keyup.enter)="solveOptimization()" min="1.1" max="20" step="0.1" placeholder="e.g. 3" class="budget-input" />
          </div>
          <div class="bc-field">
            <label class="budget-label" for="or_mode" title="exact spends the whole budget; at_most may spend less if the marginal return is below your minimum.">Budget mode</label>
            <select id="or_mode" [ngModel]="mode()" (ngModelChange)="mode.set($event)" class="budget-input">
              <option [ngValue]="null">Not set</option>
              <option value="exact">exact (spend all)</option>
              <option value="at_most">at most (may spend less)</option>
            </select>
          </div>
          <div class="bc-field">
            <label class="budget-label" for="or_minroas" title="Stop spending where the marginal ROAS falls below this value.">Min marginal ROAS (at most only)</label>
            <input id="or_minroas" type="number" [ngModel]="minRoas()" (ngModelChange)="minRoas.set($event)" [disabled]="mode() !== 'at_most'" min="0.01" step="0.1" placeholder="only with at most" class="budget-input" />
          </div>
          <div class="bc-field">
            <label class="budget-label" for="or_lh">Channel limits period</label>
            <select id="or_lh" [ngModel]="limitsHorizon()" (ngModelChange)="limitsHorizon.set($event)" class="budget-input">
              <option [ngValue]="null">No limits</option>
              <option value="per_30_days">per 30 days</option>
              <option value="daily">per day</option>
            </select>
          </div>
          @if (limitsHorizon()) {
            @for (ch of channels; track ch) {
              <div class="bc-field" [attr.data-limits]="ch">
                <span class="budget-label">{{ ch }} min / max</span>
                <div style="display:flex;gap:6px">
                  <input [id]="'or_min_' + ch" type="number" min="0" placeholder="min" [ngModel]="limits()[ch].min" (ngModelChange)="setLimit(ch,'min',$event)" class="budget-input" />
                  <input [id]="'or_max_' + ch" type="number" min="0" placeholder="max" [ngModel]="limits()[ch].max" (ngModelChange)="setLimit(ch,'max',$event)" class="budget-input" />
                </div>
              </div>
            }
          }
          @if (validationErrors().length) {
            <div class="or-validation" role="alert" style="flex-basis:100%;color:#f87171;font-size:13px">
              @for (e of validationErrors(); track e) { <div>{{ e }}</div> }
            </div>
          }
          <app-button variant="primary" [loading]="isLoading()" [disabled]="validationErrors().length > 0" (clicked)="solveOptimization()">Solve Model</app-button>
          
        </div>
      </div>

      @if (rawData() && !data()) {
        <div class="or-stale" role="status" style="padding:12px;margin:12px 0;border:1px solid #f59e0b;border-radius:8px;background:rgba(245,158,11,.1)">
          <strong>Inputs changed.</strong> The previous result is hidden because it was computed for different inputs. Press Solve Model.
        </div>
      }
      @if (data() && data()?.status !== 'ok') {
        <div class="or-status" role="status" style="padding:16px;margin:16px 0;border:1px solid #f59e0b;border-radius:8px;background:rgba(245,158,11,.1)">
          <strong>{{ data()?.status === 'infeasible' ? 'No allocation fits the stated limits' : (data()?.status === 'constraints_required' ? 'Budget and assumption required' : (data()?.status === 'no_allocation' ? 'No allocation: nothing clears your minimum marginal ROAS' : 'No scenario produced')) }}</strong>
          <p style="margin:6px 0 0">{{ data()?.status_message }}</p>
        </div>
      }
      @if (data()?.warnings?.length) {
        <div class="or-warnings" role="note" aria-label="Warnings and excluded channels" style="padding:12px;margin:12px 0;border-radius:8px;background:rgba(99,102,241,.08);font-size:13px">
          <strong>{{ data()?.status === 'ok' ? 'Read before using this scenario' : 'Why no numbers were produced' }}</strong>
          @for (w of data()?.warnings; track w) { <div>{{ w }}</div> }
        </div>
      }

      @if (data()?.status === 'ok' && data()?.solver_certificate; as c) {
        <div class="or-warnings" role="note" style="padding:12px;margin:12px 0;border-radius:8px;background:rgba(16,185,129,.08);font-size:13px">
          <strong>Solver check passed</strong>
          <div>Budget gap {{ c.budget_residual | number:'1.0-8' }} (allowed {{ c.budget_tolerance | number:'1.0-8' }}); optimality gap {{ c.stationarity_residual | number:'1.0-10' }} (allowed {{ c.stationarity_tolerance | number:'1.0-8' }}); limits respected: {{ c.within_bounds ? 'yes' : 'no' }}.</div>
          <div>{{ c.scope }}</div>
        </div>
      }

      @if (data()) { <app-suggestions [items]="sugItems()" [snapshotId]="snapshotId()" [query]="suggestQuery()"></app-suggestions> }

      <!-- Key KPI Cards -->
      @if (data()?.status === 'ok') {
        <div class="kpi-grid">
          <!-- Total Optimal Revenue -->
          <div class="kpi-card highlight">
            <div class="kpi-header">
              <span class="kpi-label">Scenario Revenue (assumed curve)</span>
              <span class="kpi-pill success">{{ data()!.revenue_gain_delta >= 0 ? '+' : '' }}{{ data()!.revenue_gain_percentage }}% vs same-spend mix</span>
            </div>
            <div class="kpi-value">{{ data()!.optimal_total_revenue | number:'1.0-0' }}</div>
            <div class="kpi-subtext">
              Reallocation effect: {{ data()!.revenue_gain_delta >= 0 ? '+' : '' }}{{ data()!.revenue_gain_delta | number:'1.0-0' }} against your current mix at the same total spend ({{ data()?.comparison?.reference_spend | number:'1.0-0' }}).
              Versus the observed 30 days: spend {{ (data()?.comparison?.spend_change_vs_observed ?? 0) >= 0 ? '+' : '' }}{{ data()?.comparison?.spend_change_vs_observed | number:'1.0-0' }}, revenue {{ (data()?.comparison?.revenue_change_vs_observed ?? 0) >= 0 ? '+' : '' }}{{ data()?.comparison?.revenue_change_vs_observed | number:'1.0-0' }}. Not profit.
            </div>
          </div>

          <!-- Optimal Blended ROAS -->
          <div class="kpi-card">
            <div class="kpi-header">
              <span class="kpi-label">Optimized Blended ROAS</span>
              <span class="kpi-pill info">{{ data()!.roas_gain_delta >= 0 ? '+' : '' }}{{ data()!.roas_gain_delta | number:'1.2-2' }}x vs same-spend mix</span>
            </div>
            <div class="kpi-value">{{ data()!.optimal_blended_roas | number:'1.2-2' }}x</div>
            <div class="kpi-subtext">
              Same-spend mix ROAS: {{ data()?.comparison?.baseline_roas_same_spend | number:'1.2-2' }}x. Observed ROAS: {{ data()!.current_blended_roas | number:'1.2-2' }}x
            </div>
          </div>

          <!-- Equilibrium Marginal Return -->
          <div class="kpi-card">
            <div class="kpi-header">
              <span class="kpi-label">Equilibrium Threshold (&lambda;*)</span>
              <span class="kpi-pill">Solver optimum (assumed curve)</span>
            </div>
            <div class="kpi-value">{{ data()!.equilibrium_lambda | number:'1.3-3' }}x</div>
            <div class="kpi-subtext">
              Marginal yield equalized across all channels
            </div>
          </div>

          <!-- Optimization Solver Status -->
          <div class="kpi-card">
            <div class="kpi-header">
              <span class="kpi-label">Solver result</span>
              <span class="kpi-pill success">Within tolerance</span>
            </div>
            <div class="kpi-value text-base">Optimal on assumed curve</div>
            <div class="kpi-subtext">
              Optimal only for the assumed response curve. Not a forecast of real results.
            </div>
          </div>
        </div>

        <!-- Interactive Visual Graphs Stage -->
        <div class="graphs-grid">
          <!-- Graph 1: Convex Efficiency Frontier -->
          <div class="graph-card">
            <div class="card-header">
              <div>
                <h3 class="card-title">Cross-Channel Efficiency Frontier</h3>
                <p class="card-desc">Convex diminishing returns curve showing revenue vs total capital allocated.</p>
              </div>
              <div class="legend-row">
                <span class="legend-item"><span class="dot curve-dot"></span> Optimal Curve</span>
                <span class="legend-item"><span class="dot current-dot"></span> Current Operating Point</span>
              </div>
            </div>

            <div class="chart-container">
              <svg viewBox="0 0 600 240" class="frontier-svg">
                <defs>
                  <linearGradient id="frontier-fill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#6366f1" stop-opacity="0.35"/>
                    <stop offset="100%" stop-color="#6366f1" stop-opacity="0.0"/>
                  </linearGradient>
                </defs>

                <!-- Grid Lines -->
                <line x1="50" y1="20" x2="570" y2="20" stroke="rgba(255,255,255,0.06)" stroke-dasharray="3 3"/>
                <line x1="50" y1="80" x2="570" y2="80" stroke="rgba(255,255,255,0.06)" stroke-dasharray="3 3"/>
                <line x1="50" y1="140" x2="570" y2="140" stroke="rgba(255,255,255,0.06)" stroke-dasharray="3 3"/>
                <line x1="50" y1="200" x2="570" y2="200" stroke="rgba(255,255,255,0.12)"/>

                <!-- Y-Axis Labels -->
                <text x="40" y="24" fill="#94a3b8" font-size="10" text-anchor="end">{{ getMaxFrontierRevenue() | number:'1.0-0' }}</text>
                <text x="40" y="114" fill="#94a3b8" font-size="10" text-anchor="end">{{ (getMaxFrontierRevenue() / 2) | number:'1.0-0' }}</text>
                <text x="40" y="204" fill="#94a3b8" font-size="10" text-anchor="end">0</text>

                <!-- Frontier Area & Line -->
                <path [attr.d]="getFrontierAreaPath()" fill="url(#frontier-fill)"/>
                <path [attr.d]="getFrontierLinePath()" fill="none" stroke="#6366f1" stroke-width="3" stroke-linecap="round"/>

                <!-- Curve Nodes -->
                @for (pt of data()!.frontier_curve; track pt.budget) {
                  <circle 
                    [attr.cx]="getPointX(pt.budget)" 
                    [attr.cy]="getPointY(pt.expected_revenue)" 
                    [attr.r]="pt.is_current ? 6 : 3.5" 
                    [attr.fill]="pt.is_current ? '#10b981' : '#38bdf8'"
                    stroke="#ffffff"
                    [attr.stroke-width]="pt.is_current ? 2.5 : 1"
                  />
                }
              </svg>
            </div>
          </div>

          <!-- Graph 2: Current vs Optimal Spend Reallocation -->
          <div class="graph-card">
            <div class="card-header">
              <div>
                <h3 class="card-title">Optimal Budget Reallocation</h3>
                <p class="card-desc">Current spend vs scenario allocation (assumed curve) per platform.</p>
              </div>
              <div class="legend-row">
                <span class="legend-item"><span class="dot current-bar-dot"></span> Current</span>
                <span class="legend-item"><span class="dot optimal-bar-dot"></span> Optimal</span>
              </div>
            </div>

            <div class="bars-container">
              @for (c of data()!.channel_allocations; track c.channel) {
                <div class="channel-realloc-row">
                  <div class="channel-meta">
                    <div class="channel-name-group">
                      <app-icon [name]="c.channel" [size]="18"></app-icon>
                      <span class="channel-title">{{ c.display_name }}</span>
                    </div>
                    <span [class]="'action-pill ' + c.action.toLowerCase()">
                      {{ c.action === 'SCALE_UP' ? 'Shift +' + c.delta_vs_baseline_percentage + '% vs same-spend mix' : (c.action === 'SCALE_DOWN' ? 'Shift ' + c.delta_vs_baseline_percentage + '% vs same-spend mix' : 'Near current mix') }}
                    </span>
                  </div>

                  <div class="comparative-bars">
                    <!-- Current Bar -->
                    <div class="bar-row">
                      <div class="bar-track">
                        <div class="bar-fill current" [style.width.%]="getBarPercent(c.current_spend)"></div>
                      </div>
                      <span class="bar-value">{{ c.current_spend | number:'1.0-0' }}</span>
                    </div>

                    <!-- Optimal Bar -->
                    <div class="bar-row">
                      <div class="bar-track">
                        <div class="bar-fill optimal" [style.width.%]="getBarPercent(c.optimal_spend)"></div>
                      </div>
                      <span class="bar-value highlight">{{ c.optimal_spend | number:'1.0-0' }}</span>
                    </div>
                  </div>
                </div>
              }
            </div>
          </div>
        </div>

        <!-- Actionable Algorithmic Directives -->
        <div class="directives-section">
          <div class="section-header">
            <div>
              <h3 class="section-title">Algorithmic Decision Directives</h3>
              <p class="section-subtitle">Scenario steps computed on an assumed curve. Not operational advice.</p>
            </div>
          </div>

          <div class="directives-grid">
            @for (rec of data()!.recommendations; track rec.title) {
              <div [class]="'directive-card ' + rec.severity.toLowerCase()">
                <div class="directive-top">
                  <span class="directive-type">{{ rec.type | titlecase }}</span>
                  @if (rec.projected_revenue_lift !== 0) {
                    <span class="lift-pill">{{ rec.projected_revenue_lift > 0 ? '+' : '' }}{{ rec.projected_revenue_lift | number:'1.0-0' }} vs same-spend mix</span>
                  }
                </div>
                <h4 class="directive-title">{{ rec.title }}</h4>
                <p class="directive-desc">{{ rec.description }}</p>
              </div>
            }
          </div>
        </div>

        <!-- Channel Detailed Breakdown Table -->
        <div class="table-card">
          <div class="table-header">
            <h4>Channel Sensitivity & Optimization Breakdown</h4>
          </div>
          <table class="or-table">
            <thead>
              <tr>
                <th>Advertising Platform</th>
                <th>Current Spend</th>
                <th>Optimal Spend</th>
                <th>Delta ($)</th>
                <th>Current Marginal ROAS</th>
                <th>Optimal Marginal ROAS</th>
                <th>Expected ROAS</th>
                <th>Status Directive</th>
              </tr>
            </thead>
            <tbody>
              @for (row of data()!.channel_allocations; track row.channel) {
                <tr>
                  <td>
                    <div class="platform-cell">
                      <app-icon [name]="row.channel" [size]="16"></app-icon>
                      <span class="font-semibold">{{ row.display_name }}</span>
                    </div>
                  </td>
                  <td>{{ row.current_spend | number:'1.0-0' }}</td>
                  <td class="font-semibold highlight">{{ row.optimal_spend | number:'1.0-0' }}</td>
                  <td [class]="row.delta_spend >= 0 ? 'text-success' : 'text-danger'">
                    {{ row.delta_spend >= 0 ? '+' : '' }}{{ row.delta_spend | number:'1.0-0' }} ({{ row.delta_spend_percentage }}%)
                  </td>
                  <td>{{ row.marginal_roas_current }}x</td>
                  <td class="font-semibold">{{ row.marginal_roas_optimal }}x</td>
                  <td class="font-semibold">{{ row.optimal_roas }}x</td>
                  <td>
                    <span [class]="'action-pill ' + row.action.toLowerCase()">
                      {{ row.action }}
                    </span>
                  </td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      } @else if (isLoading()) {
        <div class="loading-state">
          <div class="spinner"></div>
          <p>Solving convex non-linear optimization model...</p>
        </div>
      }
    </div>
  `,
  styles: [`
    .or-page {
      display: flex;
      flex-direction: column;
      gap: 1.75rem;
    }

    .page-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 1.25rem;
    }

    .header-badge-row {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      flex-wrap: wrap;
    }

    .title {
      font-size: 1.625rem;
      font-weight: 700;
      color: var(--text-main);
      letter-spacing: -0.02em;
    }

    .math-badge {
      font-size: 0.6875rem;
      font-weight: 700;
      padding: 0.25rem 0.625rem;
      border-radius: var(--radius-full);
      background: rgba(99, 102, 241, 0.15);
      color: #818cf8;
      border: 1px solid rgba(99, 102, 241, 0.3);
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .subtitle {
      font-size: 0.875rem;
      color: var(--text-muted);
      margin-top: 0.25rem;
      max-width: 680px;
    }

    /* Budget Controller */
    .budget-controller {
      display: flex;
      flex-wrap: wrap;
      align-items: flex-end;
      gap: 0.75rem;
    }
    .bc-note { flex: 1 1 100%; margin: 0; font-size: 0.78rem; color: var(--text-subtle); }
    .bc-field { display: flex; flex-direction: column; gap: 0.3rem; min-width: 0; }
    .budget-label {
      font-size: 0.75rem;
      font-weight: 600;
      color: var(--text-subtle);
    }
    .budget-input {
      box-sizing: border-box;
      width: 11rem;
      max-width: 100%;
      padding: 0.55rem 0.7rem;
      border: 1px solid var(--border-strong);
      border-radius: var(--radius-sm);
      background: var(--bg-surface);
      font: inherit;
      font-size: 0.92rem;
      color: var(--text-main);
    }
    .budget-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-ring); }
    @media (max-width: 640px) {
      .bc-field, .budget-controller app-button { flex: 1 1 100%; }
      .budget-input { width: 100%; }
    }

    /* KPI Grid */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1rem;
    }

    .kpi-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 1.25rem;
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
      box-shadow: var(--shadow-sm);
      position: relative;
      overflow: hidden;

      &.highlight {
        border-color: rgba(99, 102, 241, 0.4);
        box-shadow: var(--shadow-glow);
      }
    }

    .kpi-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .kpi-label {
      font-size: 0.75rem;
      font-weight: 600;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }

    .kpi-pill {
      font-size: 0.6875rem;
      font-weight: 700;
      padding: 0.15rem 0.5rem;
      border-radius: var(--radius-full);
      background: var(--bg-subtle);
      color: var(--text-muted);

      &.success {
        background: rgba(16, 185, 129, 0.15);
        color: #34d399;
      }
      &.info {
        background: rgba(56, 189, 248, 0.15);
        color: #38bdf8;
      }
    }

    .kpi-value {
      font-size: 1.75rem;
      font-weight: 800;
      color: var(--text-main);
      letter-spacing: -0.02em;

      &.text-base {
        font-size: 1.25rem;
        color: #10b981;
      }
    }

    .kpi-subtext {
      font-size: 0.75rem;
      color: var(--text-subtle);
    }

    /* Graphs Grid */
    .graphs-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.25rem;

      @media (max-width: 1024px) {
        grid-template-columns: 1fr;
      }
    }

    .graph-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .card-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 1rem;
    }

    .card-title {
      font-size: 1.05rem;
      font-weight: 700;
      color: var(--text-main);
    }

    .card-desc {
      font-size: 0.8125rem;
      color: var(--text-muted);
      margin-top: 0.15rem;
    }

    .legend-row {
      display: flex;
      gap: 0.75rem;
      font-size: 0.75rem;
      color: var(--text-subtle);
    }

    .legend-item {
      display: flex;
      align-items: center;
      gap: 0.35rem;
    }

    .dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;

      &.curve-dot { background: #6366f1; }
      &.current-dot { background: #10b981; }
      &.current-bar-dot { background: #64748b; }
      &.optimal-bar-dot { background: #6366f1; }
    }

    .chart-container {
      width: 100%;
      height: 240px;
    }

    .frontier-svg {
      width: 100%;
      height: 100%;
      overflow: visible;
    }

    /* Reallocation Bars */
    .bars-container {
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
      margin-top: 0.5rem;
    }

    .channel-realloc-row {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }

    .channel-meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .channel-name-group {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .channel-title {
      font-size: 0.875rem;
      font-weight: 600;
      color: var(--text-main);
    }

    .action-pill {
      font-size: 0.6875rem;
      font-weight: 700;
      padding: 0.15rem 0.5rem;
      border-radius: var(--radius-full);
      text-transform: uppercase;
      letter-spacing: 0.04em;

      &.scale_up {
        background: rgba(16, 185, 129, 0.15);
        color: #34d399;
      }
      &.scale_down {
        background: rgba(239, 68, 68, 0.15);
        color: #f87171;
      }
      &.maintain {
        background: rgba(99, 102, 241, 0.15);
        color: #818cf8;
      }
    }

    .comparative-bars {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
    }

    .bar-track {
      height: 20px;
      background: var(--bg-subtle);
      border-radius: var(--radius-sm);
      position: relative;
      overflow: hidden;
      display: flex;
      align-items: center;
    }

    .bar-fill {
      height: 100%;
      border-radius: var(--radius-sm);
      transition: width 0.4s ease;

      &.current {
        background: #475569;
      }
      &.optimal {
        background: linear-gradient(90deg, #6366f1, #38bdf8);
      }
    }

    .bar-row {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .bar-row .bar-track {
      flex: 1 1 auto;
    }
    .bar-value {
      flex: 0 0 auto;
      min-width: 3.5rem;
      text-align: right;
      font-size: 0.75rem;
      font-weight: 700;
      color: var(--text-main);

      &.highlight {
        color: var(--primary);
      }
    }

    /* Directives Section */
    .directives-section {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .section-title {
      font-size: 1.125rem;
      font-weight: 700;
      color: var(--text-main);
    }

    .section-subtitle {
      font-size: 0.8125rem;
      color: var(--text-muted);
    }

    .directives-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
      gap: 1rem;
    }

    .directive-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 1.25rem;
      display: flex;
      flex-direction: column;
      gap: 0.65rem;

      &.high_impact {
        border-color: rgba(99, 102, 241, 0.5);
        background: linear-gradient(180deg, rgba(99, 102, 241, 0.05) 0%, var(--bg-surface) 100%);
      }
      &.opportunity {
        border-color: rgba(16, 185, 129, 0.4);
      }
      &.efficiency_drain {
        border-color: rgba(239, 68, 68, 0.35);
      }
    }

    .directive-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .directive-type {
      font-size: 0.6875rem;
      font-weight: 700;
      color: var(--text-subtle);
      letter-spacing: 0.05em;
      text-transform: uppercase;
    }

    .lift-pill {
      font-size: 0.6875rem;
      font-weight: 700;
      padding: 0.15rem 0.5rem;
      background: rgba(16, 185, 129, 0.15);
      color: #34d399;
      border-radius: var(--radius-full);
    }

    .directive-title {
      font-size: 0.9375rem;
      font-weight: 700;
      color: var(--text-main);
    }

    .directive-desc {
      font-size: 0.8125rem;
      color: var(--text-muted);
      line-height: 1.5;
    }

    /* Table */
    .table-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      overflow-x: auto;
    }

    .table-header {
      padding: 1.25rem 1.5rem;
      border-bottom: 1px solid var(--border-subtle);

      h4 {
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-main);
      }
    }

    .or-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
      font-size: 0.875rem;

      th {
        padding: 0.75rem 1.25rem;
        background: var(--bg-subtle);
        color: var(--text-subtle);
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
      }

      td {
        padding: 0.875rem 1.25rem;
        border-bottom: 1px solid var(--border-subtle);
        color: var(--text-main);
      }
    }

    .platform-cell {
      display: flex;
      align-items: center;
      gap: 0.65rem;
    }

    .highlight {
      color: #38bdf8;
    }

    .text-success { color: #10b981; }
    .text-danger { color: #ef4444; }

    .loading-state {
      padding: 4rem;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 1rem;
      color: var(--text-muted);
    }

    .spinner {
      width: 32px;
      height: 32px;
      border: 3px solid rgba(99, 102, 241, 0.2);
      border-top-color: #6366f1;
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }
  `]
})
export class OperationsResearchComponent implements OnInit {
  private readonly http = inject(HttpClient);

  readonly targetBudget = signal<number | null>(null);
  readonly saturation = signal<number | null>(null);
  readonly horizon = signal<'daily' | 'per_30_days' | null>(null);
  readonly mode = signal<'exact' | 'at_most' | null>(null);
  readonly minRoas = signal<number | null>(null);
  readonly limitsHorizon = signal<'daily' | 'per_30_days' | null>(null);
  readonly suggestQuery = signal<string>('');
  readonly sugItems = signal<Suggestion[]>([]);
  readonly snapshotId = signal<string>('');
  private solveSeq = 0;
  readonly channels = ['meta', 'google', 'tiktok', 'snapchat'] as const;
  readonly limits = signal<Record<string, { min: number | null; max: number | null }>>({
    meta: { min: null, max: null }, google: { min: null, max: null }, tiktok: { min: null, max: null }, snapchat: { min: null, max: null }
  });
  setLimit(ch: string, k: 'min' | 'max', v: any): void {
    const n = v === '' || v === undefined ? null : v;
    this.limits.update(l => ({ ...l, [ch]: { ...l[ch], [k]: n } }));
  }
  validationErrors(): string[] {
    const e: string[] = [];
    const num = (v: any) => v !== null && v !== undefined && v !== '';
    const b = this.targetBudget();
    if (num(b) && !(Number(b) > 0)) e.push('Budget must be greater than 0.');
    if (num(this.saturation()) && !(Number(this.saturation()) > 1 && Number(this.saturation()) <= 20)) e.push('Saturation multiplier must be above 1 and at most 20.');
    if (num(this.minRoas()) && this.mode() !== 'at_most') e.push('Min marginal ROAS is used only with budget mode "at most". Clear it or choose at most.');
    if (this.mode() === 'at_most' && !(Number(this.minRoas()) > 0)) e.push('Budget mode "at most" needs a minimum marginal ROAS above 0.');
    if (this.limitsHorizon()) {
      let sumMin = 0;
      let anyLimit = false;
      for (const ch of this.channels) {
        const { min, max } = this.limits()[ch];
        if (num(min)) { anyLimit = true; if (!(Number(min) >= 0)) e.push(ch + ': min cannot be negative.'); else sumMin += Number(min); }
        if (num(max)) { anyLimit = true; if (!(Number(max) > 0)) e.push(ch + ': max must be above 0.'); }
        if (num(min) && num(max) && Number(min) > Number(max)) e.push(ch + ': min is above max.');
        if (num(min) && num(b) && this.horizon() === this.limitsHorizon() && Number(min) > Number(b)) e.push(ch + ': minimum exceeds the budget.');
      }
      if (!anyLimit) e.push('Enter at least one min or max, or choose "No limits".');
      if (num(b) && this.horizon() === this.limitsHorizon() && sumMin > Number(b)) e.push('Channel minimums add up to more than the budget.');
    }
    return e;
  }
  readonly isLoading = signal<boolean>(false);
  readonly rawData = signal<OperationsResearchData | null>(null);
  private readonly solvedSig = signal<string | null>(null);
  readonly data = computed(() => (this.solvedSig() === this.inputSig() ? this.rawData() : null));
  inputSig(): string {
    return JSON.stringify([this.targetBudget(), this.horizon(), this.saturation(), this.mode(), this.minRoas(), this.limitsHorizon(), this.limits()]);
  }

  ngOnInit(): void {
    this.solveOptimization();
  }

  solveOptimization(): void {
    if (this.validationErrors().length) { return; }
    this.isLoading.set(true);
    this.solvedSig.set(this.inputSig());
    const params = new URLSearchParams();
    if (this.targetBudget()) params.set('budget', String(this.targetBudget()));
    if (this.horizon()) params.set('budget_horizon', this.horizon()!);
    if (this.saturation()) params.set('saturation_multiplier', String(this.saturation()));
    if (this.mode()) params.set('budget_mode', this.mode()!);
    if (this.mode() === 'at_most' && this.minRoas()) params.set('min_marginal_roas', String(this.minRoas()));
    if (this.limitsHorizon()) {
      params.set('channel_limits_horizon', this.limitsHorizon()!);
      for (const ch of this.channels) {
        const l = this.limits()[ch];
        if (l.min !== null && l.min !== undefined && String(l.min) !== '') params.set('channel_limits[' + ch + '][min]', String(l.min));
        if (l.max !== null && l.max !== undefined && String(l.max) !== '') params.set('channel_limits[' + ch + '][max]', String(l.max));
      }
    }
    const query = params.toString();
    const mine = ++this.solveSeq;
    this.http.get<{ success: boolean; data: OperationsResearchData; suggestions?: Suggestion[]; snapshot_id?: string }>('/api/v1/analytics/operations-research?' + query)
      .subscribe({
        next: res => {
          // Only the newest solve may write: an older answer must not replace a newer result or its inputs.
          if (mine !== this.solveSeq) return;
          this.isLoading.set(false);
          if (res?.success && res.data) {
            this.rawData.set(res.data);
            this.sugItems.set(Array.isArray(res.suggestions) ? res.suggestions : []);
            this.snapshotId.set(res.snapshot_id ?? '');
            this.suggestQuery.set(query);
          } else {
            this.rawData.set({ status: 'insufficient_data', status_message: 'The analysis service did not return data, so no numbers are shown.' } as OperationsResearchData);
            this.sugItems.set([]); this.snapshotId.set('');
          }
        },
        error: () => {
          if (mine !== this.solveSeq) return;
          this.isLoading.set(false);
          this.rawData.set({ status: 'insufficient_data', status_message: 'The analysis service did not respond correctly, so no numbers are shown.' } as OperationsResearchData);
          this.sugItems.set([]); this.snapshotId.set('');
        }
      });
  }

  getMaxFrontierRevenue(): number {
    const pts = this.data()?.frontier_curve || [];
    if (!pts.length) return 50000;
    return Math.max(...pts.map(p => p.expected_revenue)) * 1.05;
  }

  getMaxFrontierBudget(): number {
    const pts = this.data()?.frontier_curve || [];
    if (!pts.length) return 30000;
    return Math.max(...pts.map(p => p.budget));
  }

  getPointX(budget: number): number {
    const maxB = this.getMaxFrontierBudget();
    return 60 + (budget / maxB) * 500;
  }

  getPointY(rev: number): number {
    const maxR = this.getMaxFrontierRevenue();
    return 200 - (rev / maxR) * 175;
  }

  getFrontierLinePath(): string {
    const pts = this.data()?.frontier_curve || [];
    if (!pts.length) return '';
    return pts.map((p, idx) => (idx === 0 ? 'M ' : 'L ') + this.getPointX(p.budget) + ' ' + this.getPointY(p.expected_revenue)).join(' ');
  }

  getFrontierAreaPath(): string {
    const pts = this.data()?.frontier_curve || [];
    if (!pts.length) return '';
    const line = this.getFrontierLinePath();
    const lastX = this.getPointX(pts[pts.length - 1].budget);
    const firstX = this.getPointX(pts[0].budget);
    return line + ' L ' + lastX + ' 200 L ' + firstX + ' 200 Z';
  }

  getBarPercent(spend: number): number {
    const max = ((this.targetBudget() ?? 0) * 0.7) || 10000;
    return Math.min(100, Math.max(5, (spend / max) * 100));
  }
}
