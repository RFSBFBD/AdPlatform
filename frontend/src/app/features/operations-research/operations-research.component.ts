import { Component, inject, OnInit, signal } from '@angular/core';
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
}

@Component({
  selector: 'app-operations-research-feature',
  standalone: true,
  imports: [CommonModule, FormsModule, ButtonComponent, IconComponent],
  template: `
    <div class="or-page">
      <!-- Header -->
      <div class="page-header">
        <div>
          <div class="header-badge-row">
            <h2 class="title">Operations Research Budget Optimizer</h2>
            <span class="math-badge">Deterministic Convex KKT Model (100% Math)</span>
          </div>
          <p class="subtitle">
            Non-linear diminishing marginal returns algorithm solves optimal cross-channel capital allocation across Meta, Google, and TikTok without stochastic LLM variation.
          </p>
        </div>

        <div class="budget-controller">
          <label class="budget-label">Total Monthly Budget</label>
          <div class="budget-input-row">
            <span class="currency-prefix">&#36;</span>
            <input 
              type="number" 
              [ngModel]="targetBudget()" 
              (ngModelChange)="targetBudget.set($event)"
              (keyup.enter)="solveOptimization()"
              min="1000" 
              max="500000" 
              step="500"
              class="budget-input"
            />
            <app-button variant="primary" [loading]="isLoading()" (clicked)="solveOptimization()">
              Solve Model
            </app-button>
          </div>
        </div>
      </div>

      <!-- Key KPI Cards -->
      @if (data()) {
        <div class="kpi-grid">
          <!-- Total Optimal Revenue -->
          <div class="kpi-card highlight">
            <div class="kpi-header">
              <span class="kpi-label">Optimal Expected Revenue</span>
              <span class="kpi-pill success">+{{ data()!.revenue_gain_percentage }}% Gain</span>
            </div>
            <div class="kpi-value">&#36;{{ data()!.optimal_total_revenue | number:'1.0-0' }}</div>
            <div class="kpi-subtext">
              Baseline: &#36;{{ data()!.current_total_revenue | number:'1.0-0' }} (+&#36;{{ data()!.revenue_gain_delta | number:'1.0-0' }} net lift)
            </div>
          </div>

          <!-- Optimal Blended ROAS -->
          <div class="kpi-card">
            <div class="kpi-header">
              <span class="kpi-label">Optimized Blended ROAS</span>
              <span class="kpi-pill info">+{{ data()!.roas_gain_delta | number:'1.2-2' }}x</span>
            </div>
            <div class="kpi-value">{{ data()!.optimal_blended_roas | number:'1.2-2' }}x</div>
            <div class="kpi-subtext">
              Current Portfolio ROAS: {{ data()!.current_blended_roas | number:'1.2-2' }}x
            </div>
          </div>

          <!-- Equilibrium Marginal Return -->
          <div class="kpi-card">
            <div class="kpi-header">
              <span class="kpi-label">Equilibrium Threshold (&lambda;*)</span>
              <span class="kpi-pill">KKT Optimal</span>
            </div>
            <div class="kpi-value">{{ data()!.equilibrium_lambda | number:'1.3-3' }}x</div>
            <div class="kpi-subtext">
              Marginal yield equalized across all channels
            </div>
          </div>

          <!-- Optimization Solver Status -->
          <div class="kpi-card">
            <div class="kpi-header">
              <span class="kpi-label">Mathematical Guarantee</span>
              <span class="kpi-pill success">100% Exact</span>
            </div>
            <div class="kpi-value text-base">Global Optimal</div>
            <div class="kpi-subtext">
              Strictly concave objective with zero stochastic noise
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
                <text x="40" y="24" fill="#94a3b8" font-size="10" text-anchor="end">&#36;{{ getMaxFrontierRevenue() | number:'1.0-0' }}</text>
                <text x="40" y="114" fill="#94a3b8" font-size="10" text-anchor="end">&#36;{{ (getMaxFrontierRevenue() / 2) | number:'1.0-0' }}</text>
                <text x="40" y="204" fill="#94a3b8" font-size="10" text-anchor="end">&#36;0</text>

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
                <p class="card-desc">Current spend vs mathematically recommended allocation per platform.</p>
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
                      {{ c.action === 'SCALE_UP' ? 'Scale +' + c.delta_spend_percentage + '%' : (c.action === 'SCALE_DOWN' ? 'Cap ' + c.delta_spend_percentage + '%' : 'Balanced') }}
                    </span>
                  </div>

                  <div class="comparative-bars">
                    <!-- Current Bar -->
                    <div class="bar-track">
                      <div class="bar-fill current" [style.width.%]="getBarPercent(c.current_spend)"></div>
                      <span class="bar-value">&#36;{{ c.current_spend | number:'1.0-0' }}</span>
                    </div>

                    <!-- Optimal Bar -->
                    <div class="bar-track">
                      <div class="bar-fill optimal" [style.width.%]="getBarPercent(c.optimal_spend)"></div>
                      <span class="bar-value highlight">&#36;{{ c.optimal_spend | number:'1.0-0' }}</span>
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
              <p class="section-subtitle">Deterministic instructions to capture unrealized marginal ROAS across your accounts.</p>
            </div>
          </div>

          <div class="directives-grid">
            @for (rec of data()!.recommendations; track rec.title) {
              <div [class]="'directive-card ' + rec.severity.toLowerCase()">
                <div class="directive-top">
                  <span class="directive-type">{{ rec.type | titlecase }}</span>
                  @if (rec.projected_revenue_lift > 0) {
                    <span class="lift-pill">+&#36;{{ rec.projected_revenue_lift | number:'1.0-0' }} Projected Lift</span>
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
                  <td>&#36;{{ row.current_spend | number:'1.0-0' }}</td>
                  <td class="font-semibold highlight">&#36;{{ row.optimal_spend | number:'1.0-0' }}</td>
                  <td [class]="row.delta_spend >= 0 ? 'text-success' : 'text-danger'">
                    {{ row.delta_spend >= 0 ? '+' : '' }}&#36;{{ row.delta_spend | number:'1.0-0' }} ({{ row.delta_spend_percentage }}%)
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
      flex-direction: column;
      align-items: flex-end;
      gap: 0.35rem;
    }

    .budget-label {
      font-size: 0.75rem;
      font-weight: 600;
      color: var(--text-subtle);
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .budget-input-row {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-md);
      padding: 0.25rem 0.5rem 0.25rem 0.85rem;
      box-shadow: var(--shadow-sm);
    }

    .currency-prefix {
      color: var(--text-muted);
      font-weight: 700;
    }

    .budget-input {
      width: 110px;
      border: none;
      background: transparent;
      font-weight: 700;
      font-size: 1rem;
      color: var(--text-main);
      outline: none;
      padding: 0.25rem;
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

    .bar-value {
      position: absolute;
      right: 8px;
      font-size: 0.6875rem;
      font-weight: 700;
      color: #f8fafc;

      &.highlight {
        color: #ffffff;
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

  readonly targetBudget = signal<number>(12000);
  readonly isLoading = signal<boolean>(false);
  readonly data = signal<OperationsResearchData | null>(null);

  ngOnInit(): void {
    this.solveOptimization();
  }

  solveOptimization(): void {
    this.isLoading.set(true);
    const budget = this.targetBudget();
    this.http.get<{ success: boolean; data: OperationsResearchData }>('/api/v1/analytics/operations-research?budget=' + budget)
      .subscribe({
        next: res => {
          this.isLoading.set(false);
          if (res.success && res.data) {
            this.data.set(res.data);
          }
        },
        error: err => {
          this.isLoading.set(false);
          console.error('Failed to solve OR model:', err);
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
    const max = (this.targetBudget() * 0.7) || 10000;
    return Math.min(100, Math.max(5, (spend / max) * 100));
  }
}
