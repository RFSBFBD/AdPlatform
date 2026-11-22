import { parseOAuthCallback, OAUTH_PROVIDER_KEY } from './oauth-callback';
import { ProviderSetupGuideComponent } from './provider-setup-guide.component';
import { Component, inject, OnInit, signal, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { ButtonComponent } from '../../shared/components/button/button.component';
import { BadgeComponent } from '../../shared/components/badge/badge.component';
import { EmptyStateComponent } from '../../shared/components/empty-state/empty-state.component';
import { ModalComponent } from '../../shared/components/modal/modal.component';
import { classifySync, SYNC_PILL, SyncKind } from './sync-outcome';
import { DataCoverageComponent } from './data-coverage.component';
import { IconComponent } from '../../shared/components/icon/icon.component';

export interface ConnectedAccountDto {
  id: number;
  uuid: string;
  provider: 'meta' | 'google' | 'tiktok' | 'snapchat';
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
  imports: [CommonModule, ButtonComponent, BadgeComponent, EmptyStateComponent, ModalComponent, IconComponent, DataCoverageComponent, ProviderSetupGuideComponent],
  template: `
    <div class="accounts-page">
      <div class="page-header">
        <div>
          <h2 class="title">Connected Advertising Accounts</h2>
          <p class="subtitle">Connect your advertising accounts here. Meta, Google and TikTok connection code exists and has not been run against live accounts. Snapchat is added with reporting at account level only, also not run live.</p>
        </div>
      </div>


      <!-- Provider Connect Cards -->
      <div class="providers-grid">
        <!-- Meta Ads -->
        <div class="provider-card">
          <div class="provider-header">
            <div class="provider-badge-icon">
              <app-icon name="meta" [size]="28"></app-icon>
            </div>
            <div>
              <span class="provider-name">Meta Ads</span>
              <span class="provider-type">Facebook & Instagram</span>
            </div>
          </div>
          <p class="provider-desc">Connect Ad Accounts and Business assets via official Meta Marketing API.</p>
          <div class="card-footer">
            <app-button 
              variant="primary" 
              size="sm" 
              [loading]="connectingProvider() === 'meta'"
              (clicked)="initiateConnect('meta')"
            >
              <app-icon name="plus" [size]="14"></app-icon>
              <span>Connect Meta Account</span>
            </app-button>
          </div>
          <app-provider-setup-guide providerId="meta"></app-provider-setup-guide>
        </div>

        <!-- Google Ads -->
        <div class="provider-card">
          <div class="provider-header">
            <div class="provider-badge-icon">
              <app-icon name="google" [size]="28"></app-icon>
            </div>
            <div>
              <span class="provider-name">Google Ads</span>
              <span class="provider-type">Search, YouTube, Display</span>
            </div>
          </div>
          <p class="provider-desc">Connect customer accounts or MCC managers via official Google Ads API.</p>
          <div class="card-footer">
            <app-button 
              variant="primary" 
              size="sm" 
              [loading]="connectingProvider() === 'google'"
              (clicked)="initiateConnect('google')"
            >
              <app-icon name="plus" [size]="14"></app-icon>
              <span>Connect Google Account</span>
            </app-button>
          </div>
          <app-provider-setup-guide providerId="google"></app-provider-setup-guide>
        </div>

        <!-- TikTok Ads -->
        <div class="provider-card">
          <div class="provider-header">
            <div class="provider-badge-icon">
              <app-icon name="tiktok" [size]="28"></app-icon>
            </div>
            <div>
              <span class="provider-name">TikTok Ads</span>
              <span class="provider-type">TikTok for Business</span>
            </div>
          </div>
          <p class="provider-desc">Connect advertiser accounts via official TikTok Marketing API.</p>
          <div class="card-footer">
            <app-button 
              variant="primary" 
              size="sm" 
              [loading]="connectingProvider() === 'tiktok'"
              (clicked)="initiateConnect('tiktok')"
            >
              <app-icon name="plus" [size]="14"></app-icon>
              <span>Connect TikTok Account</span>
            </app-button>
          </div>
          <app-provider-setup-guide providerId="tiktok"></app-provider-setup-guide>
        </div>

        <!-- Snapchat Ads -->
        <div class="provider-card">
          <div class="provider-header">
            <div class="provider-badge-icon"><app-icon name="snapchat" [size]="22"></app-icon></div>
            <div>
              <span class="provider-name">Snapchat Ads</span>
              <span class="provider-type">Snap Marketing API</span>
            </div>
          </div>
          <p class="provider-desc">Connect Snap ad accounts through the official Snap Marketing API. Daily account-level spend, swipes and purchase value (Snap Pixel only).</p>
          <div class="card-footer">
            <app-button variant="primary" size="sm" [loading]="connectingProvider() === 'snapchat'" (clicked)="initiateConnect('snapchat')">
              <app-icon name="plus" [size]="14"></app-icon>
              <span>Connect Snapchat Account</span>
            </app-button>
          </div>
          <app-provider-setup-guide providerId="snapchat"></app-provider-setup-guide>
        </div>
      </div>

      <app-data-coverage></app-data-coverage>

      <!-- Connected Accounts List -->
      <div class="section-container">
        <div class="section-header">
          <h3 class="section-title">Active Connections ({{ accounts().length }})</h3>
          <button type="button" class="btn-refresh" (click)="fetchAccounts()" title="Refresh list">
            <app-icon name="sync" [size]="15"></app-icon>
            <span>Refresh</span>
          </button>
        </div>

        @if (isLoading()) {
          <div class="loading-state">
            <span class="spinner"></span>
            <span>Loading connected accounts...</span>
          </div>
        } @else if (loadError()) {
          <div class="loading-state">Could not load connected accounts. Press Refresh to retry.</div>
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
                        <app-icon [name]="acc.provider" [size]="15"></app-icon>
                        <span>{{ acc.provider | uppercase }}</span>
                      </span>
                    </td>
                    <td class="font-medium">{{ acc.account_name }}</td>
                    <td class="mono-text">{{ acc.external_account_id }}</td>
                    <td>{{ acc.currency }} / {{ acc.timezone }}</td>
                    <td>
                      <app-badge [variant]="acc.status === 'active' ? 'success' : (acc.status === 'rate_limited' ? 'warning' : 'danger')">
                        {{ acc.status | uppercase }}
                      </app-badge>
                    </td>
                    <td>{{ acc.last_synced_at ? (acc.last_synced_at | date:'medium') : 'Pending initial sync' }}</td>
                    <td>
                      <div class="action-buttons">
                        <app-button 
                          variant="primary" 
                          size="sm" 
                          [loading]="syncingAccountId() === acc.id"
                          (clicked)="triggerDynamicSync(acc)"
                        >
                          <app-icon name="sync" [size]="14"></app-icon>
                          <span>Sync Now</span>
                        </app-button>
                        <app-button 
                          variant="outline" 
                          size="sm" 
                          (clicked)="viewHistory(acc)"
                        >
                          History
                        </app-button>
                        <button 
                          type="button" 
                          class="disconnect-btn" 
                          (click)="disconnect(acc.id)"
                          title="Disconnect account"
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

      <!-- Dynamic Live Data Ingestion Counter Modal -->
      @if (discovered(); as d) {
        <section class="discovered" aria-label="Accounts found">
          <h3>Accounts found on {{ d.provider }}</h3>
          @if (!d.accounts.length) { <p>No ad accounts were returned for this authorization.</p> }
          @for (a of d.accounts; track a.external_account_id) {
            <label style="display:block;margin:6px 0">
              <input type="checkbox" [checked]="selectedIds().includes(a.external_account_id)" (change)="toggleSelected(a.external_account_id)" />
              {{ a.account_name }} ({{ a.external_account_id }}){{ a.currency ? ' - ' + a.currency : '' }}
            </label>
          }
          <app-button variant="primary" [loading]="isConnecting()" (clicked)="connectSelected()">Connect selected</app-button>
        </section>
      }
      <p class="x-note" style="font-size:13px;opacity:.8">X (Twitter) Ads is not supported yet: no adapter exists and it needs approved X Ads API access. It is not connected and no X data is shown.</p>

      <app-modal 
        [isOpen]="isSyncModalOpen()" 
        title="Live Data Ingestion & Synchronization Engine"
        (close)="closeSyncModal()"
      >
        <div class="dynamic-sync-modal">
          <div class="sync-account-header">
            <span [class]="'platform-badge ' + currentSyncAccount()?.provider">
              {{ currentSyncAccount()?.provider | uppercase }}
            </span>
            <span class="sync-account-title">{{ currentSyncAccount()?.account_name }}</span>
            <span class="sync-status-pill" [class.completed]="syncKind() === 'completed'">
              {{ syncPill() }}
            </span>
          </div>

          <!-- Live Animated Metric Counter Display -->
          <div class="sync-counter-panel">
            <div class="counter-box">
              <span class="counter-label">RECORDS PROCESSED</span>
              <span class="counter-value">{{ syncKind() === 'completed' ? liveRecordsCount() : '-' }}</span>
              <span class="counter-sub">{{ syncKind() === 'completed' ? 'Reported by the server' : 'Shown only after a completed run' }}</span>
            </div>
            <div class="counter-box">
              <span class="counter-label">ELAPSED TIME</span>
              <span class="counter-value">{{ syncElapsedTime() }}s</span>
              <span class="counter-sub">Time waiting for the server</span>
            </div>
          </div>

          <div class="progress-section">
            <div class="progress-labels">
              <span class="stage-text">{{ currentSyncStage() }}</span>
            </div>
          </div>

          <!-- Live Ingestion Event Stream -->
          <div class="sync-log-stream">
            @for (log of syncLogs(); track $index) {
              <div class="log-entry">
                <span class="log-time">{{ log.time }}</span>
                <span class="log-msg">{{ log.message }}</span>
              </div>
            }
          </div>
        </div>

        <div footer class="modal-actions">
          <app-button 
            variant="outline" 
            size="sm" 
            [disabled]="isSyncing()"
            (clicked)="closeSyncModal()"
          >
            {{ isSyncing() ? 'Waiting for the server...' : 'Close' }}
          </app-button>
        </div>
      </app-modal>

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
                        {{ run.status | uppercase }}
                      </app-badge>
                    </td>
                    <td>{{ run.started_at | date:'medium' }}</td>
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
      gap: 1.25rem;
    }

    .provider-card {
      background-color: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      display: flex;
      flex-direction: column;
      gap: 1rem;
      box-shadow: var(--shadow-sm);
      transition: all var(--transition-fast);
    }

    .provider-card:hover {
      border-color: var(--border-strong);
      box-shadow: var(--shadow-md);
      transform: translateY(-2px);
    }

    .provider-header {
      display: flex;
      align-items: center;
      gap: 0.85rem;
    }

    .provider-badge-icon {
      width: 38px;
      height: 38px;
      border-radius: var(--radius-md);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #ffffff;
      font-weight: 800;
      font-size: 1.1rem;
    }

    .meta-bg { background: linear-gradient(135deg, #1877f2, #0062e0); }
    .google-bg { background: linear-gradient(135deg, #ea4335, #fbbc05); }
    .tiktok-bg { background: linear-gradient(135deg, #000000, #25f4ee); }

    .provider-name {
      font-weight: 700;
      font-size: 1.05rem;
      color: var(--text-main);
      display: block;
    }

    .provider-type {
      font-size: 0.75rem;
      color: var(--text-muted);
      display: block;
    }

    .provider-desc {
      font-size: 0.8125rem;
      color: var(--text-muted);
      line-height: 1.5;
    }

    .card-footer {
      margin-top: auto;
      padding-top: 0.5rem;
    }

    .section-container {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .section-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .section-title {
      font-size: 1.125rem;
      font-weight: 700;
      color: var(--text-main);
    }

    .btn-refresh {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      font-size: 0.8125rem;
      color: var(--text-muted);
      padding: 0.4rem 0.75rem;
      border-radius: var(--radius-sm);
      border: 1px solid var(--border-subtle);
      background-color: var(--bg-surface);
      cursor: pointer;
    }

    .btn-refresh:hover {
      color: var(--primary);
      border-color: var(--primary);
    }

    .table-card {
      background-color: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-sm);
      overflow-x: auto;
    }

    .accounts-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
    }

    .accounts-table th {
      padding: 0.875rem 1.25rem;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-subtle);
      background-color: var(--bg-subtle);
      border-bottom: 1px solid var(--border-subtle);
    }

    .accounts-table td {
      padding: 1rem 1.25rem;
      font-size: 0.875rem;
      color: var(--text-main);
      border-bottom: 1px solid var(--border-subtle);
      white-space: nowrap;
    }

    .accounts-table tr:last-child td {
      border-bottom: none;
    }

    .platform-badge {
      display: inline-block;
      font-size: 0.6875rem;
      font-weight: 700;
      padding: 0.2rem 0.55rem;
      border-radius: var(--radius-sm);
      letter-spacing: 0.05em;
    }

    .platform-badge.meta { background-color: rgba(24, 119, 242, 0.15); color: #1877f2; }
    .platform-badge.google { background-color: rgba(234, 67, 53, 0.15); color: #ea4335; }
    .platform-badge.tiktok { background-color: rgba(0, 0, 0, 0.15); color: #00f2fe; }
    .platform-badge.snapchat { background-color: rgba(255, 252, 0, 0.25); color: #8a7a00; }

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
      font-size: 0.8125rem;
      padding: 0.35rem 0.6rem;
      border-radius: var(--radius-sm);
      cursor: pointer;
    }

    .disconnect-btn:hover {
      background-color: var(--danger-bg);
    }

    .loading-state {
      padding: 3rem;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 0.75rem;
      color: var(--text-muted);
      font-size: 0.875rem;
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

    /* Dynamic Sync Modal Styles */
    .dynamic-sync-modal {
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
    }

    .sync-account-header {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding-bottom: 0.75rem;
      border-bottom: 1px solid var(--border-subtle);
    }

    .sync-account-title {
      font-weight: 700;
      font-size: 1rem;
      color: var(--text-main);
    }

    .sync-status-pill {
      margin-left: auto;
      font-size: 0.6875rem;
      font-weight: 700;
      padding: 0.25rem 0.6rem;
      border-radius: var(--radius-full);
      background: rgba(99, 102, 241, 0.15);
      color: var(--primary);
    }

    .sync-status-pill.completed {
      background: var(--success-bg);
      color: var(--success);
    }

    .sync-counter-panel {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
    }

    .counter-box {
      background-color: var(--bg-subtle);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-md);
      padding: 1.25rem;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
    }

    .counter-label {
      font-size: 0.6875rem;
      font-weight: 700;
      letter-spacing: 0.05em;
      color: var(--text-subtle);
    }

    .counter-value {
      font-size: 2.25rem;
      font-weight: 800;
      color: var(--primary);
      margin: 0.35rem 0;
      font-variant-numeric: tabular-nums;
    }

    .counter-sub {
      font-size: 0.75rem;
      color: var(--text-muted);
    }

    .progress-section {
      display: flex;
      flex-direction: column;
      gap: 0.4rem;
    }

    .progress-labels {
      display: flex;
      justify-content: space-between;
      font-size: 0.8125rem;
    }

    .stage-text {
      font-weight: 600;
      color: var(--text-main);
    }

    .percent-text {
      font-weight: 700;
      color: var(--primary);
    }

    .progress-track {
      height: 8px;
      background-color: var(--border-subtle);
      border-radius: var(--radius-full);
      overflow: hidden;
    }

    .progress-fill {
      height: 100%;
      background: linear-gradient(90deg, #6366f1, #06b6d4);
      border-radius: var(--radius-full);
      transition: width 0.3s ease;
    }

    .progress-fill.done {
      background: var(--success);
    }

    .sync-log-stream {
      background-color: var(--bg-subtle);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-md);
      padding: 0.875rem;
      max-height: 140px;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 0.4rem;
      font-family: var(--font-family-mono);
      font-size: 0.75rem;
    }

    .log-entry {
      display: flex;
      gap: 0.75rem;
    }

    .log-time {
      color: var(--text-subtle);
      flex-shrink: 0;
    }

    .log-msg {
      color: var(--text-main);
    }

    .history-table-wrapper {
      max-height: 350px;
      overflow-y: auto;
    }

    .history-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
    }

    .history-table th {
      padding: 0.75rem;
      font-size: 0.75rem;
      color: var(--text-subtle);
      background-color: var(--bg-subtle);
      border-bottom: 1px solid var(--border-subtle);
    }

    .history-table td {
      padding: 0.75rem;
      font-size: 0.8125rem;
      border-bottom: 1px solid var(--border-subtle);
    }

    .mono-badge {
      font-family: var(--font-family-mono);
      font-size: 0.75rem;
    }
  `]
})
export class AccountsFeatureComponent implements OnInit, OnDestroy {
  private readonly http = inject(HttpClient);

