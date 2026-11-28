import { Component, inject, OnInit, signal, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { HttpClient } from '@angular/common/http';
import { StatCardComponent } from '../../shared/components/stat-card/stat-card.component';
import { EmptyStateComponent } from '../../shared/components/empty-state/empty-state.component';
import { BadgeComponent } from '../../shared/components/badge/badge.component';
import { IconComponent } from '../../shared/components/icon/icon.component';
import { CoverageResponse, FreshnessRow, freshnessRows } from '../accounts/data-coverage';

export interface OverviewSummary {
  spend: number;
  impressions: number;
  clicks: number;
  conversions: number | null;
  conversion_value: number | null;
  cpc: number;
  cpm: number;
  ctr: number;
  roas: number | null;
  cpa: number | null;
}

export interface DqCheck { id: string; status: string; detail: string; }
export interface DqChannel { score: number; level: string; checks: DqCheck[]; rubric_version: string; }
export interface DqBlock {
  window?: { start: string; end: string; days: number };
  channels: Record<string, DqChannel> | DqChannel[];
  portfolio: { score: number | null; level: string; optimize_allowed: boolean; threshold: number; gate_reason: string | null };
  note?: string;
}
export interface DqView { portfolioLine: string; gate: string | null; note: string; channels: { provider: string; line: string; issues: string[] }[]; }
/** Shows the server's rubric output as returned. No score, level or threshold is computed or invented here. */
export function dqView(b: DqBlock | null | undefined): DqView | null {
  if (!b || !b.portfolio) return null;
  const p = b.portfolio;
  const ch = Array.isArray(b.channels) ? {} : (b.channels ?? {});
  const channels = Object.entries(ch).map(([provider, c]) => ({
    provider,
    line: 'Score ' + c.score + '/100 (' + c.level + ')',
    issues: (c.checks ?? []).filter(k => k.status !== 'pass').map(k => k.detail),
  }));
  return {
    portfolioLine: p.score === null ? 'No quality score: no channel data in the window' : 'Portfolio score ' + p.score + '/100 (' + p.level + ')',
    gate: p.optimize_allowed ? null : (p.gate_reason ?? 'Optimization is not allowed by the quality gate.'),
    note: b.note ?? 'Engineering rubric, not a calibrated statistical measure.',
    channels,
  };
}

export interface OrSuggestionItem {
  title: string;
  severity: string;
  kind: string;
}

export interface PlatformItem {
  provider: string;
  spend: number;
  impressions: number;
  clicks: number;
  conversions: number | null;
  roas: number | null;
}

export interface TimelineItem {
  date: string;
  spend: number;
  clicks: number;
  conversions: number | null;
  roas: number | null;
}

@Component({
  selector: 'app-dashboard-feature',
  standalone: true,
  imports: [CommonModule, RouterLink, StatCardComponent, EmptyStateComponent, IconComponent],
  template: `
    <div class="dashboard-page">
      <!-- Header with Filters -->
      <div class="page-header">
        <div>
          <h2 class="title">Advertising Intelligence & Decision Hub</h2>
          <p class="subtitle">Stored report overview. Reporting-currency summaries below are approximate; exact native operands are shown separately.</p>
        </div>
        <div class="filters-row">
          <!-- Platform Filter -->
          <div class="btn-group">
            <button
              type="button"
              class="filter-btn"
              [class.active]="selectedPlatform() === ''"
              (click)="setPlatform('')"
            >
              All Channels
            </button>
            @for (p of platformOptions; track p.id) {
              <button
                type="button"
                class="filter-btn"
                [class.active]="selectedPlatform() === p.id"
                (click)="setPlatform(p.id)"
              >
                <app-icon [name]="p.id" [size]="15"></app-icon>
                <span>{{ p.label }}</span>
              </button>
            }
          </div>

          <!-- Date Range Filter -->
          <div class="btn-group">
            <button
              type="button"
              class="filter-btn"
              [class.active]="dateRangePreset() === '7d'"
              (click)="setDatePreset('7d')"
            >
              Last 7D
            </button>
            <button
              type="button"
              class="filter-btn"
              [class.active]="dateRangePreset() === '30d'"
              (click)="setDatePreset('30d')"
            >
              Last 30D
            </button>
            <button
              type="button"
              class="filter-btn"
              [class.active]="dateRangePreset() === '90d'"
              (click)="setDatePreset('90d')"
            >
              Last 90D
            </button>
          </div>
        </div>
      </div>

      @if (!loadError()) {
      @if (outcomeReason()) { <div role="status" class="outcome-boundary" style="padding:16px;margin-bottom:16px;border:1px solid #f59e0b;border-radius:12px;background:#fffbeb;color:#92400e">{{ outcomeReason() }} Spend and delivery event totals remain separate. They are not unique people. Joint provider-outcome budget optimization is unavailable without comparable source evidence.</div> }
      @if(nativeEvidence();as evidence){
        <section class="native-evidence" aria-label="Exact native-currency recorded operands">
          <h3>Native-currency recorded operands</h3><p>{{evidence.limits}}</p>
          <p>These native operands are separate from legacy reporting-currency estimates below. Currency conversion and chart coordinates are approximate.</p>
          @for(group of nativeGroups();track group.key){
            <div class="native-group"><strong>{{group.key}}</strong><p>Spend {{nativeValue(group.value,'spend')}} · impressions {{nativeValue(group.value,'impressions')}} · clicks {{nativeValue(group.value,'clicks')}}</p>
            <p>Reported outcome value {{nativeValue(group.value,'conversion_value')}}. {{group.value.recorded_arithmetic.arithmetic==='unavailable_original_source_integrity'?'Current original source is unavailable or failed revalidation. Legacy values are not substituted.':group.value.recorded_arithmetic.arithmetic==='exact_over_original_reported_delivery_operands'?'Original provider tokens, returned rows only. Canonical outcomes unavailable.':'Exact stored operands, original source precision unverified.'}}</p>@if(group.value.reason){<p>{{group.value.reason}}</p>}</div>
          }
        </section>
      }
      <p class="summary-precision">Legacy reporting-currency summary. Values and charts are approximate and may use earlier rounded stored operands. They are not the exact native-source figures above.</p>
      <!-- Quick Metrics Grid -->
      <div class="stats-grid">
        <app-stat-card
          title="Approximate reporting spend"
          [value]="(summary().spend | currency:reportingCurrency():'symbol':'1.2-2') || '0.00'"
          [subtext]="'Legacy stored spend converted to (' + reportingCurrency() + ')'"
        />
        <app-stat-card
          title="Total Impressions"
          [value]="(summary().impressions | number) || '0'"
          subtext="Reported impression events, not unique reach"
        />
        <app-stat-card
          title="Total Clicks"
          [value]="(summary().clicks | number) || '0'"
          [subtext]="'CTR: ' + summary().ctr + '% • CPC: ' + summary().cpc + ' ' + reportingCurrency()"
        />
        <app-stat-card
          title="Blended ROAS"
          [value]="summary().roas === null ? 'Withheld' : summary().roas + 'x'"
          [subtext]="summary().roas === null ? 'Outcome definitions are not comparable in this scope' : 'Provider-attributed value / spend, not verified sales'"
        />
        <app-stat-card
          title="Blended CPA"
          [value]="cpaValue()"
          [subtext]="cpaSubtext()"
        />
      </div>
      }

      <!-- Smart Decision Engine Cards -->
      @if (summary().spend > 0) {
        <div class="decision-cards-grid">
          <div class="decision-card highlight">
            <div class="decision-icon">
              <app-icon name="trend-up" [size]="18"></app-icon>
            </div>
            <div class="decision-content">
              <span class="decision-tag">SOURCE-REPORTED OUTCOMES</span>
              <p class="decision-text">{{ getBestChannelInsight() }}</p>
            </div>
          </div>
        </div>
      }

      <!-- Operations Research: the core decision surface -->
      <div class="or-card">
        <div class="or-card-header">
          <div class="decision-icon">
            <app-icon name="chart" [size]="18"></app-icon>
          </div>
          <div>
            <h3 class="or-card-title">Operations Research Engine</h3>
            <p class="or-card-sub">Deterministic budget scenarios and grounded suggestions. This is the core decision surface of the platform.</p>
          </div>
          <a routerLink="/operations-research" class="or-card-link">Open OR Engine</a>
        </div>
        @if (orSuggestions().length > 0) {
          <ul class="or-suggestion-list">
            @for (sug of orSuggestions(); track sug.title) {
              <li>
                <span class="or-severity" [attr.data-severity]="sug.severity">{{ sug.severity }}</span>
                <span class="or-suggestion-title">{{ sug.title }}</span>
              </li>
            }
          </ul>
        } @else {
          <p class="or-empty">No portfolio suggestion is available. Provider-reported outcomes are not a verified joint business objective; supported source-scoped entity scenarios remain separate.</p>
        }
      </div>

      @if (freshness().length) {
        <section class="freshness" aria-label="Data freshness" data-testid="data-freshness">
          <h3>Data freshness</h3>
          <ul>
            @for (r of freshness(); track r.provider + r.account) {
              <li [attr.data-state]="r.state"><strong>{{ r.label }}</strong> · {{ r.account }}: {{ r.text }}. <span class="freshness-note">{{ r.note }}</span></li>
            }
          </ul>
        </section>
      } @else if (freshnessError()) {
        <div class="state-note" role="note" data-testid="freshness-error">{{ freshnessError() }}</div>
      }

      @if (dq(); as q) {
        <section class="quality-card" aria-label="Data quality" data-testid="data-quality">
          <h3>Data quality</h3>
          <p class="dq-line"><strong>{{ q.portfolioLine }}</strong></p>
          @if (q.gate) { <p role="status" class="dq-gate" data-testid="dq-gate">{{ q.gate }}</p> }
          <ul>
            @for (c of q.channels; track c.provider) {
              <li><strong>{{ c.provider }}</strong>: {{ c.line }}@for (i of c.issues; track i) { <br><span class="freshness-note">{{ i }}</span> }</li>
            }
          </ul>
          <p class="freshness-note">{{ q.note }}</p>
        </section>
      } @else if (dqError()) {
        <div class="state-note" role="note" data-testid="dq-error">{{ dqError() }}</div>
      }

      @if (excludedNote() && !loadError()) {
        <div class="state-note" role="note" data-testid="excluded-note">{{ excludedNote() }}</div>
      }

      @if (isLoading()) {
        <div class="loading-state">
          <span class="spinner"></span>
          <span>Aggregating cross-channel advertising analytics...</span>
        </div>
      } @else if (loadError()) {
        <div class="state-error" role="alert" data-testid="overview-error">
          <strong>Analytics could not be shown.</strong>
          <span>{{ loadError() }}</span>
        </div>
      } @else if (summary().spend === 0 && platforms().length === 0) {
        <app-empty-state
          title="No Advertising Activity Recorded"
          description="Connect your Meta Ads, Google Ads, or TikTok Ads accounts under Connected Accounts to sync performance metrics."
        />
      } @else {
        <!-- Main Decision Graph & Breakdown Grid -->
        <div class="analytics-grid">
          <!-- Interactive Trend Decision Curve Graph -->
          <div class="panel-card chart-panel">
            <div class="panel-header">
              <div>
                <h3 class="panel-title">Cross-Channel Performance Trend</h3>
                <p class="panel-subtitle">Interactive daily performance trajectory over {{ timeline().length }} days.</p>
              </div>

              <!-- Metric Mode Selector -->
              <div class="metric-selector">
                <button
                  type="button"
                  class="metric-btn"
                  [class.active]="selectedMetric() === 'spend'"
                  (click)="selectedMetric.set('spend')"
                >
                  Spend ($)
                </button>
                <button
                  type="button"
                  class="metric-btn"
                  [class.active]="selectedMetric() === 'roas'"
                  (click)="selectedMetric.set('roas')"
                >
                  ROAS (x)
                </button>
                <button
                  type="button"
                  class="metric-btn"
                  [class.active]="selectedMetric() === 'clicks'"
                  (click)="selectedMetric.set('clicks')"
                >
                  Clicks
                </button>
                <button
                  type="button"
                  class="metric-btn"
                  [class.active]="selectedMetric() === 'conversions'"
                  (click)="selectedMetric.set('conversions')"
                >
                  Conversions
                </button>
              </div>
            </div>

            <!-- SVG Smooth Area Curve Graph -->
            <div class="interactive-chart-container" (mousemove)="onChartMouseMove($event)" (mouseleave)="hoverPoint.set(null)">
              <svg viewBox="0 0 600 200" class="curve-chart-svg" preserveAspectRatio="none">
                <defs>
                  <linearGradient id="curveAreaGrad" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#6366f1" stop-opacity="0.45"/>
                    <stop offset="70%" stop-color="#8b5cf6" stop-opacity="0.12"/>
                    <stop offset="100%" stop-color="#6366f1" stop-opacity="0"/>
                  </linearGradient>
                  <linearGradient id="curveLineGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                    <stop offset="0%" stop-color="#6366f1"/>
                    <stop offset="50%" stop-color="#8b5cf6"/>
                    <stop offset="100%" stop-color="#06b6d4"/>
                  </linearGradient>
                </defs>

                <!-- Horizontal Reference Gridlines -->
                <line x1="0" y1="180" x2="600" y2="180" stroke="var(--border-subtle)" stroke-width="1"/>
                <line x1="0" y1="120" x2="600" y2="120" stroke="var(--border-subtle)" stroke-width="0.75" stroke-dasharray="4,4"/>
                <line x1="0" y1="60" x2="600" y2="60" stroke="var(--border-subtle)" stroke-width="0.75" stroke-dasharray="4,4"/>

                <!-- Area Fill Path -->
                @if (chartAreaPath()) {
                  <path [attr.d]="chartAreaPath()" fill="url(#curveAreaGrad)"/>
                }

                <!-- Main Smooth Stroke Path -->
                @if (chartCurvePath()) {
                  <path
                    [attr.d]="chartCurvePath()"
                    fill="none"
                    stroke="url(#curveLineGrad)"
                    stroke-width="3"
                    stroke-linecap="round"
                  />
                }

                <!-- Active Hover Crosshair Line & Point -->
                @if (hoverPoint()) {
                  <line
                    [attr.x1]="hoverPoint()!.x"
                    y1="20"
                    [attr.x2]="hoverPoint()!.x"
                    y2="180"
                    stroke="#06b6d4"
                    stroke-width="1.5"
                    stroke-dasharray="3,3"
                  />
                  <circle
                    [attr.cx]="hoverPoint()!.x"
                    [attr.cy]="hoverPoint()!.y"
                    r="5"
                    fill="#06b6d4"
                    stroke="#ffffff"
                    stroke-width="2"
                  />
                }
              </svg>

              <!-- Floating Tooltip Box -->
              @if (hoverPoint() && hoverPoint()!.item) {
                <div
                  class="chart-tooltip"
                  [style.left.px]="tooltipX()"
                  [style.top.px]="tooltipY()"
                >
                  <div class="tooltip-date">{{ hoverPoint()!.item.date }}</div>
                  <div class="tooltip-metric">
                    <span class="tooltip-label">{{ selectedMetric() | uppercase }}:</span>
                    <span class="tooltip-val">{{ formatMetricValue(hoverPoint()!.item) }}</span>
                  </div>
                  <div class="tooltip-sub">
                    <span>Clicks: {{ hoverPoint()!.item.clicks }}</span> • <span>ROAS: {{ hoverPoint()!.item.roas === null ? 'Unknown' : hoverPoint()!.item.roas + 'x' }}</span>
                  </div>
                </div>
              }

              <div class="timeline-labels">
                <span>{{ timeline()[0]?.date }}</span>
                <span>Mid Period</span>
                <span>{{ timeline()[timeline().length - 1]?.date }}</span>
              </div>
            </div>
          </div>

          <!-- Platform Share of Voice & Comparison Breakdown -->
          <div class="panel-card breakdown-panel">
            <h3 class="panel-title">Channel Share & ROAS Comparison</h3>
            <p class="panel-subtitle">Performance breakdown by authorized network.</p>

            <div class="platform-list">
              @for (plat of platforms(); track plat.provider) {
                <div class="platform-item">
                  <div class="platform-item-header">
                    <div class="platform-identity">
                      <span [class]="'platform-badge ' + plat.provider">
                        <app-icon [name]="plat.provider" [size]="16"></app-icon>
                        <span>{{ plat.provider | uppercase }}</span>
                      </span>
                      <span class="platform-roas-pill">{{ plat.roas === null ? 'Outcome withheld' : plat.roas + 'x reported-value ROAS' }}</span>
                    </div>
                    <span class="platform-spend">{{ plat.spend | currency:reportingCurrency():'symbol':'1.2-2' }}</span>
                  </div>

                  <div class="progress-bar-bg">
                    <div
                      [class]="'progress-bar-fill ' + plat.provider"
                      [style.width.%]="summary().spend > 0 ? (plat.spend / summary().spend) * 100 : 0"
                    ></div>
                  </div>

                  <div class="platform-metrics-row">
                    <span>Clicks: <strong>{{ plat.clicks | number }}</strong></span>
                    <span>Conv: <strong>{{ plat.conversions === null ? 'Unknown' : (plat.conversions | number) }}</strong></span>
                    <span>Share: <strong>{{ summary().spend > 0 ? ((plat.spend / summary().spend) * 100 | number:'1.0-1') : 0 }}%</strong></span>
                  </div>
                </div>
              }
            </div>
          </div>
        </div>
      }
    </div>
  `,
  styles: [`
    .dashboard-page {
      display: flex;
      flex-direction: column;
      gap: 1.75rem;
    }

    .page-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1.5rem;
      flex-wrap: wrap;
    }

    .title {
      font-size: 1.5rem;
      font-weight: 700;
      color: var(--text-main);
    }

    .subtitle {
      font-size: 0.875rem;
      color: var(--text-muted);
      margin-top: 0.25rem;
    }

    .filters-row {
      display: flex;
      gap: 0.75rem;
      flex-wrap: wrap;
    }

    .btn-group {
      display: inline-flex;
      flex-wrap: wrap;
      max-width: 100%;
      background-color: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-md);
      padding: 0.25rem;
      gap: 0.2rem;
    }

    .filter-btn {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      white-space: nowrap;
      padding: 0.4rem 0.75rem;
      font-size: 0.8125rem;
      font-weight: 500;
      color: var(--text-muted);
      border-radius: var(--radius-sm);
      transition: all var(--transition-fast);
      cursor: pointer;
    }

    .filter-btn:hover {
      color: var(--text-main);
      background-color: var(--bg-subtle);
    }

    .filter-btn.active {
      color: #ffffff;
      background-color: var(--primary);
      font-weight: 600;
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1.25rem;
    }

    /* Decision Cards */
    .decision-cards-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
      gap: 1rem;
    }

    .decision-card {
      background-color: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 1rem 1.25rem;
      display: flex;
      align-items: center;
      gap: 1rem;
      box-shadow: var(--shadow-sm);
    }

    .decision-card.highlight {
      border-left: 4px solid var(--success);
      background: linear-gradient(90deg, rgba(16, 185, 129, 0.05) 0%, var(--bg-surface) 100%);
    }

    .decision-card.warning {
      border-left: 4px solid var(--warning);
      background: linear-gradient(90deg, rgba(245, 158, 11, 0.05) 0%, var(--bg-surface) 100%);
    }

    .decision-icon {
      width: 36px;
      height: 36px;
      border-radius: var(--radius-md);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .decision-card.highlight .decision-icon {
      background-color: var(--success-bg);
      color: var(--success);
    }

    .decision-card.warning .decision-icon {
      background-color: var(--warning-bg);
      color: var(--warning);
    }

    .decision-content {
      display: flex;
      flex-direction: column;
    }

    .decision-tag {
      font-size: 0.65rem;
      font-weight: 700;
      letter-spacing: 0.05em;
      color: var(--text-subtle);
    }

    .decision-text {
      font-size: 0.8125rem;
      font-weight: 600;
      color: var(--text-main);
      margin-top: 0.15rem;
    }

    /* Analytics Grid */
    .analytics-grid {
      display: grid;
      grid-template-columns: 2fr 1fr;
      gap: 1.5rem;
      align-items: start;
    }

    .panel-card {
      background-color: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      box-shadow: var(--shadow-sm);
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
    }

    .panel-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 1rem;
      flex-wrap: wrap;
    }

    .panel-title {
      font-size: 1.05rem;
      font-weight: 700;
      color: var(--text-main);
    }

    .panel-subtitle {
      font-size: 0.75rem;
      color: var(--text-muted);
      margin-top: 0.15rem;
    }

    .metric-selector {
      display: inline-flex;
      background-color: var(--bg-subtle);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-sm);
      padding: 0.2rem;
      gap: 0.2rem;
    }

    .metric-btn {
      padding: 0.3rem 0.6rem;
      font-size: 0.75rem;
      font-weight: 600;
      color: var(--text-muted);
      border-radius: 4px;
      cursor: pointer;
    }

    .metric-btn.active {
      background-color: var(--primary);
      color: #ffffff;
    }

    /* Interactive Chart */
    .interactive-chart-container {
      position: relative;
      width: 100%;
      height: 220px;
      display: flex;
      flex-direction: column;
    }

    .curve-chart-svg {
      width: 100%;
      height: 180px;
      overflow: visible;
    }

    .chart-tooltip {
      position: absolute;
      background-color: var(--bg-surface);
      border: 1px solid var(--border-strong);
      border-radius: var(--radius-md);
      padding: 0.5rem 0.75rem;
      box-shadow: var(--shadow-lg);
      pointer-events: none;
      z-index: 30;
      transform: translate(-50%, -110%);
      white-space: nowrap;
    }

    .tooltip-date {
      font-size: 0.6875rem;
      color: var(--text-subtle);
    }

    .tooltip-metric {
      font-size: 0.8125rem;
      font-weight: 700;
      color: var(--primary);
    }

    .tooltip-sub {
      font-size: 0.6875rem;
      color: var(--text-muted);
    }

    .timeline-labels {
      display: flex;
      justify-content: space-between;
      font-size: 0.75rem;
      color: var(--text-subtle);
      margin-top: auto;
      padding-top: 0.5rem;
    }

    /* Breakdown List */
    .platform-list {
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
    }

    .platform-item {
      display: flex;
      flex-direction: column;
      gap: 0.4rem;
    }

    .platform-item-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .platform-identity {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .platform-roas-pill {
      font-size: 0.6875rem;
      font-weight: 700;
      color: var(--success);
      background-color: var(--success-bg);
      padding: 0.15rem 0.45rem;
      border-radius: var(--radius-sm);
    }

    .platform-spend {
      font-weight: 700;
      font-size: 0.9375rem;
      color: var(--text-main);
    }

    .progress-bar-bg {
      height: 7px;
      background-color: var(--border-subtle);
      border-radius: var(--radius-full);
      overflow: hidden;
    }

    .progress-bar-fill {
      height: 100%;
      border-radius: var(--radius-full);
      transition: width 0.3s ease;
    }

    .progress-bar-fill { background: linear-gradient(90deg, #475569, #94a3b8); }
    .progress-bar-fill.meta { background: linear-gradient(90deg, #1877f2, #0062e0); }
    .progress-bar-fill.google { background: linear-gradient(90deg, #ea4335, #fbbc05); }
    .progress-bar-fill.tiktok { background: linear-gradient(90deg, #000000, #25f4ee); }

    .platform-metrics-row {
      display: flex;
      justify-content: space-between;
      font-size: 0.75rem;
      color: var(--text-muted);
    }

    .platform-badge {
      font-size: 0.6875rem;
      font-weight: 700;
      padding: 0.2rem 0.5rem;
      border-radius: 4px;
      color: #fff;
    }

    .platform-badge { background-color: #475569; color: #fff; }
    .platform-badge.meta { background-color: #1877f2; }
    .platform-badge.google { background-color: #ea4335; }
    .platform-badge.tiktok { background-color: #000000; }

    .state-error, .state-note { display:flex; flex-direction:column; gap:4px; padding:12px 16px; border-radius:8px; margin-bottom:12px; font-size:0.875rem; }
    .state-error { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
    .freshness { padding:12px 16px; border:1px solid #cbd5e1; border-radius:8px; margin-bottom:12px; font-size:0.875rem; background:#f8fafc; color:#334155; }
    .freshness h3 { margin:0 0 8px; font-size:0.95rem; }
    .freshness ul { margin:0; padding-left:18px; display:flex; flex-direction:column; gap:4px; overflow-wrap:anywhere; }
    .freshness-note { color:#64748b; }
    .quality-card { padding:12px 16px; border:1px solid #cbd5e1; border-radius:8px; margin-bottom:12px; font-size:0.875rem; background:#f8fafc; color:#334155; }
    .quality-card h3 { margin:0 0 8px; font-size:0.95rem; }
    .quality-card ul { margin:0 0 8px; padding-left:18px; display:flex; flex-direction:column; gap:6px; overflow-wrap:anywhere; }
    .quality-card .dq-line { margin:0 0 6px; }
    .quality-card .dq-gate { margin:0 0 8px; padding:8px 12px; border:1px solid #f59e0b; background:#fffbeb; border-radius:8px; color:#92400e; }
    .quality-card .freshness-note { color:#64748b; margin:0; }
    .state-note { background:#f1f5f9; border:1px solid #cbd5e1; color:#334155; }
    .loading-state {
      padding: 3rem;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 0.75rem;
      color: var(--text-muted);
    }

    .spinner {
      width: 26px;
      height: 26px;
      border: 2px solid var(--border-strong);
      border-top-color: var(--primary);
      border-radius: var(--radius-full);
      animation: spin 0.8s linear infinite;
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }

    @media (max-width: 900px) {
      .analytics-grid {
        grid-template-columns: 1fr;
      }
    }

    .or-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-left: 3px solid var(--primary);
      border-radius: var(--radius-md);
      padding: 1.25rem 1.5rem;
      margin-bottom: 1.5rem;
    }

    .or-card-header {
      display: flex;
      align-items: flex-start;
      gap: 0.875rem;
    }

    .or-card-title { font-size: 1rem; font-weight: 700; color: var(--text-main); }
    .or-card-sub { font-size: 0.8125rem; color: var(--text-muted); margin-top: 0.15rem; }

    .or-card-link {
      margin-left: auto;
      white-space: nowrap;
      font-size: 0.8125rem;
      font-weight: 700;
      color: var(--primary);
      text-decoration: none;
      padding: 0.45rem 0.9rem;
      border: 1px solid var(--primary);
      border-radius: var(--radius-sm);
    }

    .or-suggestion-list {
      list-style: none;
      margin: 1rem 0 0;
      padding: 0;
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }

    .or-suggestion-list li { display: flex; align-items: center; gap: 0.6rem; font-size: 0.875rem; }

    .or-severity {
      font-size: 0.6875rem;
      font-weight: 700;
      text-transform: uppercase;
      padding: 0.15rem 0.5rem;
      border-radius: var(--radius-sm);
      background: var(--bg-subtle);
      color: var(--text-muted);
    }

    .or-severity[data-severity='attention'] { background: var(--danger-bg); color: var(--danger); }
    .or-severity[data-severity='scenario'] { background: var(--primary-light); color: var(--primary); }
    .or-suggestion-title { color: var(--text-main); }
    .native-evidence{padding:18px;border:1px solid #cbd5e1;border-radius:12px;margin-bottom:20px;font-size:13px;overflow-wrap:anywhere}.native-group{border-top:1px solid #e2e8f0;padding-top:10px}.native-evidence p{line-height:1.6}
    .or-empty { margin-top: 0.75rem; font-size: 0.8125rem; color: var(--text-muted); }
  `]
})
export class DashboardFeatureComponent implements OnInit {
  private readonly http = inject(HttpClient);

  readonly isLoading = signal<boolean>(false);
  readonly loadError = signal<string | null>(null);
  readonly excludedNote = signal<string | null>(null);
  readonly orSuggestions = signal<OrSuggestionItem[]>([]);
  readonly selectedPlatform = signal<string>('');
  readonly dateRangePreset = signal<'7d' | '30d' | '90d'>('30d');
  private overviewRequest=0;
  readonly nativeEvidence=signal<any>(null);
  nativeGroups=()=>Object.entries(this.nativeEvidence()?.groups??{}).map(([key,value])=>({key,value:value as any}));
  nativeValue=(g:any,k:string)=>g.recorded_arithmetic?.metrics?.[k]?.decimal??'unknown';
  readonly outcomeReason=signal<string|null>(null);
  readonly selectedMetric = signal<'spend' | 'roas' | 'clicks' | 'conversions'>('spend');

  readonly summary = signal<OverviewSummary>({
    spend: 0,
    impressions: 0,
    clicks: 0,
    conversions: 0,
    conversion_value: 0,
    cpc: 0,
    cpm: 0,
    ctr: 0,
    roas: 0,
    cpa: 0,
  });

  readonly platforms = signal<PlatformItem[]>([]);
  readonly timeline = signal<TimelineItem[]>([]);
  readonly reportingCurrency = signal<string>('USD');

  // Hover Tooltip tracking
  readonly hoverPoint = signal<{ x: number; y: number; item: TimelineItem } | null>(null);
  readonly tooltipX = signal<number>(0);
  readonly tooltipY = signal<number>(0);

  ngOnInit(): void {
    this.fetchOverview();
    this.fetchOrSuggestions();
    this.fetchFreshness();
    this.fetchQuality();
  }

  readonly freshness = signal<FreshnessRow[]>([]);
  readonly freshnessError = signal<string | null>(null);

  fetchFreshness(): void {
    this.http.get<{ success: boolean; data: CoverageResponse }>('/api/v1/analytics/data-coverage').subscribe({
      next: (res) => {
        this.freshnessError.set(null);
        this.freshness.set(freshnessRows(res?.data, Date.now()));
      },
      error: () => {
        this.freshness.set([]);
        this.freshnessError.set('Data freshness is unavailable right now. It is unknown, not confirmed current.');
      },
    });
  }

  /** CPA is the server value (spend / provider-reported conversions). Withheld or undefined is shown as such, never as 0. With zero conversions CPA is undefined, even if the server returns 0. */
  cpaValue(): string {
    const s = this.summary();
    const c = s.cpa;
    if (c === null || c === undefined) { return this.outcomeReason() ? 'Withheld' : 'Not defined'; }
    if (!s.conversions) { return 'Not defined'; }
    return c + ' ' + this.reportingCurrency();
  }
  cpaSubtext(): string {
    const s = this.summary();
    const c = s.cpa;
    if (c === null || c === undefined) {
      return this.outcomeReason() ? 'Outcome definitions are not comparable in this scope' : 'No provider-reported conversions in this window, so cost per conversion is undefined';
    }
    if (!s.conversions) { return 'No provider-reported conversions in this window, so cost per conversion is undefined'; }
    return 'Approximate converted spend / provider-reported conversions, not verified sales';
  }

  readonly dq = signal<DqView | null>(null);
  readonly dqError = signal<string | null>(null);

  fetchQuality(): void {
    this.http.get<{ success: boolean; data: DqBlock }>('/api/v1/analytics/data-quality').subscribe({
      next: (res) => { this.dqError.set(null); this.dq.set(dqView(res?.data)); if (!this.dq()) this.dqError.set('Data quality is unavailable. It is unknown, not confirmed good.'); },
      error: () => { this.dq.set(null); this.dqError.set('Data quality is unavailable. It is unknown, not confirmed good.'); },
    });
  }

  fetchOrSuggestions(): void {
    this.http.get<{ success: boolean; data: { suggestions: OrSuggestionItem[] } }>('/api/v1/analytics/suggestions').subscribe({
      next: (res) => {
        const list = res?.data?.suggestions ?? [];
        this.orSuggestions.set(list.slice(0, 3));
      },
      error: () => this.orSuggestions.set([]),
    });
  }

  readonly platformOptions: ReadonlyArray<{ id: string; label: string }> = [
    { id: 'meta', label: 'Meta' }, { id: 'google', label: 'Google' }, { id: 'tiktok', label: 'TikTok' },
    { id: 'snapchat', label: 'Snapchat' }, { id: 'taboola', label: 'Taboola' }, { id: 'pinterest', label: 'Pinterest' },
    { id: 'reddit', label: 'Reddit' }, { id: 'spotify', label: 'Spotify' }, { id: 'linkedin', label: 'LinkedIn' },
    { id: 'microsoft', label: 'Microsoft' }, { id: 'amazon', label: 'Amazon' },
  ];

  setPlatform(platform: string): void {
    this.selectedPlatform.set(platform);
    this.fetchOverview();
  }

  setDatePreset(preset: '7d' | '30d' | '90d'): void {
    this.dateRangePreset.set(preset);
    this.fetchOverview();
  }

  fetchOverview(): void {
    const requestId=++this.overviewRequest;
    this.isLoading.set(true);this.nativeEvidence.set(null);this.loadError.set(null);this.excludedNote.set(null);

    const days = this.dateRangePreset() === '7d' ? 7 : (this.dateRangePreset() === '90d' ? 90 : 30);
    const end = new Date().toISOString().split('T')[0];
    const startDateObj = new Date();
    startDateObj.setDate(startDateObj.getDate() - days);
    const start = startDateObj.toISOString().split('T')[0];

    let url = `/api/v1/analytics/overview?start_date=${start}&end_date=${end}`;
    if (this.selectedPlatform()) {
      url += `&provider=${this.selectedPlatform()}`;
    }

    this.http.get<{
      summary: OverviewSummary & { primary_currency?: string };
      platforms: PlatformItem[];
      timeline: TimelineItem[];
      reporting_currency?: string;
      outcome_semantics?: {reason?:string|null};
      recorded_native_evidence?:any;
      excluded_providers?: {provider:string;reason:string}[];
    }>(url).subscribe({
      next: (res) => {
        if(requestId!==this.overviewRequest)return;
        this.isLoading.set(false);
        if (res.summary) {
          this.nativeEvidence.set(res.recorded_native_evidence??null);
          this.outcomeReason.set(res.outcome_semantics?.reason || null);
          this.excludedNote.set(res.excluded_providers?.[0]?.reason ?? null);
          this.summary.set(res.summary);
          this.platforms.set(res.platforms || []);
          this.timeline.set(res.timeline || []);
          this.reportingCurrency.set(res.reporting_currency || res.summary.primary_currency || 'USD');
        }
      },
      error: (err) => {
        if(requestId!==this.overviewRequest)return;
        this.isLoading.set(false);
        // Never show zeros as if they were data: clear stale numbers and show the server's reason.
        this.loadError.set(err?.error?.message || err?.error?.error?.message || 'The server could not return analytics for this selection. Try again or pick a single platform.');
        this.platforms.set([]);this.timeline.set([]);this.outcomeReason.set(null);
        this.summary.set({spend:0,impressions:0,clicks:0,conversions:0,conversion_value:0,cpc:0,cpm:0,ctr:0,roas:0} as OverviewSummary);
      }
    });
  }

  getBestChannelInsight(): string {
    if(this.outcomeReason())return 'Reported provider outcomes are not a comparable joint business objective. View each provider separately; no cross-provider best-channel verdict.';
    const list = this.platforms().filter(p=>p.roas!==null);
    if (list.length === 0) return 'Analyzing channel performance metrics...';
    const sorted = [...list].sort((a, b) => b.roas! - a.roas!);
    const best = sorted[0];
    return `${best.provider.toUpperCase()} shows the highest observed ROAS (${best.roas}x) in this period. This is an observed metric, not a recommendation; budget scenarios live in the OR engine below.`;
  }


  formatMetricValue(item: TimelineItem): string {
    const metric = this.selectedMetric();
    if(item[metric]===null)return 'Unknown';
    switch (metric) {
      case 'spend': return `${item.spend.toFixed(2)} ${this.reportingCurrency()}`;
      case 'roas': return `${item.roas}x`;
      case 'clicks': return `${item.clicks}`;
      case 'conversions': return `${item.conversions}`;
    }
  }

  onChartMouseMove(e: MouseEvent): void {
    const list = this.timeline();
    if (list.length === 0 || list.some(t=>t[this.selectedMetric()]===null)) {this.hoverPoint.set(null);return;}

    const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
    const mouseX = e.clientX - rect.left;
    const normX = Math.max(0, Math.min(1, mouseX / rect.width));

    const index = Math.min(list.length - 1, Math.round(normX * (list.length - 1)));
    const item = list[index];

    const maxVal = Math.max(...list.map(t => this.getVal(t)), 1);
    const stepX = 600 / Math.max(list.length - 1, 1);
    const x = Math.round(index * stepX);
    const y = Math.round(180 - (this.getVal(item) / maxVal) * 140);

    this.hoverPoint.set({ x, y, item });
    this.tooltipX.set(Math.round(normX * rect.width));
    this.tooltipY.set(Math.round((y / 200) * rect.height));
  }

  private getVal(t: TimelineItem): number {
    const m = this.selectedMetric();
    return (t as any)[m] || 0;
  }

  chartCurvePath(): string {
    const list = this.timeline();
    if (list.length === 0 || list.some(t=>t[this.selectedMetric()]===null)) return '';

    const maxVal = Math.max(...list.map(t => this.getVal(t)), 1);
    const stepX = 600 / Math.max(list.length - 1, 1);

    const points = list.map((item, idx) => ({
      x: Math.round(idx * stepX),
      y: Math.round(180 - (this.getVal(item) / maxVal) * 140)
    }));

    if (points.length === 1) return `M0,${points[0].y} L600,${points[0].y}`;

    let path = `M ${points[0].x} ${points[0].y}`;
    for (let i = 0; i < points.length - 1; i++) {
      const p0 = points[i];
      const p1 = points[i + 1];
      const mx = (p0.x + p1.x) / 2;
      path += ` C ${mx} ${p0.y}, ${mx} ${p1.y}, ${p1.x} ${p1.y}`;
    }

    return path;
  }

  chartAreaPath(): string {
    const linePath = this.chartCurvePath();
    if (!linePath) return '';
    return `${linePath} L 600 180 L 0 180 Z`;
  }
}
