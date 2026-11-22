import { Component, inject, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ButtonComponent } from '../../shared/components/button/button.component';
import { BadgeComponent } from '../../shared/components/badge/badge.component';
import { IconComponent } from '../../shared/components/icon/icon.component';
import { ProviderSetupGuideComponent } from '../accounts/provider-setup-guide.component';

export interface ProviderConfigDto {
  id?: number;
  provider: 'meta' | 'google' | 'tiktok' | 'snapchat';
  app_id: string | null;
  has_secret: boolean;
  masked_secret: string | null;
  has_developer_token: boolean;
  masked_developer_token: string | null;
  redirect_uri: string | null;
  api_version: string | null;
  is_enabled: boolean;
  test_status: string;
  test_error: string | null;
  last_tested_at: string | null;
}

@Component({
  selector: 'app-developer-settings-feature',
  standalone: true,
  imports: [CommonModule, FormsModule, ButtonComponent, BadgeComponent, IconComponent, ProviderSetupGuideComponent],
  template: `
    <div class="settings-page">
      <div class="page-header">
        <div>
          <h2 class="title">Developer API Hub & Connection Center</h2>
          <p class="subtitle">
            Configure official social media & advertising developer credentials in one unified place. Test API handshakes with live status verification.
          </p>
        </div>
      </div>

      @if (feedbackMessage()) {
        <div [class]="'feedback-banner ' + feedbackType()">
          <span>{{ feedbackMessage() }}</span>
        </div>
      }

      <div class="settings-cards">
        <!-- Meta Ads Dev Config -->
        <div class="config-card">
          <div class="card-header">
            <div class="card-title-group">
              <div class="platform-icon">
                <app-icon name="meta" [size]="24"></app-icon>
              </div>
              <div>
                <h3 class="card-title">Meta Marketing API Credentials</h3>
                <p class="card-desc">Facebook & Instagram Ads developer app credentials.</p>
              </div>
            </div>
            <app-badge [variant]="getBadgeVariant(metaConfig.test_status)">
              {{ metaConfig.test_status | uppercase }}
            </app-badge>
          </div>

          <div class="form-group">
            <label class="form-label" for="metaAppId">Meta App ID</label>
            <input 
              id="metaAppId"
              type="text" 
              class="form-input" 
              [(ngModel)]="metaConfig.app_id" 
              placeholder="e.g. 123456789012345" 
            />
          </div>

          <div class="form-group">
            <label class="form-label" for="metaAppSecret">
              Meta App Secret 
              @if (metaConfig.has_secret) {
                <span class="secure-tag">(Encrypted: {{ metaConfig.masked_secret }})</span>
              }
            </label>
            <div class="password-wrapper">
              <input 
                id="metaAppSecret"
                [type]="showMetaSecret() ? 'text' : 'password'" 
                class="form-input" 
                [(ngModel)]="metaSecretInput" 
                [placeholder]="metaConfig.has_secret ? 'Enter new secret to rotate' : 'Enter app secret'" 
              />
              <button type="button" class="toggle-pwd" (click)="showMetaSecret.update(v => !v)">
                <app-icon [name]="showMetaSecret() ? 'eye-off' : 'eye'" [size]="15"></app-icon>
              </button>
            </div>
          </div>

          <div class="card-actions">
            <app-button 
              variant="primary" 
              size="sm" 
              [loading]="isSaving('meta')"
              (clicked)="saveConfig('meta')"
            >
              Save Meta Configuration
            </app-button>
            <app-button 
              variant="outline" 
              size="sm" 
              [loading]="isTesting('meta')"
              (clicked)="testConfig('meta')"
            >
              <app-icon name="sync" [size]="14"></app-icon>
              <span>Test Handshake</span>
            </app-button>
          </div>
          <app-provider-setup-guide providerId="meta"></app-provider-setup-guide>
        </div>

        <!-- Google Ads Dev Config -->
        <div class="config-card">
          <div class="card-header">
            <div class="card-title-group">
              <div class="platform-icon">
                <app-icon name="google" [size]="24"></app-icon>
              </div>
              <div>
                <h3 class="card-title">Google Ads API Credentials</h3>
                <p class="card-desc">OAuth 2.0 Web Client credentials and MCC Developer Token.</p>
              </div>
            </div>
            <app-badge [variant]="getBadgeVariant(googleConfig.test_status)">
              {{ googleConfig.test_status | uppercase }}
            </app-badge>
          </div>

          <div class="form-group">
            <label class="form-label" for="googleClientId">Client ID</label>
            <input 
              id="googleClientId"
              type="text" 
              class="form-input" 
              [(ngModel)]="googleConfig.app_id" 
              placeholder="e.g. 12345-abc.apps.googleusercontent.com" 
            />
          </div>

          <div class="form-group">
            <label class="form-label" for="googleClientSecret">
              Client Secret 
              @if (googleConfig.has_secret) {
                <span class="secure-tag">(Encrypted: {{ googleConfig.masked_secret }})</span>
              }
            </label>
            <div class="password-wrapper">
              <input 
                id="googleClientSecret"
                [type]="showGoogleSecret() ? 'text' : 'password'" 
                class="form-input" 
                [(ngModel)]="googleSecretInput" 
                [placeholder]="googleConfig.has_secret ? 'Enter new secret to rotate' : 'Enter client secret'" 
              />
              <button type="button" class="toggle-pwd" (click)="showGoogleSecret.update(v => !v)">
                <app-icon [name]="showGoogleSecret() ? 'eye-off' : 'eye'" [size]="15"></app-icon>
              </button>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="googleDevToken">
              Developer Token
              @if (googleConfig.has_developer_token) {
                <span class="secure-tag">(Encrypted: {{ googleConfig.masked_developer_token }})</span>
              }
            </label>
            <div class="password-wrapper">
              <input 
                id="googleDevToken"
                [type]="showGoogleToken() ? 'text' : 'password'" 
                class="form-input" 
                [(ngModel)]="googleTokenInput" 
                [placeholder]="googleConfig.has_developer_token ? 'Enter new token to rotate' : 'Enter developer token'" 
              />
              <button type="button" class="toggle-pwd" (click)="showGoogleToken.update(v => !v)">
                <app-icon [name]="showGoogleToken() ? 'eye-off' : 'eye'" [size]="15"></app-icon>
              </button>
            </div>
          </div>

          <div class="card-actions">
            <app-button 
              variant="primary" 
              size="sm" 
              [loading]="isSaving('google')"
              (clicked)="saveConfig('google')"
            >
              Save Google Configuration
            </app-button>
            <app-button 
              variant="outline" 
              size="sm" 
              [loading]="isTesting('google')"
              (clicked)="testConfig('google')"
            >
              <app-icon name="sync" [size]="14"></app-icon>
              <span>Test Handshake</span>
            </app-button>
          </div>
          <app-provider-setup-guide providerId="google"></app-provider-setup-guide>
        </div>

        <!-- TikTok Ads Dev Config -->
        <div class="config-card">
          <div class="card-header">
            <div class="card-title-group">
              <div class="platform-icon">
                <app-icon name="tiktok" [size]="24"></app-icon>
              </div>
              <div>
                <h3 class="card-title">TikTok Marketing API Credentials</h3>
                <p class="card-desc">App ID and Secret from TikTok for Business Developer Portal.</p>
              </div>
            </div>
            <app-badge [variant]="getBadgeVariant(tiktokConfig.test_status)">
              {{ tiktokConfig.test_status | uppercase }}
            </app-badge>
          </div>

          <div class="form-group">
            <label class="form-label" for="tiktokAppId">TikTok App ID</label>
            <input 
              id="tiktokAppId"
              type="text" 
              class="form-input" 
              [(ngModel)]="tiktokConfig.app_id" 
              placeholder="e.g. 6900000000000000000" 
            />
          </div>

          <div class="form-group">
            <label class="form-label" for="tiktokSecret">
              TikTok App Secret 
              @if (tiktokConfig.has_secret) {
                <span class="secure-tag">(Encrypted: {{ tiktokConfig.masked_secret }})</span>
              }
            </label>
            <div class="password-wrapper">
              <input 
                id="tiktokSecret"
                [type]="showTiktokSecret() ? 'text' : 'password'" 
                class="form-input" 
                [(ngModel)]="tiktokSecretInput" 
                [placeholder]="tiktokConfig.has_secret ? 'Enter new secret to rotate' : 'Enter app secret'" 
              />
              <button type="button" class="toggle-pwd" (click)="showTiktokSecret.update(v => !v)">
                <app-icon [name]="showTiktokSecret() ? 'eye-off' : 'eye'" [size]="15"></app-icon>
              </button>
            </div>
          </div>

          <div class="card-actions">
            <app-button 
              variant="primary" 
              size="sm" 
              [loading]="isSaving('tiktok')"
              (clicked)="saveConfig('tiktok')"
            >
              Save TikTok Configuration
            </app-button>
            <app-button 
              variant="outline" 
              size="sm" 
              [loading]="isTesting('tiktok')"
              (clicked)="testConfig('tiktok')"
            >
              <app-icon name="sync" [size]="14"></app-icon>
              <span>Test Handshake</span>
            </app-button>
          </div>
          <app-provider-setup-guide providerId="tiktok"></app-provider-setup-guide>
        </div>

        <!-- Snapchat Ads Dev Config -->
        <div class="config-card">
          <div class="card-header">
            <div class="card-title-group">
              <div class="platform-icon">
                <app-icon name="snapchat" [size]="24"></app-icon>
              </div>
              <div>
                <h3 class="card-title">Snapchat Marketing API Credentials</h3>
                <p class="card-desc">OAuth app client ID and client secret from Snap Business Manager (Business Details, + OAuth App). The secret is shown only once.</p>
              </div>
            </div>
            <app-badge [variant]="getBadgeVariant(snapchatConfig.test_status)">
              {{ snapchatConfig.test_status | uppercase }}
            </app-badge>
          </div>

          <div class="form-group">
            <label class="form-label" for="snapchatAppId">Snapchat Client ID</label>
            <input 
              id="snapchatAppId"
              type="text" 
              class="form-input" 
              [(ngModel)]="snapchatConfig.app_id" 
              placeholder="OAuth app client ID" 
            />
          </div>

          <div class="form-group">
            <label class="form-label" for="snapchatSecret">
              Snapchat Client Secret 
              @if (snapchatConfig.has_secret) {
                <span class="secure-tag">(Encrypted: {{ snapchatConfig.masked_secret }})</span>
              }
            </label>
            <div class="password-wrapper">
              <input 
                id="snapchatSecret"
                [type]="showSnapchatSecret() ? 'text' : 'password'" 
                class="form-input" 
                [(ngModel)]="snapchatSecretInput" 
                [placeholder]="snapchatConfig.has_secret ? 'Enter new secret to rotate' : 'Enter app secret'" 
              />
              <button type="button" class="toggle-pwd" (click)="showSnapchatSecret.update(v => !v)">
                <app-icon [name]="showSnapchatSecret() ? 'eye-off' : 'eye'" [size]="15"></app-icon>
              </button>
            </div>
          </div>

          <div class="card-actions">
            <app-button 
              variant="primary" 
              size="sm" 
              [loading]="isSaving('snapchat')"
              (clicked)="saveConfig('snapchat')"
            >
              Save Snapchat Configuration
            </app-button>
            <app-button 
              variant="outline" 
              size="sm" 
              [loading]="isTesting('snapchat')"
              (clicked)="testConfig('snapchat')"
            >
              <app-icon name="sync" [size]="14"></app-icon>
              <span>Test Handshake</span>
            </app-button>
          </div>
          <app-provider-setup-guide providerId="snapchat"></app-provider-setup-guide>
        </div>

        <!-- Google Looker Studio (Google Locker) Integration -->
        <div class="config-card looker-highlight">
          <div class="card-header">
            <div class="card-title-group">
              <div class="platform-icon">
                <app-icon name="looker" [size]="24"></app-icon>
              </div>
              <div>
                <h3 class="card-title">Google Looker Studio (Locker) Integration</h3>
                <p class="card-desc">Real-time sync of unified cross-channel performance metrics directly to Google Looker Studio dashboards.</p>
              </div>
            </div>
            <app-badge [variant]="lookerTestStatus() === 'connected' ? 'success' : 'neutral'">
              {{ lookerTestStatus() === 'connected' ? 'ONLINE & READY' : 'CONNECTOR READY' }}
            </app-badge>
          </div>

          <div class="form-group">
            <label class="form-label">Looker Studio Connector Data Endpoint</label>
            <div class="url-copy-box">
              <input type="text" readonly [value]="lookerEndpointUrl" class="form-input font-mono" />
              <app-button variant="outline" size="sm" (clicked)="copyLookerEndpoint()">
                <app-icon name="copy" [size]="14"></app-icon>
                <span>{{ copiedLookerUrl() ? 'Copied!' : 'Copy URL' }}</span>
              </app-button>
            </div>
            <span class="field-hint">Use this HTTPS endpoint in your Looker Studio Community Connector or Apps Script.</span>
          </div>

          <div class="form-group">
            <label class="form-label">Supported Dimensions & Metrics</label>
            <div class="schema-tags">
              <span class="schema-tag dim">Dimensions: date, platform, account, campaign, currency</span>
              <span class="schema-tag metric">Metrics: spend, clicks, impressions, conversions, roas, cpc</span>
            </div>
          </div>

          <div class="card-actions">
            <app-button 
              variant="primary" 
              size="sm" 
              [loading]="isTestingLooker()"
              (clicked)="testLookerEndpoint()"
            >
              <app-icon name="sync" [size]="14"></app-icon>
              <span>Verify Looker Endpoint</span>
            </app-button>
            <a 
              href="https://lookerstudio.google.com" 
              target="_blank" 
              rel="noopener noreferrer" 
              class="looker-portal-btn"
            >
              <app-icon name="external-link" [size]="14"></app-icon>
              <span>Open Looker Studio</span>
            </a>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .settings-page {
      display: flex;
      flex-direction: column;
      gap: 1.75rem;
    }

    .page-header .title {
      font-size: 1.5rem;
      font-weight: 700;
      color: var(--text-main);
    }

    .page-header .subtitle {
      font-size: 0.875rem;
      color: var(--text-muted);
      margin-top: 0.25rem;
    }

    .feedback-banner {
      padding: 0.875rem 1.25rem;
      border-radius: var(--radius-md);
      font-size: 0.875rem;
      font-weight: 500;
    }

    .feedback-banner.success {
      background-color: var(--success-bg);
      color: var(--success-text);
      border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .feedback-banner.error {
      background-color: var(--danger-bg);
      color: var(--danger-text);
      border: 1px solid rgba(239, 68, 68, 0.2);
    }

    .settings-cards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
      gap: 1.5rem;
    }

    .config-card {
      background-color: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
      box-shadow: var(--shadow-sm);
      transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
    }

    .config-card:hover {
      border-color: var(--border-strong);
      box-shadow: var(--shadow-md);
    }

    .card-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 1rem;
      border-bottom: 1px solid var(--border-subtle);
      padding-bottom: 1rem;
    }

    .card-title-group {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      flex: 1 1 auto;
      min-width: 0;
    }
    .card-header app-badge {
      flex-shrink: 0;
    }

    .platform-icon {
      width: 36px;
      height: 36px;
      border-radius: var(--radius-md);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      font-size: 1rem;
      flex-shrink: 0;
    }

    .meta-icon {
      background: linear-gradient(135deg, #1877f2, #0062e0);
      color: #ffffff;
    }

    .google-icon {
      background: linear-gradient(135deg, #ea4335, #fbbc05);
      color: #ffffff;
    }

    .tiktok-icon {
      background: linear-gradient(135deg, #000000, #25f4ee);
      color: #ffffff;
    }

    .card-title {
      font-size: 1rem;
      font-weight: 600;
      color: var(--text-main);
    }

    .card-desc {
      font-size: 0.75rem;
      color: var(--text-muted);
      margin-top: 0.15rem;
    }

    .form-group {
      display: flex;
      flex-direction: column;
    }

    .secure-tag {
      font-size: 0.75rem;
      color: var(--success);
      font-weight: 600;
      margin-left: 0.5rem;
    }

    .password-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }

    .password-wrapper .form-input {
      padding-right: 2.5rem;
    }

    .toggle-pwd {
      position: absolute;
      right: 0.75rem;
      color: var(--text-muted);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0.25rem;
    }

    .toggle-pwd:hover {
      color: var(--text-main);
    }

    .card-actions {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      margin-top: auto;
      padding-top: 0.75rem;
    }

    .looker-highlight {
      border-color: rgba(66, 133, 244, 0.35);
      background: linear-gradient(180deg, rgba(66, 133, 244, 0.05) 0%, var(--bg-surface) 100%);
    }
    @media (min-width: 1200px) {
      .looker-highlight {
        grid-column: span 2;
      }
    }

    .url-copy-box {
      display: flex;
      gap: 0.5rem;
      align-items: center;
    }

    .url-copy-box .form-input {
      flex: 1;
      font-size: 0.8125rem;
    }

    .field-hint {
      font-size: 0.75rem;
      color: var(--text-muted);
      margin-top: 0.25rem;
    }

    .schema-tags {
      display: flex;
      flex-direction: column;
      gap: 0.375rem;
    }

    .schema-tag {
      font-size: 0.75rem;
      padding: 0.25rem 0.625rem;
      border-radius: var(--radius-sm);
      font-family: monospace;
    }

    .schema-tag.dim {
      background: rgba(99, 102, 241, 0.12);
      color: #818cf8;
      border: 1px solid rgba(99, 102, 241, 0.2);
    }

    .schema-tag.metric {
      background: rgba(16, 185, 129, 0.12);
      color: #34d399;
      border: 1px solid rgba(16, 185, 129, 0.2);
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
      transition: all 0.2s ease;
      &:hover {
        background: rgba(96, 165, 250, 0.1);
        color: #93c5fd;
      }
    }
  `]
})
export class DeveloperSettingsFeatureComponent implements OnInit {
  private readonly http = inject(HttpClient);

