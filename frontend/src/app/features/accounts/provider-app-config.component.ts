import { Component, inject, input, model, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ButtonComponent } from '../../shared/components/button/button.component';
import { BadgeComponent, BadgeVariant } from '../../shared/components/badge/badge.component';
import { IconComponent } from '../../shared/components/icon/icon.component';

export type ProviderId = 'meta' | 'google' | 'tiktok' | 'snapchat' | 'taboola' | 'pinterest' | 'reddit' | 'spotify' | 'microsoft' | 'linkedin' | 'apple' | 'amazon' | 'x';

export interface ProviderConfigDto {
  id?: number;
  provider: ProviderId;
  app_id: string | null;
  has_secret: boolean;
  masked_secret: string | null;
  has_developer_token: boolean;
  masked_developer_token: string | null;
  redirect_uri: string | null;
  api_version: string | null;
  region?: string | null;
  is_enabled: boolean;
  test_status: string;
  test_error: string | null;
  last_tested_at: string | null;
}

export function createDefaultConfig(provider: ProviderId): ProviderConfigDto {
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

/**
 * Step 1 of the unified integration journey: the platform's own developer app
 * credentials for one ad provider. Admin-only (manage-developers): the backend
 * enforces the same permission on /api/v1/admin/developer-configs.
 *
 * These credentials identify THIS platform's app at the provider. They never
 * hold customer data: ad data always comes from the OAuth-connected account in
 * Step 2. Secrets are write-only here; the API returns them masked.
 */
@Component({
  selector: 'app-provider-app-config',
  standalone: true,
  imports: [CommonModule, FormsModule, ButtonComponent, BadgeComponent, IconComponent],
  template: `
    @if (config(); as cfg) {
      <details class="app-setup" [attr.open]="(configStatus() !== 'loaded' || !isComplete(cfg)) ? '' : null" [attr.data-provider]="cfg.provider">
        <summary>
          <span class="step-label">Step 1 · Platform app setup (Admin)</span>
          <app-badge [variant]="badgeVariant(cfg)">{{ badgeText(cfg) }}</app-badge>
        </summary>

        <p class="setup-explainer">
          One setup per platform, for the app owner only. These credentials identify this platform's app at
          {{ providerName }}; report access comes from Step 2. Taboola and Apple instead use shared client credentials and are restricted to administrators.
        </p>

        @if (cfg.provider !== 'taboola' && cfg.provider !== 'apple') {
        <div class="form-group">
          <label class="form-label">Redirect URI to register in the {{ providerName }} app</label>
          <div class="url-copy-box">
            <input type="text" readonly [value]="redirectUri" class="form-input mono" />
            <app-button variant="outline" size="sm" (clicked)="copyRedirectUri()">
              <app-icon name="copy" [size]="14"></app-icon>
              <span>{{ copiedUri() ? 'Copied!' : 'Copy' }}</span>
            </app-button>
          </div>
          <span class="field-hint">Callback used by this deployment. Register it in the provider app AND the backend OAUTH_ALLOWED_REDIRECT_URIS allowlist. This does not confirm either is configured. Use the final HTTPS deployment, not a local preview.</span>
        </div>

        }
        @if (message()) {
          <div [class]="'inline-feedback ' + messageType()">{{ message() }}</div>
        }

        <div class="form-group">
          <label class="form-label" [for]="cfg.provider + 'AppId'">{{ idLabel }}</label>
          <input
            [id]="cfg.provider + 'AppId'"
            type="text"
            class="form-input"
            [(ngModel)]="appIdInput"
            [placeholder]="idPlaceholder"
          />
        </div>

        <div class="form-group">
          <label class="form-label" [for]="cfg.provider + 'Secret'">
            {{ secretLabel }}
            @if (cfg.has_secret) {
              <span class="secure-tag">(Saved, encrypted: {{ cfg.masked_secret }})</span>
            }
          </label>
          <div class="password-wrapper">
            <input
              [id]="cfg.provider + 'Secret'"
              [type]="showSecret() ? 'text' : 'password'"
              class="form-input"
              [(ngModel)]="secretInput"
              [placeholder]="cfg.has_secret ? 'Enter new secret to rotate' : 'Enter ' + secretLabel"
            />
            <button type="button" class="toggle-pwd" aria-label="Toggle new secret visibility" (click)="showSecret.update(v => !v)">
              <app-icon [name]="showSecret() ? 'eye-off' : 'eye'" [size]="15"></app-icon>
            </button>
          </div>
        </div>

        @if (['google', 'microsoft'].includes(cfg.provider)) {
          <div class="form-group">
            <label class="form-label" for="googleDevToken">
              Developer Token
              @if (cfg.has_developer_token) {
                <span class="secure-tag">(Saved, encrypted: {{ cfg.masked_developer_token }})</span>
              }
            </label>
            <div class="password-wrapper">
              <input
                id="googleDevToken"
                [type]="showToken() ? 'text' : 'password'"
                class="form-input"
                [(ngModel)]="tokenInput"
                [placeholder]="cfg.has_developer_token ? 'Enter new token to rotate' : 'Enter developer token'"
              />
              <button type="button" class="toggle-pwd" aria-label="Toggle new token visibility" (click)="showToken.update(v => !v)">
                <app-icon [name]="showToken() ? 'eye-off' : 'eye'" [size]="15"></app-icon>
              </button>
            </div>
          </div>
        }

        @if (cfg.provider === 'amazon') {
          <label class="form-label" for="amazonRegion">Amazon profile region</label>
          <select id="amazonRegion" class="form-input" [(ngModel)]="regionInput"><option value="">Choose region</option><option value="NA">North America</option><option value="EU">Europe / Middle East (including Egypt)</option><option value="FE">Far East</option></select>
          <p class="field-hint">One configured region per app in this release. Report imports require a verified download-host allowlist and endDate policy in deployment.</p>
        }
        @if (cfg.provider === 'linkedin') {
          <div class="form-group">
            <label class="form-label" for="linkedinVersion">LinkedIn API version (YYYYMM)</label>
            <input id="linkedinVersion" type="text" class="form-input" [(ngModel)]="versionInput" placeholder="Supported YYYYMM version, e.g. 202611" />
            <span class="field-hint">Verify the version is currently supported. Conversion-value reporting is disabled until verified in deployment configuration.</span>
          </div>
        }
        <div class="card-actions">
          <app-button variant="primary" size="sm" [loading]="saving()" [disabled]="configStatus() !== 'loaded'" (clicked)="save()">
            Save {{ providerName }} App
          </app-button>
          <app-button variant="outline" size="sm" [loading]="testing()" [disabled]="!cfg.app_id || !cfg.has_secret" (clicked)="test()">
            <app-icon name="sync" [size]="14"></app-icon>
            <span>Check saved fields</span>
          </app-button>
        </div>
        @if (cfg.last_tested_at) {
          <span class="field-hint">This checks saved field presence only, not provider approval or a live connection.</span>
          <span class="field-hint">Last checked: {{ cfg.last_tested_at | date:'medium' }}</span>
        }
      </details>
    }
  `,
  styles: [`
    .app-setup {
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-md);
      padding: 0.625rem 0.875rem;
      background: var(--bg-subtle);
    }
    .app-setup summary {
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      list-style: none;
      flex-wrap: wrap;
    }
    .app-setup summary::-webkit-details-marker { display: none; }
    .step-label { font-size: 0.8125rem; font-weight: 600; color: var(--text-main); }
    .setup-explainer {
      font-size: 0.75rem;
      color: var(--text-muted);
      margin: 0.625rem 0 0.25rem;
      line-height: 1.45;
    }
    .inline-feedback {
      margin-top: 0.5rem;
      padding: 0.5rem 0.75rem;
      border-radius: var(--radius-sm);
      font-size: 0.75rem;
      font-weight: 500;
    }
    .inline-feedback.success {
      background-color: var(--success-bg);
      color: var(--success-text);
      border: 1px solid rgba(16, 185, 129, 0.2);
    }
    .inline-feedback.error {
      background-color: var(--danger-bg);
      color: var(--danger-text);
      border: 1px solid rgba(239, 68, 68, 0.2);
    }
    .form-group { display: flex; flex-direction: column; margin-top: 0.75rem; }
    .form-label { font-size: 0.75rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.3rem; }
    .form-input {
      width: 100%;
      min-width: 0;
      padding: 0.5rem 0.75rem;
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-sm);
      background: var(--bg-surface);
      color: var(--text-main);
      font-size: 0.8125rem;
      box-sizing: border-box;
    }
    .form-input:focus { outline: 2px solid var(--primary); outline-offset: 0; border-color: var(--primary); }
    .secure-tag { font-size: 0.7rem; color: var(--success); font-weight: 600; margin-left: 0.4rem; }
    .password-wrapper { position: relative; display: flex; align-items: center; }
    .password-wrapper .form-input { padding-right: 2.5rem; }
    .toggle-pwd {
      position: absolute;
      right: 0.75rem;
      color: var(--text-muted);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0.25rem;
      background: none;
      border: none;
      cursor: pointer;
    }
    .card-actions {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      margin-top: 0.875rem;
      flex-wrap: wrap;
    }
    .field-hint { display: block; font-size: 0.7rem; color: var(--text-muted); margin-top: 0.5rem; }
    .url-copy-box { display: flex; gap: 0.5rem; align-items: center; }
    .url-copy-box .form-input.mono { font-family: monospace; font-size: 0.75rem; background: var(--bg-surface); }
  `]
})
export class ProviderAppConfigComponent {
  private readonly http = inject(HttpClient);

  readonly provider = input.required<ProviderId>();
  readonly config = model.required<ProviderConfigDto>();
  // Parent fetch state: on 'error' the UI must say "unknown", never claim the
  // configuration is missing — an unreachable backend is not an empty config.
  readonly configStatus = input<'loading' | 'loaded' | 'error'>('loaded');

  appIdInput = '';
  secretInput = '';
  tokenInput = '';
  versionInput = '';

  readonly showSecret = signal<boolean>(false);
  readonly showToken = signal<boolean>(false);
  readonly saving = signal<boolean>(false);
  readonly testing = signal<boolean>(false);
  readonly message = signal<string | null>(null);
  readonly messageType = signal<'success' | 'error'>('success');

  readonly redirectUri = `${window.location.origin}/accounts`;
  readonly copiedUri = signal<boolean>(false);

  regionInput = '';
  private appIdLoaded = false;

  ngDoCheck(): void {
    // Keep the visible App ID in sync with the loaded config without
    // overwriting what the admin is typing after the first load.
    if (!this.appIdLoaded) {
      const cfg = this.config();
      if (cfg.app_id) {
        this.appIdInput = cfg.app_id;
        this.versionInput = cfg.api_version ?? '';
        this.regionInput = cfg.region ?? '';
        this.appIdLoaded = true;
      }
    }
  }

  get providerName(): string {
    switch (this.provider()) {
      case 'meta': return 'Meta';
      case 'google': return 'Google';
      case 'tiktok': return 'TikTok';
      case 'snapchat': return 'Snapchat';
      case 'x': return 'X Ads';
      case 'amazon': return 'Amazon Ads';
      case 'apple': return 'Apple Ads';
      case 'microsoft': return 'Microsoft Advertising';
      case 'linkedin': return 'LinkedIn';
      case 'spotify': return 'Spotify';
      case 'reddit': return 'Reddit';
      case 'pinterest': return 'Pinterest';
      case 'taboola': return 'Taboola';
    }
  }

  get idLabel(): string {
    return this.provider()==='x'?'Consumer API key':(this.provider() === 'google' || this.provider() === 'snapchat' ? 'Client ID' : 'App ID');
  }

  get idPlaceholder(): string {
    switch (this.provider()) {
      case 'meta': return 'e.g. 123456789012345';
      case 'google': return 'e.g. 12345-abc.apps.googleusercontent.com';
      case 'tiktok': return 'e.g. 6900000000000000000';
      case 'snapchat': return 'OAuth app client ID';
      case 'x': return 'X consumer API key (OAuth1, not OAuth2 client ID)';
      case 'amazon': return 'Login with Amazon client ID';
      case 'apple': return 'Apple client ID';
      case 'microsoft': return 'Microsoft Entra client ID';
      case 'linkedin': return 'LinkedIn client ID';
      case 'spotify': return 'Spotify client ID';
      case 'reddit': return 'Reddit app ID';
      case 'pinterest': return 'Pinterest app ID';
      case 'taboola': return 'Taboola API client ID';
    }
  }

  get secretLabel(): string {
    if (this.provider() === 'x') return 'Consumer API secret (OAuth1)';
    if (this.provider() === 'apple') return 'Pre-signed ES256 client-secret JWT';
    return this.provider() === 'google' || this.provider() === 'snapchat' ? 'Client Secret' : 'App Secret';
  }

  isComplete(cfg: ProviderConfigDto): boolean {
    const base = !!cfg.app_id && cfg.has_secret;
    return ['google', 'microsoft'].includes(cfg.provider) ? base && cfg.has_developer_token : base;
  }

  badgeText(cfg: ProviderConfigDto): string {
    if (this.configStatus() === 'error') return 'STATUS UNKNOWN';
    if (this.configStatus() === 'loading') return 'CHECKING...';
    return this.isComplete(cfg) ? 'CREDENTIALS SAVED' : 'SETUP NEEDED';
  }

  badgeVariant(cfg: ProviderConfigDto): BadgeVariant {
    if (this.configStatus() !== 'loaded') return 'neutral';
    if (this.isComplete(cfg)) {
      return cfg.test_status === 'failed' ? 'warning' : 'success';
    }
    return 'warning';
  }

  copyRedirectUri(): void {
    navigator.clipboard.writeText(this.redirectUri);
    this.copiedUri.set(true);
    setTimeout(() => this.copiedUri.set(false), 3000);
  }

  save(): void {
    if (this.configStatus() !== 'loaded') return;
    const cfg = this.config();
    const payload: Record<string, any> = { app_id: this.appIdInput };
    if (cfg.provider === 'amazon') payload['region'] = this.regionInput || null;
    if (cfg.provider === 'linkedin') payload['api_version'] = this.versionInput;
    if (this.secretInput) payload['app_secret'] = this.secretInput;
    if (['google', 'microsoft'].includes(cfg.provider) && this.tokenInput) payload['developer_token'] = this.tokenInput;

    this.saving.set(true);
    this.http.put<{ success: boolean; message: string; data: ProviderConfigDto }>(
      `/api/v1/admin/developer-configs/${cfg.provider}`, payload
    ).subscribe({
      next: (res) => {
        this.saving.set(false);
        this.config.set(res.data);
        this.appIdInput = res.data.app_id ?? '';
        this.secretInput = '';
        this.tokenInput = '';
        this.flash(res.message || 'Configuration saved.', 'success');
      },
      error: (err) => {
        this.saving.set(false);
        this.flash(err.error?.error?.message || 'Failed to save configuration.', 'error');
      }
    });
  }

  test(): void {
    const cfg = this.config();
    this.testing.set(true);
    this.http.post<{ success: boolean; data: any }>(
      `/api/v1/admin/developer-configs/${cfg.provider}/test`, {}
    ).subscribe({
      next: (res) => {
        this.testing.set(false);
        this.config.update(c => ({
          ...c,
          test_status: res.data?.test_status ?? c.test_status,
          last_tested_at: res.data?.last_tested_at ?? c.last_tested_at,
        }));
        this.flash(res.data?.message || 'Saved fields checked.', res.success ? 'success' : 'error');
      },
      error: (err) => {
        this.testing.set(false);
        this.config.update(c => ({ ...c, test_status: 'failed' }));
        this.flash(err.error?.error?.message || 'Saved-field check failed.', 'error');
      }
    });
  }

  private flash(msg: string, type: 'success' | 'error'): void {
    this.message.set(msg);
    this.messageType.set(type);
    setTimeout(() => this.message.set(null), 5000);
  }
}