  readonly accounts = signal<ConnectedAccountDto[]>([]);
  readonly isLoading = signal<boolean>(false);
  readonly loadError = signal<boolean>(false);
  readonly connectingProvider = signal<string | null>(null);
  readonly syncingAccountId = signal<number | null>(null);

  // Dynamic Live Sync Counter Signals
  readonly isSyncModalOpen = signal<boolean>(false);
  readonly currentSyncAccount = signal<ConnectedAccountDto | null>(null);
  readonly isSyncing = signal<boolean>(false);
  readonly liveRecordsCount = signal<number>(0);
  readonly syncElapsedTime = signal<string>('0.0');
  readonly syncProgress = signal<number>(0);
  readonly syncKind = signal<SyncKind | 'running'>('running');
  readonly syncPill = () => SYNC_PILL[this.syncKind()];
  readonly currentSyncStage = signal<string>('Initiating connection...');
  readonly syncLogs = signal<{ time: string; message: string }[]>([]);

  private syncInterval?: any;
  private syncTimerStart: number = 0;

  readonly isModalOpen = signal<boolean>(false);
  readonly modalTitle = signal<string>('');
  readonly modalMessage = signal<string>('');

  readonly isHistoryModalOpen = signal<boolean>(false);
  readonly historyAccountName = signal<string>('');
  readonly isLoadingHistory = signal<boolean>(false);
  readonly syncRuns = signal<SyncRunDto[]>([]);