  readonly feedbackMessage = signal<string | null>(null);
  readonly feedbackType = signal<'success' | 'error'>('success');
  readonly activeOperations = signal<Record<string, boolean>>({});

  readonly showMetaSecret = signal<boolean>(false);
  readonly showGoogleSecret = signal<boolean>(false);
  readonly showGoogleToken = signal<boolean>(false);
  readonly showTiktokSecret = signal<boolean>(false);
  readonly showSnapchatSecret = signal<boolean>(false);

  readonly lookerStatus = signal<'ready' | 'connected'>('ready');
  readonly lookerTestStatus = signal<'untested' | 'connected' | 'error'>('untested');
  readonly isTestingLooker = signal<boolean>(false);
  readonly copiedLookerUrl = signal<boolean>(false);
  readonly lookerEndpointUrl = `${window.location.origin}/api/v1/reporting/looker-studio`;

  metaConfig: ProviderConfigDto = this.createDefault('meta');
  googleConfig: ProviderConfigDto = this.createDefault('google');
  tiktokConfig: ProviderConfigDto = this.createDefault('tiktok');
  snapchatConfig: ProviderConfigDto = this.createDefault('snapchat');

  metaSecretInput = '';
  googleSecretInput = '';
  googleTokenInput = '';
  tiktokSecretInput = '';
  snapchatSecretInput = '';

