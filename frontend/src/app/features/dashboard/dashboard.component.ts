import { Component, inject, OnInit, signal, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { HttpClient } from '@angular/common/http';
import { StatCardComponent } from '../../shared/components/stat-card/stat-card.component';
import { EmptyStateComponent } from '../../shared/components/empty-state/empty-state.component';
import { BadgeComponent } from '../../shared/components/badge/badge.component';
import { IconComponent } from '../../shared/components/icon/icon.component';

export interface OverviewSummary {
  spend: number;
  impressions: number;
  clicks: number;
  conversions: number;
  conversion_value: number;
  cpc: number;
  cpm: number;
  ctr: number;
  roas: number;
  cpa: number;
}

export interface OrSuggestionItem {
  title: string;
  severity: string;
  kind: string;
}

export interface PlatformItem {
  provider: 'meta' | 'google' | 'tiktok' | 'snapchat';
  spend: number;
  impressions: number;
  clicks: number;
  conversions: number;
  roas: number;
}

export interface TimelineItem {
  date: string;
  spend: number;
  clicks: number;
  conversions: number;
  roas: number;
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
          <p class="subtitle">Unified cross-channel metrics aggregated directly from official provider APIs.</p>
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
            <button 
              type="button" 
              class="filter-btn" 
              [class.active]="selectedPlatform() === 'meta'"
              (click)="setPlatform('meta')"
            >
              <app-icon name="meta" [size]="15"></app-icon>
              <span>Meta</span>
            </button>
            <button 
              type="button" 
              class="filter-btn" 
              [class.active]="selectedPlatform() === 'google'"
              (click)="setPlatform('google')"
            >
              <app-icon name="google" [size]="15"></app-icon>
              <span>Google</span>
            </button>
            <button 
              type="button" 
              class="filter-btn" 
              [class.active]="selectedPlatform() === 'tiktok'"
              (click)="setPlatform('tiktok')"
            >
              <app-icon name="tiktok" [size]="15"></app-icon>
              <span>TikTok</span>
            </button>
            <button 
              type="button" 
              class="filter-btn" 
              [class.active]="selectedPlatform() === 'snapchat'"
              (click)="setPlatform('snapchat')"
            >
              <span>Snapchat</span>
            </button>
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

      <!-- Quick Metrics Grid -->
      <div class="stats-grid">
        <app-stat-card 
          title="Total Ad Spend" 
          [value]="(summary().spend | currency:reportingCurrency():'symbol':'1.2-2') || '0.00'" 
          [subtext]="'Synced provider spend in reporting currency (' + reportingCurrency() + ')'" 
        />
        <app-stat-card 
          title="Total Impressions" 
          [value]="(summary().impressions | number) || '0'" 
          subtext="Verified provider reach" 
        />
        <app-stat-card 
          title="Total Clicks" 
          [value]="(summary().clicks | number) || '0'" 
          [subtext]="'CTR: ' + summary().ctr + '% • CPC: ' + summary().cpc + ' ' + reportingCurrency()" 
        />
        <app-stat-card 
          title="Blended ROAS" 
          [value]="summary().roas + 'x'" 
          [subtext]="'Conversions: ' + (summary().conversions | number) + ' • CPA: ' + summary().cpa + ' ' + reportingCurrency()" 
        />
      </div>

      <!-- Smart Decision Engine Cards -->
      @if (summary().spend > 0) {
        <div class="decision-cards-grid">
          <div class="decision-card highlight">
            <div class="decision-icon">
              <app-icon name="trend-up" [size]="18"></app-icon>
            </div>
            <div class="decision-content">
              <span class="decision-tag">TOP OBSERVED ROAS</span>
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
          <p class="or-empty">No grounded suggestions yet. Connect an ad account and sync, then the engine reads the same data here.</p>
        }
      </div>

      @if (isLoading()) {
        <div class="loading-state">
          <span class="spinner"></span>
          <span>Aggregating cross-channel advertising analytics...</span>
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
                    <span>Clicks: {{ hoverPoint()!.item.clicks }}</span> • <span>ROAS: {{ hoverPoint()!.item.roas }}x</span>
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
                      <span class="platform-roas-pill">{{ plat.roas }}x ROAS</span>
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
                    <span>Conv: <strong>{{ plat.conversions | number }}</strong></span>
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
      background-color: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-md);
      padding: 0.25rem;
      gap: 0.2rem;
    }

    .filter-btn {
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

    .platform-badge.meta { background-color: #1877f2; }
    .platform-badge.google { background-color: #ea4335; }
    .platform-badge.tiktok { background-color: #000000; }

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
    .or-empty { margin-top: 0.75rem; font-size: 0.8125rem; color: var(--text-muted); }
  `]
})
export class DashboardFeatureComponent implements OnInit {
  private readonly http = inject(HttpClient);

  readonly isLoading = signal<boolean>(false);
  readonly orSuggestions = signal<OrSuggestionItem[]>([]);
  readonly selectedPlatform = signal<string>('');
  readonly dateRangePreset = signal<'7d' | '30d' | '90d'>('30d');
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

  setPlatform(platform: string): void {
    this.selectedPlatform.set(platform);
    this.fetchOverview();
  }

  setDatePreset(preset: '7d' | '30d' | '90d'): void {
    this.dateRangePreset.set(preset);
    this.fetchOverview();
  }

  fetchOverview(): void {
    this.isLoading.set(true);

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
    }>(url).subscribe({
      next: (res) => {
        this.isLoading.set(false);
        if (res.summary) {
          this.summary.set(res.summary);
          this.platforms.set(res.platforms || []);
          this.timeline.set(res.timeline || []);
          this.reportingCurrency.set(res.reporting_currency || res.summary.primary_currency || 'USD');
        }
      },
      error: () => {
        this.isLoading.set(false);
      }
    });
  }

  getBestChannelInsight(): string {
    const list = this.platforms();
    if (list.length === 0) return 'Analyzing channel performance metrics...';
    const sorted = [...list].sort((a, b) => b.roas - a.roas);
    const best = sorted[0];
    return `${best.provider.toUpperCase()} shows the highest observed ROAS (${best.roas}x) in this period. This is an observed metric, not a recommendation; budget scenarios live in the OR engine below.`;
  }


  formatMetricValue(item: TimelineItem): string {
    const metric = this.selectedMetric();
    switch (metric) {
      case 'spend': return `${item.spend.toFixed(2)} ${this.reportingCurrency()}`;
      case 'roas': return `${item.roas}x`;
      case 'clicks': return `${item.clicks}`;
      case 'conversions': return `${item.conversions}`;
    }
  }

  onChartMouseMove(e: MouseEvent): void {
    const list = this.timeline();
    if (list.length === 0) return;

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
    if (list.length === 0) return '';

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