  // OAuth return handling (code support; needs real provider approval to verify live)
  readonly discovered = signal<{ provider: string; accounts: { external_account_id: string; account_name: string; currency?: string }[] } | null>(null);
  readonly selectedIds = signal<string[]>([]);
  readonly isConnecting = signal<boolean>(false);

  ngOnInit(): void {
    this.fetchAccounts();
    this.handleOAuthReturn();
  }

  private handleOAuthReturn(): void {
    if (typeof window === 'undefined') return;
    const parsed = parseOAuthCallback(window.location.search, window.localStorage);
    if (!parsed) return;
    window.history.replaceState({}, '', window.location.pathname);
    if ('error' in parsed) {
      this.showModal('Connection not completed', parsed.error);
      return;
    }
    const redirectUri = window.location.origin + '/accounts';
    const qs = new URLSearchParams({ code: parsed.code, state: parsed.state, redirect_uri: redirectUri });
    this.http.get<{ success: boolean; data: { provider: string; accounts: any[] } }>(
      `/api/v1/integrations/oauth/${parsed.provider}/callback?${qs.toString()}`
    ).subscribe({
      next: (res) => {
        window.localStorage.removeItem(OAUTH_PROVIDER_KEY);
        if (res?.success && res.data?.accounts) {
          this.discovered.set({ provider: res.data.provider, accounts: res.data.accounts });
          this.selectedIds.set([]);
        } else {
          this.showModal('Connection not completed', 'The server did not return any accounts.');
        }
      },
      error: (err) => {
        window.localStorage.removeItem(OAUTH_PROVIDER_KEY);
        this.showModal('Connection not completed', err.error?.error?.message || 'The authorization could not be completed.');
      }
    });
  }