  ngOnInit(): void {
    this.fetchConfigs();
  }

  fetchConfigs(): void {
    this.http.get<{ success: boolean; data: ProviderConfigDto[] }>('/api/v1/admin/developer-configs').subscribe({
      next: (res) => {
        if (res.success) {
          for (const item of res.data) {
            if (item.provider === 'meta') this.metaConfig = item;
            if (item.provider === 'google') this.googleConfig = item;
            if (item.provider === 'tiktok') this.tiktokConfig = item;
            if (item.provider === 'snapchat') this.snapchatConfig = item;
          }
        }
      },
      error: () => {}
    });
  }

  isSaving(provider: string): boolean {
    return !!this.activeOperations()[`save_${provider}`];
  }

  isTesting(provider: string): boolean {
    return !!this.activeOperations()[`test_${provider}`];
  }

  saveConfig(provider: 'meta' | 'google' | 'tiktok' | 'snapchat'): void {
    const payload: Record<string, any> = {};
    const key = `save_${provider}`;
    this.setOp(key, true);

    if (provider === 'meta') {
      payload['app_id'] = this.metaConfig.app_id;
      if (this.metaSecretInput) payload['app_secret'] = this.metaSecretInput;
    } else if (provider === 'google') {
      payload['app_id'] = this.googleConfig.app_id;
      if (this.googleSecretInput) payload['app_secret'] = this.googleSecretInput;
      if (this.googleTokenInput) payload['developer_token'] = this.googleTokenInput;
    } else if (provider === 'tiktok') {
      payload['app_id'] = this.tiktokConfig.app_id;
      if (this.tiktokSecretInput) payload['app_secret'] = this.tiktokSecretInput;
    } else if (provider === 'snapchat') {
      payload['app_id'] = this.snapchatConfig.app_id;
      if (this.snapchatSecretInput) payload['app_secret'] = this.snapchatSecretInput;
    }

    this.http.put<{ success: boolean; message: string; data: ProviderConfigDto }>(`/api/v1/admin/developer-configs/${provider}`, payload).subscribe({
      next: (res) => {
        this.setOp(key, false);
        this.showFeedback(res.message, 'success');
        if (provider === 'meta') {
          this.metaConfig = res.data;
          this.metaSecretInput = '';
        } else if (provider === 'google') {
          this.googleConfig = res.data;
          this.googleSecretInput = '';
          this.googleTokenInput = '';
        } else if (provider === 'tiktok') {
          this.tiktokConfig = res.data;
          this.tiktokSecretInput = '';
        } else if (provider === 'snapchat') {
          this.snapchatConfig = res.data;
          this.snapchatSecretInput = '';
        }
      },
      error: (err) => {
        this.setOp(key, false);
        this.showFeedback(err.error?.error?.message || 'Failed to save configuration.', 'error');
      }
    });
  }

