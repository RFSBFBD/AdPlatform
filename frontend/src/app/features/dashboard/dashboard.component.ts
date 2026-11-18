import { Component, inject, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { StatCardComponent } from '../../shared/components/stat-card/stat-card.component';
import { EmptyStateComponent } from '../../shared/components/empty-state/empty-state.component';
import { BadgeComponent } from '../../shared/components/badge/badge.component';
import { ButtonComponent } from '../../shared/components/button/button.component';

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

export interface PlatformItem {
  provider: 'meta' | 'google' | 'tiktok';
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
  imports: [CommonModule, StatCardComponent, EmptyStateComponent],
  template: `
    <div class="dashboard-page">
      <!-- Header with Filters -->
      <div class="page-header">
        <div>
          <h2 class="title">Performance Overview</h2>
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
              All Platforms
            </button>
            <button 
              type="button" 
              class="filter-btn" 
              [class.active]="selectedPlatform() === 'meta'"
              (click)="setPlatform('meta')"
            >
              Meta
            </button>
            <button 
              type="button" 
              class="filter-btn" 
              [class.active]="selectedPlatform() === 'google'"
              (click)="setPlatform('google')"
            >
              Google
            </button>
            <button 
              type="button" 
              class="filter-btn" 
              [class.active]="selectedPlatform() === 'tiktok'"
              (click)="setPlatform('tiktok')"
            >
              TikTok
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
          [value]="(summary().spend | currency:'USD':'symbol':'1.2-2') || '$0.00'" 
          subtext="Actual spend across connected accounts" 
        />
        <app-stat-card 
          title="Total Impressions" 
          [value]="(summary().impressions | number) || '0'" 
          subtext="Direct provider impressions" 
        />
        <app-stat-card 
          title="Total Clicks" 
          [value]="(summary().clicks | number) || '0'" 
          [subtext]="'CTR: ' + summary().ctr + '% | CPC: $' + summary().cpc" 
        />
        <app-stat-card 
          title="Blended ROAS" 
          [value]="summary().roas + 'x'" 
          [subtext]="'Conversions: ' + (summary().conversions | number) + ' | CPA: $' + summary().cpa" 
        />
      </div>

      @if (isLoading()) {
        <div class="loading-state">Refreshing analytics data...</div>
      } @else if (summary().spend === 0 && platforms().length === 0) {
        <app-empty-state
          title="No Advertising Activity Recorded"
          description="Connect your Meta Ads, Google Ads, or TikTok Ads accounts under Connected Accounts to sync performance metrics."
        />
      } @else {
        <!-- Platform Breakdown & Trend Section -->
        <div class="analytics-grid">
          <!-- Platform Performance Breakdown -->
          <div class="panel-card">
            <h3 class="panel-title">Platform Performance Breakdown</h3>
            <div class="platform-list">
              @for (plat of platforms(); track plat.provider) {
                <div class="platform-item">
                  <div class="platform-item-header">
                    <span [class]="'platform-badge ' + plat.provider">{{ plat.provider | uppercase }}</span>
                    <span class="platform-spend">{{ plat.spend | currency:'USD':'symbol':'1.2-2' }}</span>
                  </div>
                  <div class="platform-metrics-row">
                    <span>Clicks: <strong>{{ plat.clicks | number }}</strong></span>
                    <span>Conversions: <strong>{{ plat.conversions | number }}</strong></span>
                    <span>ROAS: <strong>{{ plat.roas }}x</strong></span>
                  </div>
                  <div class="progress-bar-bg">
                    <div 
                      class="progress-bar-fill" 
                      [style.width.%]="summary().spend > 0 ? (plat.spend / summary().spend) * 100 : 0"
                    ></div>
                  </div>
                </div>
              }
            </div>
          </div>

          <!-- Trend Timeline -->
          <div class="panel-card">
            <h3 class="panel-title">Daily Spend Trend ({{ timeline().length }} Days)</h3>
            @if (timeline().length > 0) {
              <div class="timeline-chart">
                <svg viewBox="0 0 500 150" class="chart-svg">
                  <!-- Grid Lines -->
                  <line x1="0" y1="130" x2="500" y2="130" stroke="var(--border-color)" stroke-width="1"/>
                  <line x1="0" y1="70" x2="500" y2="70" stroke="var(--border-color)" stroke-width="0.5" stroke-dasharray="3,3"/>
                  
                  <!-- Area & Line Graph -->
                  <polyline
                    fill="none"
                    stroke="var(--color-primary, #6366f1)"
                    stroke-width="2.5"
                    [attr.points]="chartPoints()"
                  />
                </svg>
                <div class="timeline-labels">
                  <span>{{ timeline()[0]?.date }}</span>
                  <span>{{ timeline()[timeline().length - 1]?.date }}</span>
                </div>
              </div>
            } @else {
              <div class="no-data">No daily time-series metrics recorded yet.</div>
            }
          </div>
        </div>
      }
    </div>
  `,
  styles: [`
    .dashboard-page {
      display: flex;
      flex-direction: column;
      gap: 1.5rem;
    }

    .page-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 1rem;
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
      border: 1px solid var(--border-color);
      border-radius: var(--border-radius-md, 6px);
      overflow: hidden;
      background: var(--bg-surface);
    }

    .filter-btn {
      padding: 0.375rem 0.75rem;
      font-size: 0.8125rem;
      font-weight: 500;
      border: none;
      background: transparent;
      color: var(--text-muted);
      cursor: pointer;
      transition: all 0.15s ease;

      &:hover {
        background: var(--bg-surface-hover);
        color: var(--text-main);
      }

      &.active {
        background: var(--color-primary, #6366f1);
        color: #ffffff;
      }
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 1rem;
    }

    .analytics-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
      gap: 1.5rem;
    }

    .panel-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-color);
      border-radius: var(--border-radius-md, 8px);
      padding: 1.25rem;
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .panel-title {
      font-size: 1rem;
      font-weight: 600;
      color: var(--text-main);
    }

    .platform-list {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .platform-item {
      display: flex;
      flex-direction: column;
      gap: 0.375rem;
    }

    .platform-item-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .platform-spend {
      font-weight: 600;
      font-size: 0.9375rem;
      color: var(--text-main);
    }

    .platform-metrics-row {
      display: flex;
      gap: 1rem;
      font-size: 0.75rem;
      color: var(--text-muted);
    }

    .progress-bar-bg {
      height: 6px;
      background: var(--border-color);
      border-radius: 3px;
      overflow: hidden;
      margin-top: 0.25rem;
    }

    .progress-bar-fill {
      height: 100%;
      background: var(--color-primary, #6366f1);
      border-radius: 3px;
    }

    .timeline-chart {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }

    .chart-svg {
      width: 100%;
      height: 150px;
    }

    .timeline-labels {
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

      &.meta { background-color: #0866ff; }
      &.google { background-color: #ea4335; }
      &.tiktok { background-color: #000000; }
    }

    .loading-state, .no-data {
      padding: 2rem;
      text-align: center;
      color: var(--text-muted);
    }
  `]
})
export class DashboardFeatureComponent implements OnInit {
  private readonly http = inject(HttpClient);

  readonly isLoading = signal<boolean>(false);
  readonly selectedPlatform = signal<string>('');
  readonly dateRangePreset = signal<'7d' | '30d' | '90d'>('30d');

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

  ngOnInit(): void {
    this.fetchOverview();
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
      summary: OverviewSummary;
      platforms: PlatformItem[];
      timeline: TimelineItem[];
    }>(url).subscribe({
      next: (res) => {
        this.isLoading.set(false);
        if (res.summary) {
          this.summary.set(res.summary);
          this.platforms.set(res.platforms || []);
          this.timeline.set(res.timeline || []);
        }
      },
      error: () => {
        this.isLoading.set(false);
      }
    });
  }

  chartPoints(): string {
    const list = this.timeline();
    if (list.length === 0) return '0,130 500,130';

    const maxSpend = Math.max(...list.map(t => t.spend), 1);
    const stepX = 500 / Math.max(list.length - 1, 1);

    return list.map((item, idx) => {
      const x = Math.round(idx * stepX);
      const y = Math.round(130 - (item.spend / maxSpend) * 110);
      return `${x},${y}`;
    }).join(' ');
  }
}
