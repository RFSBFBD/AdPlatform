import { SyncProvenance, provenanceLine } from './data-coverage';
import { parseOAuthCallback, OAUTH_PROVIDER_KEY } from './oauth-callback';
import { ProviderSetupGuideComponent } from './provider-setup-guide.component';
import { ProviderAppConfigComponent, ProviderConfigDto, ProviderId, createDefaultConfig } from './provider-app-config.component';
import { Component, computed, inject, OnInit, signal, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { ButtonComponent } from '../../shared/components/button/button.component';
import { BadgeComponent } from '../../shared/components/badge/badge.component';
import { EmptyStateComponent } from '../../shared/components/empty-state/empty-state.component';
import { ModalComponent } from '../../shared/components/modal/modal.component';
import { classifySync, SYNC_PILL, SyncKind } from './sync-outcome';
import { DataCoverageComponent } from './data-coverage.component';
import { IconComponent } from '../../shared/components/icon/icon.component';
import { AuthService } from '../../core/services/auth.service';

export interface ConnectedAccountDto {
  id: number;
  uuid: string;
  provider: 'meta' | 'google' | 'tiktok' | 'snapchat' | 'taboola' | 'pinterest' | 'reddit' | 'spotify' | 'microsoft' | 'linkedin' | 'apple' | 'amazon' | 'x';
  external_account_id: string;
  account_name: string;
  currency: string;
  timezone: string;
  status: 'active' | 'expired' | 'revoked' | 'error' | 'rate_limited';
  sync_provenance?:SyncProvenance;
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
  imports: [CommonModule, ButtonComponent, BadgeComponent, EmptyStateComponent, ModalComponent, IconComponent, DataCoverageComponent, ProviderSetupGuideComponent, ProviderAppConfigComponent],
  template: `
    <div class="accounts-page">
      <div class="page-header">
        <div>
          <h2 class="title">Integrations &amp; Connected Accounts</h2>
          <p class="subtitle">Step 1 stores the developer app credentials (admin only); Step 2 authorizes and selects an ad account. Taboola and Apple use shared client credentials instead of browser OAuth and is admin-only. Every adapter is code/fixture-tested only, not verified against a live advertising account. New providers currently report at account/day scope; each card lists its limits.</p>
        </div>
      </div>


      <!-- Unified integration journey: Step 1 (admin) sets up the platform's developer app, Step 2 connects an ad account -->
      @if (canManageDevelopers() && devConfigStatus() === 'error') {
        <p class="admin-note">
          Could not load the platform app setup status, so it is unknown, not necessarily missing. Step 2 stays available.
          <button type="button" class="retry-btn" (click)="fetchDevConfigs()">Retry</button>
        </p>
      }
      <div class="providers-grid">
        @for (p of providers; track p.id) {
          <div class="provider-card">
            <div class="provider-header">
              <div class="provider-badge-icon">
                <app-icon [name]="p.id" [size]="p.iconSize"></app-icon>
              </div>
              <div>
                <span class="provider-name">{{ p.name }}</span>
                <span class="provider-type">{{ p.type }}</span>
              </div>
            </div>
            <p class="provider-desc">{{ p.desc }}</p>

            @if (canManageDevelopers()) {
              <app-provider-app-config [provider]="p.id" [(config)]="devConfigs[p.id]" [configStatus]="devConfigStatus()"></app-provider-app-config>
            } @else {
              <p class="admin-note">Step 1 (platform app setup) is managed by your administrator. You only connect an ad account in Step 2.</p>
            }

            <div class="card-footer">
              <span class="connect-step-label">Step 2 · Connect an ad account</span>
              <app-button
                variant="primary"
                size="sm"
                [loading]="connectingProvider() === p.id"
                [disabled]="isConnectDisabled(p.id)"
                (clicked)="initiateConnect(p.id)"
              >
                <app-icon name="plus" [size]="14"></app-icon>
                <span>Connect {{ p.name }} Account</span>
              </app-button>
              @if (isConnectDisabled(p.id)) {
                <span class="field-hint">Finish Step 1 first: the provider rejects sign-in until the app credentials are saved.</span>
              }
            </div>
            <app-provider-setup-guide [providerId]="p.id"></app-provider-setup-guide>
          </div>
        }
      </div>

      <!-- Reporting integration: Looker Studio connector (kept from the retired Developer API Hub page) -->
      <div class="section-container">
        <div class="section-header">
          <h3 class="section-title">Reporting Integration</h3>
        </div>
        <div class="provider-card looker-highlight">
          <div class="provider-header">
            <div class="provider-badge-icon">
              <app-icon name="looker" [size]="24"></app-icon>
            </div>
            <div>
              <span class="provider-name">Google Looker Studio Connector</span>
              <span class="provider-type">Unified metrics from your synced accounts</span>
            </div>
            <app-badge [variant]="lookerTestStatus() === 'connected' ? 'success' : (lookerTestStatus() === 'error' ? 'danger' : 'neutral')">
              {{ lookerTestStatus() === 'connected' ? 'SCHEMA VERIFIED' : (lookerTestStatus() === 'error' ? 'CHECK FAILED' : 'NOT VERIFIED') }}
            </app-badge>
          </div>
          <p class="provider-desc">Serves unified cross-channel performance metrics to Looker Studio dashboards. Data freshness follows the last account sync (sync is manual); press Verify to check the endpoint before relying on it.</p>
          <div>
            <span class="connect-step-label">Connector data endpoint</span>
            <div class="url-copy-box">
              <input type="text" readonly [value]="lookerEndpointUrl" class="looker-url-input" />
              <app-button variant="outline" size="sm" (clicked)="copyLookerEndpoint()">
                <app-icon name="copy" [size]="14"></app-icon>
                <span>{{ copiedLookerUrl() ? 'Copied!' : 'Copy URL' }}</span>
              </app-button>
            </div>
            <span class="field-hint">Use this HTTPS endpoint in a Looker Studio Community Connector or Apps Script. Requires authenticated connector setup. Schema dimensions: date, provider, account_name, campaign_name, currency. Metrics: spend, clicks, impressions, conversions, conversion_value, roas, cpc. Verification checks schema only, not data freshness or completed Looker setup.</span>
          </div>
          <div class="card-footer looker-actions">
            <app-button variant="primary" size="sm" [loading]="isTestingLooker()" (clicked)="testLookerEndpoint()">
              <app-icon name="sync" [size]="14"></app-icon>
              <span>Verify Looker Endpoint</span>
            </app-button>
            <a href="https://lookerstudio.google.com" target="_blank" rel="noopener noreferrer" class="looker-portal-btn">
              <app-icon name="external-link" [size]="14"></app-icon>
              <span>Open Looker Studio</span>
            </a>
          </div>
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
                  <th>Sync evidence</th>
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
                      <app-badge [variant]="['active','connected'].includes(acc.status) ? 'success' : (acc.status === 'rate_limited' ? 'warning' : 'danger')">
                        {{ acc.status | uppercase }}
                      </app-badge>
                    </td>
                    <td class="sync-evidence">{{ syncEvidence(acc) }}</td>
                    <td>
                      <div class="action-buttons">
                        <app-button 
                          variant="primary" 
                          size="sm" 
                          [loading]="syncingAccountId() === acc.id"
                          [disabled]="['apple','x'].includes(acc.provider)"
                          (clicked)="triggerDynamicSync(acc)"
                        >
                          <app-icon name="sync" [size]="14"></app-icon>
                          <span>{{ ['apple','x'].includes(acc.provider) ? 'Revenue sync unavailable' : (['microsoft','amazon'].includes(acc.provider) ? 'Request async report' : 'Sync Now') }}</span>
                        </app-button>
                        @if (['microsoft','amazon'].includes(acc.provider)) { <app-button variant="outline" size="sm" (clicked)="viewAsyncReport(acc)">Report status</app-button> }
                        @if (acc.provider === 'x') { <app-button variant="outline" size="sm" (clicked)="viewXDelivery(acc)">7-day delivery</app-button> }
                        @if (acc.provider === 'apple') {
                          <app-button variant="outline" size="sm" (clicked)="viewAppleDelivery(acc)">Delivery report</app-button>
                        }
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
      <p class="x-note" style="font-size:13px;opacity:.8">X Ads supports a separate OAuth1 campaign-delivery reader, not purchase revenue or OR sync. Every adapter remains fixture-tested only until verified with an approved live account. Walmart Connect remains blocked on the partner signing and token contract.</p>

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
    .accounts-table td.sync-evidence { max-width:280px; min-width:180px; white-space:normal; overflow-wrap:anywhere; font-size:.8rem; }
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
      grid-template-columns: repeat(auto-fit, minmax(min(100%, 380px), 1fr));
      align-items: start;
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

    .connect-step-label {
      display: block;
      font-size: 0.75rem;
      font-weight: 600;
      color: var(--text-main);
      margin-bottom: 0.375rem;
    }

    .field-hint {
      display: block;
      font-size: 0.7rem;
      color: var(--text-muted);
      margin-top: 0.375rem;
    }

    .admin-note {
      font-size: 0.75rem;
      color: var(--text-muted);
      font-style: italic;
      border: 1px dashed var(--border-subtle);
      border-radius: var(--radius-sm);
      padding: 0.5rem 0.75rem;
    }

    .retry-btn {
      margin-left: 0.5rem;
      background: none;
      border: none;
      padding: 0;
      color: var(--primary);
      font-size: 0.75rem;
      font-weight: 600;
      cursor: pointer;
      text-decoration: underline;
    }

    .provider-header app-badge {
      margin-left: auto;
    }

    .looker-highlight {
      border-color: rgba(66, 133, 244, 0.35);
      background: linear-gradient(180deg, rgba(66, 133, 244, 0.05) 0%, var(--bg-surface) 100%);
    }

    .url-copy-box {
      display: flex;
      gap: 0.5rem;
      align-items: center;
      margin-top: 0.25rem;
    }

    .looker-url-input {
      flex: 1;
      font-size: 0.8125rem;
      font-family: monospace;
      min-width: 0;
      padding: 0.5rem 0.75rem;
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-sm);
      background: var(--bg-subtle);
      color: var(--text-main);
    }

    .looker-actions {
      display: flex;
      gap: 0.5rem;
      align-items: center;
      flex-wrap: wrap;
    }

    .looker-portal-btn {
      display: inline-flex;
      align-items: center;
      gap: 0.375rem;
      font-size: 0.8125rem;
      color: #60a5fa;
      padding: 0.375rem 0.75rem;
      border-radius: var(--radius-md);
      border: 1px solid rgba(96, 165, 250, 0.3);
      text-decoration: none;
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
  syncEvidence=(a:ConnectedAccountDto)=>a.sync_provenance?provenanceLine(a.sync_provenance):'Sync audit unavailable';

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

  private readonly authService = inject(AuthService);

  // Unified integration journey: developer app config (Step 1, admin-only via
  // manage-developers, enforced again by the backend) sits next to account
  // connection (Step 2). Ad data always comes from the OAuth-connected account
  // in Step 2; Step 1 only identifies this platform's app at the provider.
  readonly canManageDevelopers = computed(() => this.authService.hasPermission('manage-developers'));
  readonly devConfigStatus = signal<'loading' | 'loaded' | 'error'>('loading');
  readonly devConfigs: Record<ProviderId, ProviderConfigDto> = {
    meta: createDefaultConfig('meta'),
    google: createDefaultConfig('google'),
    tiktok: createDefaultConfig('tiktok'),
    snapchat: createDefaultConfig('snapchat'),
    taboola: createDefaultConfig('taboola'),
    pinterest: createDefaultConfig('pinterest'),
    reddit: createDefaultConfig('reddit'),
    spotify: createDefaultConfig('spotify'),
    linkedin: createDefaultConfig('linkedin'),
    microsoft: createDefaultConfig('microsoft'),
    apple: createDefaultConfig('apple'),
    amazon: createDefaultConfig('amazon'),
    x: createDefaultConfig('x'),
  };

  readonly providers: { id: ProviderId; name: string; type: string; desc: string; iconSize: number }[] = [
    { id: 'meta', name: 'Meta Ads', type: 'Facebook & Instagram', desc: 'Connect Ad Accounts and Business assets via official Meta Marketing API.', iconSize: 28 },
    { id: 'google', name: 'Google Ads', type: 'Search, YouTube, Display', desc: 'Connect customer accounts or MCC managers via official Google Ads API.', iconSize: 28 },
    { id: 'tiktok', name: 'TikTok Ads', type: 'TikTok for Business', desc: 'Connect advertiser accounts via official TikTok Marketing API.', iconSize: 28 },
    { id: 'snapchat', name: 'Snapchat Ads', type: 'Snap Marketing API', desc: 'Connect Snap ad accounts through the official Snap Marketing API. Daily account-level spend, swipes and purchase value (Snap Pixel only).', iconSize: 22 },
    { id:'x', name:'X Ads', type:'OAuth1 · campaign delivery only', desc:'Fixture-tested, not live. Approved Ads API access and consumer credentials required. Signed OAuth1 user flow; seven completed local days, BILLING/ENGAGEMENT on X only. No purchase revenue import, async history or off-platform placement.', iconSize:22 },
    { id: 'amazon', name: 'Amazon Ads', type: 'Sponsored Products · v3 asynchronous reports', desc: 'Fixture-tested, not live. Profile-local campaign/day data aggregated to account/day. Explicit 7-day click purchases and sales, not SB/SD/DSP. Durable queue, verified download host and endDate boundary policy required. v3 migration needed before June 30, 2027.', iconSize: 22 },
    { id: 'apple', name: 'Apple Ads', type: 'Platform API v1 · delivery only', desc: 'Fixture-tested, not live. Admin client grant with pre-signed ES256 JWT. Separate campaign/day delivery API for taps and installs, no purchase revenue. Standard Sync intentionally disabled.', iconSize: 22 },
    { id: 'microsoft', name: 'Microsoft Advertising', type: 'REST v13 · asynchronous account reports', desc: 'Fixture-tested, not live. Requires developer token, durable queue worker, verified timezone map and download-host allowlist in deployment. Reports submit/poll/download/import, never immediate success.', iconSize: 22 },
    { id: 'linkedin', name: 'LinkedIn Ads', type: 'Versioned REST · guarded revenue mapping', desc: 'Auth and discovery implemented, fixture-tested only. Daily reports remain disabled until conversion rules and revenue values are verified in deployment configuration. App review and API version required.', iconSize: 22 },
    { id: 'spotify', name: 'Spotify Ads', type: 'API v3 · account/day reports', desc: 'Fixture-tested, not verified live. UTC account reports in billing currency. Purchases/revenue require instrumentation; missing or privacy-suppressed metrics stop import.', iconSize: 22 },
    { id: 'reddit', name: 'Reddit Ads', type: 'API v3 · account/day purchases', desc: 'Fixture-tested, not verified live. UTC daily account reports; purchase click + view conversions. Microcurrency spend and cents purchase value are normalized separately.', iconSize: 22 },
    { id: 'pinterest', name: 'Pinterest Ads', type: 'API v5 · account/day reports', desc: 'Fixture-tested, not verified live. Advertiser-currency reporting, checkout event value, outbound clicks, 30-day click and engagement / 1-day view attribution. Missing fields stop import.', iconSize: 22 },
    { id: 'taboola', name: 'Taboola Ads', type: 'Client credentials · account/day reports', desc: 'Adapter implemented and fixture-tested, not verified live. Admin credentials required. Account-level reporting only; conversions can change for 30 days.', iconSize: 22 },
  ];

  // Looker Studio connector (kept from the retired Developer API Hub page)
  readonly lookerTestStatus = signal<'untested' | 'connected' | 'error'>('untested');
  readonly isTestingLooker = signal<boolean>(false);
  readonly copiedLookerUrl = signal<boolean>(false);
  readonly lookerEndpointUrl = `${window.location.origin}/api/v1/reporting/looker-studio`;

  isAppConfigured(id: ProviderId): boolean {
    const cfg = this.devConfigs[id];
    const base = !!cfg.app_id && cfg.has_secret;
    return ['google', 'microsoft'].includes(id) ? base && cfg.has_developer_token : base;
  }

  // Connect is gated only on a successfully loaded config state. A failed load
  // means "unknown", never "verified missing", so it must not block Step 2.
  isConnectDisabled(id: ProviderId): boolean {
    return (['taboola', 'apple'].includes(id) && !this.canManageDevelopers()) || (this.devConfigStatus() === 'loaded' && this.canManageDevelopers() && !this.isAppConfigured(id));
  }

  fetchDevConfigs(): void {
    this.devConfigStatus.set('loading');
    this.http.get<{ success: boolean; data: ProviderConfigDto[] }>('/api/v1/admin/developer-configs').subscribe({
      next: (res) => {
        if (res?.success && Array.isArray(res.data) && ['meta', 'google', 'tiktok', 'snapchat'].every(p => res.data.some(c => c.provider === p))) {
          for (const item of res.data) {
            if (item.provider in this.devConfigs) {
              this.devConfigs[item.provider] = item;
            }
          }
          this.devConfigStatus.set('loaded');
        } else {
          this.devConfigStatus.set('error');
        }
      },
      error: () => { this.devConfigStatus.set('error'); }
    });
  }

  async copyLookerEndpoint(): Promise<void> {
    try {
      await navigator.clipboard.writeText(this.lookerEndpointUrl);
      this.copiedLookerUrl.set(true);
      setTimeout(() => this.copiedLookerUrl.set(false), 3000);
    } catch {
      this.copiedLookerUrl.set(false);
      this.showModal('Copy not available', 'Select and copy the endpoint address from the field.');
    }
  }

  testLookerEndpoint(): void {
    this.isTestingLooker.set(true);
    this.http.get<{ schema: any[] }>('/api/v1/reporting/looker-studio?schema=true').subscribe({
      next: (res) => {
        this.isTestingLooker.set(false);
        // Verified only on an actual non-empty schema response; anything else is a failed check.
        this.lookerTestStatus.set(Array.isArray(res?.schema) && res.schema.length > 0 ? 'connected' : 'error');
      },
      error: () => {
        this.isTestingLooker.set(false);
        this.lookerTestStatus.set('error');
      }
    });
  }

  ngOnInit(): void {
    this.fetchAccounts();
    this.handleOAuthReturn();
    if (this.canManageDevelopers()) {
      this.fetchDevConfigs();
    }
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
    if(parsed.oauth_token) qs.set('oauth_token',parsed.oauth_token);
    if(parsed.oauth_verifier) qs.set('oauth_verifier',parsed.oauth_verifier);
    this.http.get<{ success: boolean; data: { provider: string; accounts: any[] } }>(
      `/api/v1/integrations/oauth/${parsed.provider}/callback?${qs.toString()}`
    ).subscribe({
      next: (res) => {
        window.localStorage.removeItem(OAUTH_PROVIDER_KEY);
        window.localStorage.removeItem('adplatform_x_oauth_state');
        if (res?.success && res.data?.accounts) {
          this.discovered.set({ provider: res.data.provider, accounts: res.data.accounts });
          this.selectedIds.set([]);
        } else {
          this.showModal('Connection not completed', 'The server did not return any accounts.');
        }
      },
      error: (err) => {
        window.localStorage.removeItem(OAUTH_PROVIDER_KEY);
        window.localStorage.removeItem('adplatform_x_oauth_state');
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

  viewXDelivery(acc: ConnectedAccountDto): void {
    if(!acc.timezone){this.showModal('X timezone missing','Reconnect with verified account-local timezone.');return;}
    const parts=new Intl.DateTimeFormat('en-CA',{timeZone:acc.timezone,year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date());
    const get=(key:string)=>parts.find(p=>p.type===key)?.value;
    const end=new Date(`${get('year')}-${get('month')}-${get('day')}T00:00:00Z`);end.setUTCDate(end.getUTCDate()-1);
    const start=new Date(end);start.setUTCDate(start.getUTCDate()-6);
    this.http.get<any>(`/api/v1/ad-accounts/${acc.id}/x-delivery-report`,{params:{start_date:start.toISOString().slice(0,10),end_date:end.toISOString().slice(0,10)}}).subscribe({
      next:r=>{const rows=r.data?.rows??[];this.showModal('X ACTUAL campaign delivery, not revenue',rows.length?rows.map((x:any)=>`${x.date} (${x.timezone}), ${x.campaign_id}: ${x.spend} ${x.currency}, ${x.impressions} impressions, ${x.aggregate_clicks} aggregate clicks. Revenue unavailable. Spend can restate.`).join('\n'):'No rows. Empty data does not verify permission or measured zero.');},
      error:e=>this.showModal('X delivery unavailable',e.error?.message||'Check approved Ads access, local date range, funding currencies and rate limits.')
    });
  }

  viewAppleDelivery(acc: ConnectedAccountDto): void {
    const end = new Date(); end.setUTCDate(end.getUTCDate() - 1);
    const start = new Date(end); start.setUTCDate(start.getUTCDate() - 29);
    const start_date = start.toISOString().slice(0, 10), end_date = end.toISOString().slice(0, 10);
    this.http.get<any>(`/api/v1/ad-accounts/${acc.id}/apple-delivery-report`, { params: {start_date, end_date} }).subscribe({
      next: r => {
        const rows = r.data?.rows ?? [];
        this.modalTitle.set(`Apple delivery: ${start_date} to ${end_date} (UTC)`);
        this.modalMessage.set(rows.length ? rows.map((x: any) => `${x.date}, campaign ${x.campaign_id}: ${x.spend} ${x.currency}, ${x.impressions} impressions, ${x.taps} taps, ${x.installs} installs. Revenue unavailable.`).join('\n') : 'No report rows. Empty results do not confirm live reporting permission.');
        this.isModalOpen.set(true);
      },
      error: e => { this.modalTitle.set('Apple delivery unavailable'); this.modalMessage.set(e.error?.message || 'Report failed. Check access, JWT expiry and report permissions.'); this.isModalOpen.set(true); }
    });
  }

  initiateConnect(provider: 'meta' | 'google' | 'tiktok' | 'snapchat' | 'taboola' | 'pinterest' | 'reddit' | 'spotify' | 'microsoft' | 'linkedin' | 'apple' | 'amazon' | 'x'): void {
    this.connectingProvider.set(provider);
    if (provider === 'taboola' || provider === 'apple') {
      this.http.post<{success: boolean; data: {provider: string; accounts: any[]}}>(`/api/v1/integrations/${provider}/credential-discovery`, {}).subscribe({
        next: res => { this.connectingProvider.set(null); this.discovered.set(res.data); this.selectedIds.set([]); },
        error: err => { this.connectingProvider.set(null); this.modalTitle.set(provider.toUpperCase() + ' account discovery'); this.modalMessage.set(err.error?.message || 'Discovery failed. Administrator credentials and API access are required.'); this.isModalOpen.set(true); }
      });
      return;
    }
    window.localStorage.setItem(OAUTH_PROVIDER_KEY, provider);
    const redirectUri = window.location.origin + '/accounts';

    this.http.get<{ success: boolean; data: { auth_url: string; state: string } }>(
      `/api/v1/integrations/oauth/${provider}/redirect?redirect_uri=${encodeURIComponent(redirectUri)}`
    ).subscribe({
      next: (res) => {
        this.connectingProvider.set(null);
        if (res.success && res.data.auth_url) {
          if(provider==='x') window.localStorage.setItem('adplatform_x_oauth_state',res.data.state);
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

  readonly microsoftReportIds: Record<number, number> = {};
  submitAsyncReport(account: ConnectedAccountDto): void {
    const tz=account.timezone;
    if (!tz) { this.modalTitle.set('Profile timezone missing');this.modalMessage.set('Reconnect with a verified reporting timezone.');this.isModalOpen.set(true);return; }
    const localDay = new Intl.DateTimeFormat('en-CA',{timeZone:tz,year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
    const end=new Date(localDay+'T00:00:00Z');end.setUTCDate(end.getUTCDate()-1);
    const start=new Date(end);start.setUTCDate(start.getUTCDate()-29);
    this.http.post<any>(`/api/v1/ad-accounts/${account.id}/${account.provider}-reports`,{start_date:start.toISOString().slice(0,10),end_date:end.toISOString().slice(0,10)}).subscribe({
      next:r=>{this.microsoftReportIds[account.id]=r.data.report_job_id;this.modalTitle.set('Report submitted, not imported');this.modalMessage.set('A durable server worker polls with delay. Use Report status to inspect completion. Submission is not a sync success.');this.isModalOpen.set(true);},
      error:e=>{this.modalTitle.set('Report unavailable');this.modalMessage.set(e.error?.message || 'Check queue, date policy, timezone and provider setup.');this.isModalOpen.set(true);}
    });
  }
  submitMicrosoftReport(account: ConnectedAccountDto): void {this.submitAsyncReport(account);}
  viewMicrosoftReport(account: ConnectedAccountDto): void {this.viewAsyncReport(account);}
  viewAsyncReport(account: ConnectedAccountDto): void {
    const id=this.microsoftReportIds[account.id] || 'latest';
    this.http.get<any>(`/api/v1/ad-accounts/${account.id}/${account.provider}-reports/${id}`).subscribe({next:r=>{this.microsoftReportIds[account.id]=r.data.report_job_id;this.modalTitle.set('Report status');this.modalMessage.set(`State: ${r.data.state}. Imported: ${r.data.imported}. ${r.data.error || ''}`);this.isModalOpen.set(true);this.fetchAccounts();},error:()=>{this.modalTitle.set('Report status unavailable');this.modalMessage.set('No accessible report found.');this.isModalOpen.set(true);}});
  }

  triggerDynamicSync(account: ConnectedAccountDto): void {
    if (['microsoft','amazon'].includes(account.provider)) { this.submitAsyncReport(account); return; }
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