  toggleSelected(id: string): void {
    const cur = this.selectedIds();
    this.selectedIds.set(cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id]);
  }

  connectSelected(): void {
    const d = this.discovered();
    if (!d || !this.selectedIds().length) return;
    this.isConnecting.set(true);
    this.http.post(`/api/v1/integrations/${d.provider}/connect`, { selected_account_ids: this.selectedIds() }).subscribe({
      next: () => {
        this.isConnecting.set(false);
        this.discovered.set(null);
        this.fetchAccounts();
        if (window.opener) { window.opener.postMessage('adplatform-oauth-done', window.location.origin); }
      },
      error: (err) => {
        this.isConnecting.set(false);
        this.showModal('Connect accounts', err.error?.error?.message || 'Connecting the selected accounts failed.');
      }
    });
  }

  private showModal(title: string, message: string): void {
    this.modalTitle.set(title);
    this.modalMessage.set(message);
    this.isModalOpen.set(true);
  }

  fetchAccounts(): void {
    this.isLoading.set(true);
    this.loadError.set(false);
    this.http.get<{ success: boolean; data: ConnectedAccountDto[] }>('/api/v1/ad-accounts').subscribe({
      next: (res) => {
        this.isLoading.set(false);
        if (res.success && Array.isArray(res.data)) {
          this.accounts.set(res.data);
        } else {
          this.loadError.set(true);
        }
      },
      error: () => {
        this.isLoading.set(false);
        this.loadError.set(true);
      }
    });
  }

  initiateConnect(provider: 'meta' | 'google' | 'tiktok' | 'snapchat'): void {
    this.connectingProvider.set(provider);
    window.localStorage.setItem(OAUTH_PROVIDER_KEY, provider);
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
        const errorMsg = err.error?.error?.message || `Failed to initiate OAuth for ${provider}. Please verify developer credentials in Developer API Hub.`;
        this.modalTitle.set(`Connect ${provider.toUpperCase()}`);
        this.modalMessage.set(errorMsg);
        this.isModalOpen.set(true);
      }
    });
  }

  triggerDynamicSync(account: ConnectedAccountDto): void {
    this.currentSyncAccount.set(account);
    this.syncingAccountId.set(account.id);
    this.isSyncModalOpen.set(true);
    this.isSyncing.set(true);
    this.syncKind.set('running');
    this.liveRecordsCount.set(0);
    this.syncProgress.set(0);
    this.currentSyncStage.set('Waiting for the server to answer. Nothing is shown until it does.');
    this.syncLogs.set([{ time: this.nowTime(), message: `Sync requested for ${account.provider.toUpperCase()} account ${account.account_name}.` }]);

    this.syncTimerStart = Date.now();
    clearInterval(this.syncInterval);
    // Only the elapsed time is live. No record counts or stages are simulated.
    this.syncInterval = setInterval(() => {
      this.syncElapsedTime.set(((Date.now() - this.syncTimerStart) / 1000).toFixed(1));
    }, 200);

    const finish = (kind: SyncKind, message: string, records: number | null) => {
      clearInterval(this.syncInterval);
      this.syncKind.set(kind);
      if (records !== null) this.liveRecordsCount.set(records);
      this.currentSyncStage.set(message);
      this.addLog(message);
      this.isSyncing.set(false);
      this.syncingAccountId.set(null);
      this.fetchAccounts();
    };

    this.http.post<any>(`/api/v1/ad-accounts/${account.id}/sync`, { sync_type: 'manual', sync_now: true }, { observe: 'response' }).subscribe({
      next: (res) => {
        const o = classifySync(res.status, res.body);
        finish(o.kind, o.message, o.records);
      },
      error: (err) => {
        const msg = err?.error?.error?.message || err?.error?.message || 'The sync request failed.';
        finish('failed', `Failed: ${msg}`, null);
      }
    });
  }

  private addLog(msg: string): void {
    this.syncLogs.update(logs => [...logs, { time: this.nowTime(), message: msg }]);
  }

  private nowTime(): string {
    const d = new Date();
    return d.toTimeString().split(' ')[0];
  }

  closeSyncModal(): void {
    clearInterval(this.syncInterval);
    this.isSyncModalOpen.set(false);
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

  ngOnDestroy(): void {
    clearInterval(this.syncInterval);
  }
}
