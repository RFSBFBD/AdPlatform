import { Component, inject, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { ButtonComponent } from '../../shared/components/button/button.component';
import { BadgeComponent } from '../../shared/components/badge/badge.component';
import { EmptyStateComponent } from '../../shared/components/empty-state/empty-state.component';
import { ModalComponent } from '../../shared/components/modal/modal.component';

export interface ConnectedAccountDto {
  id: number;
  uuid: string;
  provider: 'meta' | 'google' | 'tiktok';
  external_account_id: string;
  account_name: string;
  currency: string;
  timezone: string;
  status: 'active' | 'expired' | 'revoked' | 'error' | 'rate_limited';
  last_synced_at: string | null;
  created_at: string;
}

export interface SyncRunDto {
  id: number;
  sync_type: string;
  status: string;
  started_at: string;
  completed_at: string | null;
  records_processed: number;
  error_message: string | null;
}

@Component({
  selector: 'app-accounts-feature',
  standalone: true,
  imports: [CommonModule, ButtonComponent, BadgeComponent, EmptyStateComponent, ModalComponent],
  template: `
    <div class="accounts-page">
      <div class="page-header">
        <div>
          <h2 class="title">Connected Advertising Accounts</h2>
          <p class="subtitle">Direct, official API integrations with authorized advertising accounts.</p>
        </div>
      </div>

      <!-- Provider Connect Cards -->
      <div class="providers-grid">
        <!-- Meta Ads -->
        <div class="provider-card">
          <div class="provider-header">
            <span class="provider-name">Meta Ads</span>
            <span class="provider-type">Facebook & Instagram</span>
          </div>
          <p class="provider-desc">Connect Ad Accounts and Business assets via official Meta Marketing API.</p>
          <div class="card-footer">
            <app-button 
              variant="primary" 
              size="sm" 
              [loading]="connectingProvider() === 'meta'"
              (clicked)="initiateConnect('meta')"
            >
              Connect Meta Account
            </app-button>
          </div>
        </div>

        <!-- Google Ads -->
        <div class="provider-card">
          <div class="provider-header">
            <span class="provider-name">Google Ads</span>
            <span class="provider-type">Search, YouTube, Display</span>
          </div>
          <p class="provider-desc">Connect customer accounts or MCC managers via official Google Ads API.</p>
          <div class="card-footer">
            <app-button 
              variant="primary" 
              size="sm" 
              [loading]="connectingProvider() === 'google'"
              (clicked)="initiateConnect('google')"
            >
              Connect Google Account
            </app-button>
          </div>
        </div>

        <!-- TikTok Ads -->
        <div class="provider-card">
          <div class="provider-header">
            <span class="provider-name">TikTok Ads</span>
            <span class="provider-type">TikTok for Business</span>
          </div>
          <p class="provider-desc">Connect advertiser accounts via official TikTok Marketing API.</p>
          <div class="card-footer">
            <app-button 
              variant="primary" 
              size="sm" 
              [loading]="connectingProvider() === 'tiktok'"
              (clicked)="initiateConnect('tiktok')"
            >
              Connect TikTok Account
            </app-button>
          </div>
        </div>
      </div>

      <!-- Connected Accounts List -->
      <div class="section-container">
        <h3 class="section-title">Active Connections ({{ accounts().length }})</h3>

        @if (isLoading()) {
          <div class="loading-state">Loading connected accounts...</div>
        } @else if (accounts().length > 0) {
          <div class="table-card">
            <table class="accounts-table">
              <thead>
                <tr>
                  <th>Platform</th>
                  <th>Account Name</th>
                  <th>Account ID</th>
                  <th>Currency & Timezone</th>
                  <th>Status</th>
                  <th>Last Synced</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                @for (acc of accounts(); track acc.id) {
                  <tr>
                    <td>
                      <span [class]="'platform-badge ' + acc.provider">
                        {{ acc.provider | uppercase }}
                      </span>
                    </td>
                    <td class="font-medium">{{ acc.account_name }}</td>
                    <td class="mono-text">{{ acc.external_account_id }}</td>
                    <td>{{ acc.currency }} / {{ acc.timezone }}</td>
                    <td>
                      <app-badge [variant]="acc.status === 'active' ? 'success' : (acc.status === 'rate_limited' ? 'warning' : 'danger')">
                        {{ acc.status }}
                      </app-badge>
                    </td>
                    <td>{{ acc.last_synced_at ? (acc.last_synced_at | date:'short') : 'Pending initial sync' }}</td>
                    <td>
                      <div class="action-buttons">
                        <app-button 
                          variant="outline" 
                          size="sm" 
                          [loading]="syncingAccountId() === acc.id"
                          (clicked)="triggerSync(acc.id)"
                        >
                          Sync Now
                        </app-button>
                        <app-button 
                          variant="ghost" 
                          size="sm" 
                          (clicked)="viewHistory(acc)"
                        >
                          History
                        </app-button>
                        <button 
                          type="button" 
                          class="disconnect-btn" 
                          (click)="disconnect(acc.id)"
                        >
                          Disconnect
                        </button>
                      </div>
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        } @else {
          <app-empty-state
            title="No Accounts Connected"
            description="Authorize a provider above to discover your advertising accounts and begin syncing real performance data."
          />
        }
      </div>

      <!-- Connect Info Modal -->
      <app-modal [isOpen]="isModalOpen()" [title]="modalTitle()" (close)="isModalOpen.set(false)">
        <p>{{ modalMessage() }}</p>
        <div footer>
          <app-button variant="outline" size="sm" (clicked)="isModalOpen.set(false)">Close</app-button>
        </div>
      </app-modal>

      <!-- Sync History Modal -->
      <app-modal [isOpen]="isHistoryModalOpen()" [title]="'Sync History: ' + historyAccountName()" (close)="isHistoryModalOpen.set(false)">
        @if (isLoadingHistory()) {
          <div class="loading-state">Loading sync audit records...</div>
        } @else if (syncRuns().length === 0) {
          <app-empty-state title="No Sync Runs Recorded" description="No sync cycles have run yet for this account." />
        } @else {
          <div class="history-table-wrapper">
            <table class="history-table">
              <thead>
                <tr>
                  <th>Type</th>
                  <th>Status</th>
                  <th>Started</th>
                  <th>Duration</th>
                  <th>Records</th>
                </tr>
              </thead>
              <tbody>
                @for (run of syncRuns(); track run.id) {
                  <tr>
                    <td><span class="mono-badge">{{ run.sync_type }}</span></td>
                    <td>
                      <app-badge [variant]="run.status === 'completed' ? 'success' : (run.status === 'running' ? 'info' : 'danger')">
                        {{ run.status }}
                      </app-badge>
                    </td>
                    <td>{{ run.started_at | date:'short' }}</td>
                    <td>{{ run.completed_at ? (calculateDuration(run.started_at, run.completed_at)) : 'Running...' }}</td>
                    <td>{{ run.records_processed }}</td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        }
        <div footer>
          <app-button variant="outline" size="sm" (clicked)="isHistoryModalOpen.set(false)">Close</app-button>
        </div>
      </app-modal>
    </div>
  `,
  styles: [`
    .accounts-page {
      display: flex;
      flex-direction: column;
      gap: 2rem;
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

    .providers-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 1.5rem;
    }

    .provider-card {
      background-color: var(--bg-surface);
      border: 1px solid var(--border-color);
      border-radius: var(--border-radius-md);
      padding: 1.5rem;
      display: flex;
      flex-direction: column;
      gap: 1rem;
      box-shadow: var(--shadow-sm);
    }

    .provider-header {
      display: flex;
      flex-direction: column;
    }

    .provider-name {
      font-weight: 600;
      font-size: 1.125rem;
      color: var(--text-main);
    }

    .provider-type {
      font-size: 0.75rem;
      color: var(--text-muted);
      margin-top: 0.125rem;
    }

    .provider-desc {
      font-size: 0.875rem;
      color: var(--text-muted);
      line-height: 1.4;
      flex: 1;
    }

    .card-footer {
      display: flex;
      justify-content: flex-end;
      margin-top: 0.5rem;
    }

    .section-container {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .section-title {
      font-size: 1.125rem;
      font-weight: 600;
      color: var(--text-main);
    }

    .table-card {
      background-color: var(--bg-surface);
      border: 1px solid var(--border-color);
      border-radius: var(--border-radius-md);
      overflow-x: auto;
    }

    .accounts-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
      font-size: 0.875rem;

      th {
        padding: 0.75rem 1rem;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-muted);
        font-weight: 600;
        background-color: var(--bg-surface-hover);
      }

      td {
        padding: 1rem;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-main);
      }

      tr:last-child td {
        border-bottom: none;
      }
    }

    .platform-badge {
      display: inline-flex;
      align-items: center;
      padding: 0.25rem 0.5rem;
      border-radius: 4px;
      font-size: 0.6875rem;
      font-weight: 700;
      letter-spacing: 0.05em;

      &.meta {
        background-color: #0866ff;
        color: #ffffff;
      }

      &.google {
        background-color: #ea4335;
        color: #ffffff;
      }

      &.tiktok {
        background-color: #000000;
        color: #ffffff;
      }
    }

    .font-medium {
      font-weight: 500;
    }

    .mono-text {
      font-family: var(--font-family-mono);
      font-size: 0.8125rem;
      color: var(--text-muted);
    }

    .action-buttons {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .disconnect-btn {
      color: var(--danger);
      background: none;
      border: none;
      font-size: 0.8125rem;
      font-weight: 500;
      cursor: pointer;
      padding: 0.25rem 0.5rem;

      &:hover {
        text-decoration: underline;
      }
    }

    .loading-state {
      padding: 2rem;
      text-align: center;
      color: var(--text-muted);
    }

    .history-table-wrapper {
      max-height: 350px;
      overflow-y: auto;
    }

    .history-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.8125rem;

      th {
        padding: 0.5rem 0.75rem;
        background: var(--bg-surface-hover);
        border-bottom: 1px solid var(--border-color);
        text-align: left;
      }

      td {
        padding: 0.625rem 0.75rem;
        border-bottom: 1px solid var(--border-color);
      }
    }

    .mono-badge {
      font-family: var(--font-family-mono);
      background-color: var(--bg-surface-hover);
      padding: 0.125rem 0.375rem;
      border-radius: 3px;
    }
  `]
})
export class AccountsFeatureComponent implements OnInit {
  private readonly http = inject(HttpClient);

  readonly accounts = signal<ConnectedAccountDto[]>([]);
  readonly isLoading = signal<boolean>(false);
  readonly connectingProvider = signal<string | null>(null);
  readonly syncingAccountId = signal<number | null>(null);

  readonly isModalOpen = signal<boolean>(false);
  readonly modalTitle = signal<string>('');
  readonly modalMessage = signal<string>('');

  readonly isHistoryModalOpen = signal<boolean>(false);
  readonly historyAccountName = signal<string>('');
  readonly isLoadingHistory = signal<boolean>(false);
  readonly syncRuns = signal<SyncRunDto[]>([]);

  ngOnInit(): void {
    this.fetchAccounts();
  }

  fetchAccounts(): void {
    this.isLoading.set(true);
    this.http.get<{ success: boolean; data: ConnectedAccountDto[] }>('/api/v1/ad-accounts').subscribe({
      next: (res) => {
        this.isLoading.set(false);
        if (res.success) {
          this.accounts.set(res.data);
        }
      },
      error: () => {
        this.isLoading.set(false);
      }
    });
  }

  initiateConnect(provider: 'meta' | 'google' | 'tiktok'): void {
    this.connectingProvider.set(provider);
    const redirectUri = window.location.origin + '/accounts';

    this.http.get<{ success: boolean; data: { auth_url: string } }>(
      `/api/v1/integrations/oauth/${provider}/redirect?redirect_uri=${encodeURIComponent(redirectUri)}`
    ).subscribe({
      next: (res) => {
        this.connectingProvider.set(null);
        if (res.success && res.data.auth_url) {
          window.open(res.data.auth_url, '_blank', 'width=600,height=700');
        }
      },
      error: (err) => {
        this.connectingProvider.set(null);
        const errorMsg = err.error?.error?.message || `Failed to initiate OAuth for ${provider}. Please verify developer credentials in Developer Settings.`;
        this.modalTitle.set(`Connect ${provider.toUpperCase()}`);
        this.modalMessage.set(errorMsg);
        this.isModalOpen.set(true);
      }
    });
  }

  triggerSync(id: number): void {
    this.syncingAccountId.set(id);
    this.http.post<{ message: string; sync_run?: any }>(`/api/v1/ad-accounts/${id}/sync`, {
      sync_type: 'manual',
      sync_now: true,
    }).subscribe({
      next: () => {
        this.syncingAccountId.set(null);
        this.fetchAccounts();
      },
      error: (err) => {
        this.syncingAccountId.set(null);
        this.modalTitle.set('Sync Alert');
        this.modalMessage.set(err.error?.message || 'Failed to trigger synchronization.');
        this.isModalOpen.set(true);
      }
    });
  }

  viewHistory(account: ConnectedAccountDto): void {
    this.historyAccountName.set(account.account_name);
    this.isLoadingHistory.set(true);
    this.isHistoryModalOpen.set(true);

    this.http.get<{ runs: SyncRunDto[] }>(`/api/v1/ad-accounts/${account.id}/sync-history`).subscribe({
      next: (res) => {
        this.isLoadingHistory.set(false);
        this.syncRuns.set(res.runs || []);
      },
      error: () => {
        this.isLoadingHistory.set(false);
      }
    });
  }

  calculateDuration(start: string, end: string): string {
    const diff = new Date(end).getTime() - new Date(start).getTime();
    if (diff < 1000) return `${diff}ms`;
    return `${(diff / 1000).toFixed(1)}s`;
  }

  disconnect(id: number): void {
    if (confirm('Are you sure you want to disconnect this advertising account?')) {
      this.http.delete(`/api/v1/ad-accounts/${id}`).subscribe({
        next: () => this.fetchAccounts(),
      });
    }
  }
}
