import { Component, inject, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ButtonComponent } from '../../shared/components/button/button.component';
import { BadgeComponent } from '../../shared/components/badge/badge.component';

export interface ProviderConfigDto {
  id?: number;
  provider: 'meta' | 'google' | 'tiktok';
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
  imports: [CommonModule, FormsModule, ButtonComponent, BadgeComponent],
  template: `
    <div class="settings-page">
      <div class="page-header">
        <div>
          <h2 class="title">Developer API Settings Center</h2>
          <p class="subtitle">Securely configure official provider developer credentials. Secrets are encrypted at rest.</p>
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
            <div>
              <h3 class="card-title">Meta Marketing API Credentials</h3>
              <p class="card-desc">App ID and App Secret from Meta for Developers portal.</p>
            </div>
            <app-badge [variant]="getBadgeVariant(metaConfig.test_status)">
              {{ metaConfig.test_status }}
            </app-badge>
          </div>

          <div class="form-group">
            <label class="form-label">App ID</label>
            <input 
              type="text" 
              class="form-input" 
              [(ngModel)]="metaConfig.app_id" 
              placeholder="e.g. 123456789012345" 
            />
          </div>

          <div class="form-group">
            <label class="form-label">
              App Secret 
              @if (metaConfig.has_secret) {
                <span class="secure-tag">(Encrypted: {{ metaConfig.masked_secret }})</span>
              }
            </label>
            <input 
              type="password" 
              class="form-input" 
              [(ngModel)]="metaSecretInput" 
              [placeholder]="metaConfig.has_secret ? 'Enter new secret to rotate' : 'Enter app secret'" 
            />
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
              Test Handshake
            </app-button>
          </div>
        </div>

        <!-- Google Ads Dev Config -->
        <div class="config-card">
          <div class="card-header">
            <div>
              <h3 class="card-title">Google Ads API Credentials</h3>
              <p class="card-desc">OAuth 2.0 Client credentials and Developer Token from Google Cloud / Ads Manager.</p>
            </div>
            <app-badge [variant]="getBadgeVariant(googleConfig.test_status)">
              {{ googleConfig.test_status }}
            </app-badge>
          </div>

          <div class="form-group">
            <label class="form-label">Client ID</label>
            <input 
              type="text" 
              class="form-input" 
              [(ngModel)]="googleConfig.app_id" 
              placeholder="e.g. 12345-abc.apps.googleusercontent.com" 
            />
          </div>

          <div class="form-group">
            <label class="form-label">
              Client Secret 
              @if (googleConfig.has_secret) {
                <span class="secure-tag">(Encrypted: {{ googleConfig.masked_secret }})</span>
              }
            </label>
            <input 
              type="password" 
              class="form-input" 
              [(ngModel)]="googleSecretInput" 
              [placeholder]="googleConfig.has_secret ? 'Enter new secret to rotate' : 'Enter client secret'" 
            />
          </div>

          <div class="form-group">
            <label class="form-label">
              Developer Token
              @if (googleConfig.has_developer_token) {
                <span class="secure-tag">(Encrypted: {{ googleConfig.masked_developer_token }})</span>
              }
            </label>
            <input 
              type="password" 
              class="form-input" 
              [(ngModel)]="googleTokenInput" 
              [placeholder]="googleConfig.has_developer_token ? 'Enter new token to rotate' : 'Enter developer token'" 
            />
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
              Test Handshake
            </app-button>
          </div>
        </div>

        <!-- TikTok Ads Dev Config -->
        <div class="config-card">
          <div class="card-header">
            <div>
              <h3 class="card-title">TikTok Marketing API Credentials</h3>
              <p class="card-desc">App ID and Secret from TikTok for Business Developers.</p>
            </div>
            <app-badge [variant]="getBadgeVariant(tiktokConfig.test_status)">
              {{ tiktokConfig.test_status }}
            </app-badge>
          </div>

          <div class="form-group">
            <label class="form-label">App ID</label>
            <input 
              type="text" 
              class="form-input" 
              [(ngModel)]="tiktokConfig.app_id" 
              placeholder="e.g. 7123456789012345678" 
            />
          </div>

          <div class="form-group">
            <label class="form-label">
              Secret
              @if (tiktokConfig.has_secret) {
                <span class="secure-tag">(Encrypted: {{ tiktokConfig.masked_secret }})</span>
              }
            </label>
            <input 
              type="password" 
              class="form-input" 
              [(ngModel)]="tiktokSecretInput" 
              [placeholder]="tiktokConfig.has_secret ? 'Enter new secret to rotate' : 'Enter app secret'" 
            />
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
              Test Handshake
            </app-button>
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

    .feedback-banner {
      padding: 0.75rem 1rem;
      border-radius: var(--radius-md);
      font-size: 0.875rem;
      font-weight: 500;

      &.success {
        background-color: var(--success-bg);
        color: var(--success-text);
        border: 1px solid var(--success);
      }

      &.error {
        background-color: var(--danger-bg);
        color: var(--danger-text);
        border: 1px solid var(--danger);
      }
    }

    .settings-cards {
      display: flex;
      flex-direction: column;
      gap: 1.5rem;
      max-width: 720px;
    }

    .config-card {
      background-color: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      box-shadow: var(--shadow-sm);
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
      font-size: 1.125rem;
      font-weight: 600;
      color: var(--text-main);
    }

    .card-desc {
      font-size: 0.8125rem;
      color: var(--text-muted);
      margin-top: 0.25rem;
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 0.375rem;
    }

    .form-label {
      font-size: 0.8125rem;
      font-weight: 500;
      color: var(--text-main);
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .secure-tag {
      font-size: 0.75rem;
      color: var(--text-muted);
      font-family: var(--font-family-mono);
    }

    .form-input {
      padding: 0.5rem 0.75rem;
      border: 1px solid var(--border-strong);
      border-radius: var(--radius-md);
      background-color: var(--bg-app);
      color: var(--text-main);
      font-size: 0.875rem;

      &:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 2px var(--primary-ring);
      }
    }

    .card-actions {
      display: flex;
      gap: 0.75rem;
      margin-top: 0.5rem;
    }
  `]
})
export class DeveloperSettingsFeatureComponent implements OnInit {
  private readonly http = inject(HttpClient);

  readonly feedbackMessage = signal<string | null>(null);
  readonly feedbackType = signal<'success' | 'error'>('success');

  readonly activeOperations = signal<Record<string, boolean>>({});

  metaConfig: ProviderConfigDto = this.createDefault('meta');
  googleConfig: ProviderConfigDto = this.createDefault('google');
  tiktokConfig: ProviderConfigDto = this.createDefault('tiktok');

  metaSecretInput = '';
  googleSecretInput = '';
  googleTokenInput = '';
  tiktokSecretInput = '';

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
          }
        }
      },
      error: () => {
        // Will be empty if unauthenticated in tests
      }
    });
  }

  isSaving(provider: string): boolean {
    return !!this.activeOperations()[`save_${provider}`];
  }

  isTesting(provider: string): boolean {
    return !!this.activeOperations()[`test_${provider}`];
  }

  saveConfig(provider: 'meta' | 'google' | 'tiktok'): void {
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
        }
      },
      error: (err) => {
        this.setOp(key, false);
        this.showFeedback(err.error?.error?.message || 'Failed to save configuration.', 'error');
      }
    });
  }

  testConfig(provider: 'meta' | 'google' | 'tiktok'): void {
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

  private createDefault(provider: 'meta' | 'google' | 'tiktok'): ProviderConfigDto {
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
