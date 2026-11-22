import { Component, inject, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { EmptyStateComponent } from '../../shared/components/empty-state/empty-state.component';
import { BadgeComponent } from '../../shared/components/badge/badge.component';
import { ButtonComponent } from '../../shared/components/button/button.component';
import { ModalComponent } from '../../shared/components/modal/modal.component';
import { IconComponent } from '../../shared/components/icon/icon.component';

export interface CampaignListItem {
  id: number;
  external_campaign_id: string;
  name: string;
  provider: 'meta' | 'google' | 'tiktok' | 'snapchat';
  status: string;
  objective: string | null;
  daily_budget: number | null;
  lifetime_budget: number | null;
  currency: string;
  account_name: string;
  metrics: {
    spend: number;
    impressions: number;
    clicks: number;
    conversions: number;
    conversion_value: number;
    roas: number;
    cpc: number;
    ctr: number;
  };
}

@Component({
  selector: 'app-campaigns-feature',
  standalone: true,
  imports: [CommonModule, FormsModule, EmptyStateComponent, BadgeComponent, ButtonComponent, ModalComponent, IconComponent],
  template: `
    <div class="campaigns-page">
      <!-- Header -->
      <div class="page-header">
        <div>
          <h2 class="title">Campaigns</h2>
          <p class="subtitle">Multi-platform advertising campaigns across Meta, Google, TikTok and Snapchat Ads.</p>
        </div>
      </div>

      <!-- Controls & Search Bar -->
      <div class="controls-card">
        <div class="search-box">
          <input 
            type="text" 
            placeholder="Search campaigns by name or external ID..." 
            [(ngModel)]="searchQuery" 
            (keyup.enter)="loadCampaigns()"
            class="form-input input-search" 
          />
          <app-button variant="primary" size="sm" (clicked)="loadCampaigns()">Search</app-button>
        </div>

        <div class="filter-group">
          <!-- Platform Filter -->
          <select [(ngModel)]="selectedProvider" (change)="loadCampaigns()" class="form-input select-filter">
            <option value="">All Platforms</option>
            <option value="meta">Meta Ads</option>
            <option value="google">Google Ads</option>
            <option value="tiktok">TikTok Ads</option>
          </select>

          <!-- Status Filter -->
          <select [(ngModel)]="selectedStatus" (change)="loadCampaigns()" class="form-input select-filter">
            <option value="">All Statuses</option>
            <option value="ACTIVE">Active</option>
            <option value="PAUSED">Paused</option>
          </select>
        </div>
      </div>

      <!-- Campaign List Table -->
      @if (isLoading()) {
        <div class="loading-state">Loading campaigns...</div>
      } @else if (campaigns().length > 0) {
        <div class="table-card">
          <table class="campaigns-table">
            <thead>
              <tr>
                <th>Campaign</th>
                <th>Platform</th>
                <th>Account</th>
                <th>Status</th>
                <th>Spend</th>
                <th>Impressions</th>
                <th>Clicks</th>
                <th>Conversions</th>
                <th>ROAS</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              @for (camp of campaigns(); track camp.id) {
                <tr class="campaign-row" (click)="openDetails(camp)">
                  <td>
                    <div class="campaign-info">
                      <span class="campaign-name">{{ camp.name }}</span>
                      <span class="campaign-meta">ID: {{ camp.external_campaign_id }} • Obj: {{ camp.objective || 'N/A' }}</span>
                    </div>
                  </td>
                  <td>
                    <span [class]="'platform-badge ' + camp.provider">
                      <app-icon [name]="camp.provider" [size]="15"></app-icon>
                      <span>{{ camp.provider | uppercase }}</span>
                    </span>
                  </td>
                  <td class="account-cell">{{ camp.account_name || 'N/A' }}</td>
                  <td>
                    <app-badge [variant]="camp.status === 'ACTIVE' || camp.status === 'ENABLED' ? 'success' : 'neutral'">
                      {{ camp.status }}
                    </app-badge>
                  </td>
                  <td class="font-semibold">{{ camp.metrics.spend | currency:camp.currency:'symbol':'1.2-2' }}</td>
                  <td>{{ camp.metrics.impressions | number }}</td>
                  <td>{{ camp.metrics.clicks | number }}</td>
                  <td>{{ camp.metrics.conversions | number }}</td>
                  <td class="roas-cell">{{ camp.metrics.roas }}x</td>
                  <td>
                    <app-button variant="ghost" size="sm" (clicked)="$event.stopPropagation(); openDetails(camp)">
                      Details
                    </app-button>
                  </td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      } @else {
        <app-empty-state
          title="No Campaigns Found"
          description="No advertising campaigns match the current filter criteria or have been synchronized yet."
        />
      }

      <!-- Campaign Drilldown Modal -->
      <app-modal [isOpen]="isModalOpen()" [title]="selectedCampaign()?.name || 'Campaign Details'" (close)="isModalOpen.set(false)">
        @if (selectedCampaign(); as camp) {
          <div class="drilldown-content">
            <div class="drilldown-summary">
              <div><strong>Platform:</strong> {{ camp.provider | uppercase }}</div>
              <div><strong>Account:</strong> {{ camp.account_name }}</div>
              <div><strong>Status:</strong> {{ camp.status }}</div>
              <div><strong>Currency:</strong> {{ camp.currency }}</div>
              <div><strong>Daily Budget:</strong> {{ camp.daily_budget ? (camp.daily_budget | currency:camp.currency) : 'Unset' }}</div>
              <div><strong>External ID:</strong> <code>{{ camp.external_campaign_id }}</code></div>
            </div>

            <h4 class="metrics-subheading">Aggregated Performance (Selected Window)</h4>
            <div class="metrics-pill-grid">
              <div class="metric-box">
                <span class="label">Spend</span>
                <span class="val">{{ camp.metrics.spend | currency:camp.currency:'symbol':'1.2-2' }}</span>
              </div>
              <div class="metric-box">
                <span class="label">Clicks</span>
                <span class="val">{{ camp.metrics.clicks | number }}</span>
              </div>
              <div class="metric-box">
                <span class="label">Conversions</span>
                <span class="val">{{ camp.metrics.conversions | number }}</span>
              </div>
              <div class="metric-box">
                <span class="label">ROAS</span>
                <span class="val">{{ camp.metrics.roas }}x</span>
              </div>
              <div class="metric-box">
                <span class="label">Avg CPC</span>
                <span class="val">{{ camp.metrics.cpc | currency:camp.currency:'symbol':'1.2-2' }}</span>
              </div>
              <div class="metric-box">
                <span class="label">Avg CTR</span>
                <span class="val">{{ camp.metrics.ctr }}%</span>
              </div>
            </div>
          </div>
        }
        <div footer>
          <app-button variant="outline" size="sm" (clicked)="isModalOpen.set(false)">Close</app-button>
        </div>
      </app-modal>
    </div>
  `,
  styles: [`
    .campaigns-page {
      display: flex;
      flex-direction: column;
      gap: 1.5rem;
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

    .controls-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-color);
      border-radius: var(--border-radius-md, 8px);
      padding: 1rem;
      display: flex;
      justify-content: space-between;
      gap: 1rem;
      flex-wrap: wrap;
    }

    .search-box {
      display: flex;
      gap: 0.5rem;
      flex: 1;
      min-width: 280px;
    }

    .input-search {
      flex: 1;
      padding: 0.5rem 0.75rem;
      border: 1px solid var(--border-color);
      border-radius: var(--border-radius-sm, 6px);
      background: var(--bg-surface-hover);
      color: var(--text-main);
      font-size: 0.875rem;

      &:focus {
        outline: none;
        border-color: var(--color-primary, #6366f1);
      }
    }

    .filter-group {
      display: flex;
      gap: 0.75rem;
    }

    .select-filter {
      padding: 0.5rem 0.75rem;
      border: 1px solid var(--input-border, var(--border-color));
      border-radius: var(--border-radius-sm, 6px);
      background-color: var(--input-bg, #131b2e);
      color: var(--input-text, #f8fafc);
      font-size: 0.875rem;
      cursor: pointer;

      option {
        background-color: var(--bg-surface, #0f172a);
        color: var(--text-main, #f8fafc);
        padding: 0.5rem;
      }

      &:focus {
        outline: none;
        border-color: var(--color-primary, #6366f1);
      }
    }

    .table-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-color);
      border-radius: var(--border-radius-md, 8px);
      overflow-x: auto;
    }

    .campaigns-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
      font-size: 0.875rem;

      th {
        padding: 0.75rem 1rem;
        background: var(--bg-surface-hover);
        border-bottom: 1px solid var(--border-color);
        color: var(--text-muted);
        font-weight: 600;
        white-space: nowrap;
      }

      td {
        padding: 0.875rem 1rem;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-main);
      }

      tr.campaign-row:hover {
        background-color: var(--bg-surface-hover);
        cursor: pointer;
      }
    }

    .campaign-info {
      display: flex;
      flex-direction: column;
      gap: 0.2rem;
    }

    .campaign-name {
      font-weight: 600;
      color: var(--text-main);
    }

    .campaign-meta {
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

    .account-cell {
      color: var(--text-muted);
      font-size: 0.8125rem;
    }

    .font-semibold {
      font-weight: 600;
    }

    .roas-cell {
      font-weight: 700;
      color: var(--success, #10b981);
    }

    .loading-state {
      padding: 3rem;
      text-align: center;
      color: var(--text-muted);
    }

    .drilldown-content {
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
    }

    .drilldown-summary {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 0.5rem;
      font-size: 0.875rem;
      padding-bottom: 1rem;
      border-bottom: 1px solid var(--border-color);
    }

    .metrics-subheading {
      font-size: 0.9375rem;
      font-weight: 600;
      color: var(--text-main);
    }

    .metrics-pill-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 0.75rem;
    }

    .metric-box {
      background: var(--bg-surface-hover);
      border-radius: 6px;
      padding: 0.75rem;
      display: flex;
      flex-direction: column;
      gap: 0.25rem;

      .label {
        font-size: 0.75rem;
        color: var(--text-muted);
      }

      .val {
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-main);
      }
    }
  `]
})
export class CampaignsFeatureComponent implements OnInit {
  private readonly http = inject(HttpClient);

  readonly campaigns = signal<CampaignListItem[]>([]);
  readonly isLoading = signal<boolean>(false);
  readonly selectedCampaign = signal<CampaignListItem | null>(null);
  readonly isModalOpen = signal<boolean>(false);

  searchQuery = '';
  selectedProvider = '';
  selectedStatus = '';

  ngOnInit(): void {
    this.loadCampaigns();
  }

  loadCampaigns(): void {
    this.isLoading.set(true);

    let url = `/api/v1/analytics/campaigns?per_page=50`;
    if (this.searchQuery.trim()) {
      url += `&search=${encodeURIComponent(this.searchQuery.trim())}`;
    }
    if (this.selectedProvider) {
      url += `&provider=${this.selectedProvider}`;
    }
    if (this.selectedStatus) {
      url += `&status=${this.selectedStatus}`;
    }

    this.http.get<{ data: CampaignListItem[] }>(url).subscribe({
      next: (res) => {
        this.isLoading.set(false);
        this.campaigns.set(res.data || []);
      },
      error: () => {
        this.isLoading.set(false);
      }
    });
  }

  openDetails(camp: CampaignListItem): void {
    this.selectedCampaign.set(camp);
    this.isModalOpen.set(true);
  }
}