  testConfig(provider: 'meta' | 'google' | 'tiktok' | 'snapchat'): void {
    const key = `test_${provider}`;
    this.setOp(key, true);

    this.http.post<{ success: boolean; data: any }>(`/api/v1/admin/developer-configs/${provider}/test`, {}).subscribe({
      next: (res) => {
        this.setOp(key, false);
        this.showFeedback(res.data.message, res.success ? 'success' : 'error');
        this.fetchConfigs();
      },
      error: (err) => {
        this.setOp(key, false);
        this.showFeedback(err.error?.error?.message || 'Handshake failed.', 'error');
        this.fetchConfigs();
      }
    });
  }

  copyLookerEndpoint(): void {
    navigator.clipboard.writeText(this.lookerEndpointUrl);
    this.copiedLookerUrl.set(true);
    this.showFeedback('Looker Studio Connector endpoint URL copied to clipboard!', 'success');
    setTimeout(() => this.copiedLookerUrl.set(false), 3000);
  }

  testLookerEndpoint(): void {
    this.isTestingLooker.set(true);
    this.http.get<{ schema: any[] }>('/api/v1/reporting/looker-studio?schema=true').subscribe({
      next: (res) => {
        this.isTestingLooker.set(false);
        this.lookerTestStatus.set('connected');
        this.lookerStatus.set('connected');
        this.showFeedback(`Google Looker Studio endpoint verified! Schema valid with ${res.schema.length} fields.`, 'success');
      },
      error: (err) => {
        this.isTestingLooker.set(false);
        this.lookerTestStatus.set('error');
        this.showFeedback(err.error?.error?.message || 'Looker Studio test connection failed.', 'error');
      }
    });
  }

  getBadgeVariant(status: string): any {
    switch (status) {
      case 'configured':
      case 'passed':
        return 'success';
      case 'failed':
        return 'danger';
      default:
        return 'neutral';
    }
  }

  private setOp(key: string, val: boolean): void {
    this.activeOperations.update(ops => ({ ...ops, [key]: val }));
  }

  private showFeedback(msg: string, type: 'success' | 'error'): void {
    this.feedbackMessage.set(msg);
    this.feedbackType.set(type);
    setTimeout(() => this.feedbackMessage.set(null), 5000);
  }

  private createDefault(provider: 'meta' | 'google' | 'tiktok' | 'snapchat'): ProviderConfigDto {
    return {
      provider,
      app_id: '',
      has_secret: false,
      masked_secret: null,
      has_developer_token: false,
      masked_developer_token: null,
      redirect_uri: null,
      api_version: null,
      is_enabled: true,
      test_status: 'untested',
      test_error: null,
      last_tested_at: null,
    };
  }
}
