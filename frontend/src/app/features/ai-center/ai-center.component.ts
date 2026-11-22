import { Component, inject, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ButtonComponent } from '../../shared/components/button/button.component';
import { BadgeComponent } from '../../shared/components/badge/badge.component';
import { IconComponent } from '../../shared/components/icon/icon.component';
import { ModalComponent } from '../../shared/components/modal/modal.component';

export interface AiConfigDto {
  id: number;
  provider: string;
  model: string;
  base_url?: string | null;
  is_active: boolean;
  is_configured: boolean;
  api_key_masked: string | null;
  monthly_token_limit: number | null;
  current_month_tokens: number;
}

@Component({
  selector: 'app-ai-center-feature',
  standalone: true,
  imports: [CommonModule, FormsModule, ButtonComponent, BadgeComponent, IconComponent, ModalComponent],
  template: `
    <div class="ai-page">
      <!-- Header -->
      <div class="page-header">
        <div>
          <h2 class="title">AI Model APIs</h2>
          <p class="subtitle">Bring-Your-Own-Key (BYOK) model connection. The connected model has one bounded job: it orders the deterministic Operations Research suggestions and attaches fixed, server-written notes. It never writes free text and never touches campaigns or budgets.</p>
        </div>
      </div>

      <!-- Feedback Banner -->
      @if (feedbackMessage()) {
        <div [class]="'feedback-banner ' + feedbackType()">
          <span>{{ feedbackMessage() }}</span>
        </div>
      }

      <!-- Connected AI APIs (the only AI surface) -->
      @if (activeTab() === 'settings') {
        <div class="tab-content">
          <div class="ai-role-card">
            <app-icon name="ai" [size]="20"></app-icon>
            <div>
              <h4>What the connected model does here</h4>
              <p>It only <strong>orders</strong> the Operations Research suggestions and picks fixed, server-written notes for them. Suggestion wording, numbers, platforms and actions always come from the deterministic engine, never from the model. If the model is unreachable or answers outside that contract, the deterministic order is kept automatically.</p>
            </div>
          </div>

          <div class="ai-apis-header">
            <div>
              <h3 class="section-title">Connected AI APIs</h3>
              <p class="section-subtitle">Connect a supported model (DeepSeek, Groq, OpenAI, Claude, Gemini, Ollama) with your own API key for that provider.</p>
            </div>
            <app-button variant="primary" (clicked)="openAddModal()">
              <app-icon name="plus" [size]="16"></app-icon>
              <span>Add AI API (أضف API)</span>
            </app-button>
          </div>

          <!-- Connected Models Table / List -->
          @if (configuredModels().length > 0) {
            <div class="models-table-card">
              <table class="models-table">
                <thead>
                  <tr>
                    <th>Provider / Name</th>
                    <th>Model</th>
                    <th>API Endpoint</th>
                    <th>Status</th>
                    <th>State</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  @for (m of configuredModels(); track m.provider) {
                    <tr>
                      <td class="provider-cell">
                        <span class="provider-badge-tag">{{ m.provider | uppercase }}</span>
                      </td>
                      <td class="font-mono model-name">{{ m.model }}</td>
                      <td class="font-mono text-muted text-xs">{{ m.base_url || 'Default Cloud Gateway' }}</td>
                      <td>
                        @if (connectionStatus[m.provider]) {
                          <span [class]="'status-pill ' + (connectionStatus[m.provider].success ? 'ok' : 'err')">
                            {{ connectionStatus[m.provider].text }}
                          </span>
                        } @else {
                          <span class="status-pill ready">Ready</span>
                        }
                      </td>
                      <td>
                        <app-badge [variant]="m.is_active ? 'success' : 'neutral'">
                          {{ m.is_active ? 'ACTIVE' : 'STANDBY' }}
                        </app-badge>
                      </td>
                      <td>
                        <div class="table-actions">
                          <app-button 
                            variant="outline" 
                            size="sm" 
                            [loading]="isTestingProvider(m.provider)"
                            (clicked)="testConnection(m.provider)"
                          >
                            <app-icon name="sync" [size]="13"></app-icon>
                            <span>Test Ping</span>
                          </app-button>
                          <button 
                            type="button" 
                            class="delete-btn" 
                            (click)="deleteModel(m.provider)"
                            title="Remove Model"
                          >
                            <app-icon name="trash" [size]="15"></app-icon>
                          </button>
                        </div>
                      </td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
          } @else {
            <div class="empty-ai-box">
              <div class="empty-icon-circle">
                <app-icon name="ai" [size]="32"></app-icon>
              </div>
              <h4>No AI APIs Connected Yet</h4>
              <p>Add your model API key (DeepSeek, Groq, OpenAI, Claude, Gemini, Ollama, etc.). It will only order Operations Research suggestions with fixed server-written notes.</p>
              <app-button variant="primary" (clicked)="openAddModal()">
                <app-icon name="plus" [size]="15"></app-icon>
                <span>Add AI API (أضف API)</span>
              </app-button>
            </div>
          }
        </div>
      }
    </div>

    <!-- Modal: Add New AI API -->
    <app-modal 
      [isOpen]="isAddModalOpen()" 
      title="Add AI API (إضافة واجهة ذكاء اصطناعي)" 
      (close)="closeAddModal()"
    >
      <div class="modal-form-content">
        <!-- Quick 1-Click Template Selector -->
        <div class="field">
          <label class="form-label">Quick 1-Click Presets (اختيار سريع)</label>
          <div class="modal-preset-pills">
            <button type="button" class="mini-pill" (click)="selectModalPreset('deepseek')">DeepSeek</button>
            <button type="button" class="mini-pill" (click)="selectModalPreset('groq')">Groq</button>
            <button type="button" class="mini-pill" (click)="selectModalPreset('openrouter')">OpenRouter</button>
            <button type="button" class="mini-pill" (click)="selectModalPreset('ollama')">Local Ollama</button>
            <button type="button" class="mini-pill" (click)="selectModalPreset('openai')">OpenAI</button>
            <button type="button" class="mini-pill" (click)="selectModalPreset('anthropic')">Claude</button>
            <button type="button" class="mini-pill" (click)="selectModalPreset('gemini')">Gemini</button>
          </div>
        </div>

        <div class="field">
          <label class="form-label">Provider Name / Alias (اسم المزود)</label>
          <input 
            type="text" 
            [(ngModel)]="newApiForm.provider" 
            placeholder="e.g. deepseek, groq, openai, custom-model"
            class="form-input font-mono" 
          />
        </div>

        <div class="field">
          <label class="form-label">API Base URL / Endpoint (رابط الـ API)</label>
          <input 
            type="text" 
            [(ngModel)]="newApiForm.base_url" 
            placeholder="e.g. https://api.deepseek.com/v1 or https://api.groq.com/openai/v1"
            class="form-input font-mono" 
          />
        </div>

        <div class="field">
          <label class="form-label">Model Identifier (اسم الموديل)</label>
          <input 
            type="text" 
            [(ngModel)]="newApiForm.model" 
            placeholder="e.g. deepseek-chat, llama-3.3-70b-versatile, gpt-4o"
            class="form-input font-mono" 
          />
        </div>

        <div class="field">
          <label class="form-label">API Key / Bearer Token (مفتاح الـ API)</label>
          <div class="password-wrapper">
            <input 
              [type]="showApiKey() ? 'text' : 'password'" 
              [(ngModel)]="newApiForm.api_key" 
              placeholder="sk-•••••••• or Bearer token"
              class="form-input font-mono" 
            />
            <button type="button" class="toggle-pwd" (click)="showApiKey.update(v => !v)">
              <app-icon [name]="showApiKey() ? 'eye-off' : 'eye'" [size]="15"></app-icon>
            </button>
          </div>
        </div>

        @if (modalTestResult()) {
          <div [class]="'modal-test-status ' + (modalTestResult()!.success ? 'ok' : 'err')">
            {{ modalTestResult()!.text }}
          </div>
        }
      </div>

      <div footer class="modal-footer-btns">
        <app-button 
          variant="outline" 
          size="sm" 
          [loading]="isTestingModal()"
          (clicked)="testModalApi()"
        >
          <app-icon name="sync" [size]="14"></app-icon>
          <span>Test Connection</span>
        </app-button>
        <div class="footer-right">
          <app-button variant="outline" size="sm" (clicked)="closeAddModal()">Cancel</app-button>
          <app-button variant="primary" size="sm" [loading]="isSavingModal()" (clicked)="saveModalApi()">Save & Connect</app-button>
        </div>
      </div>
    </app-modal>
  `,
  styles: [`
    .ai-page {
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

    .nav-tabs {
      display: flex;
      gap: 0.5rem;
      border-bottom: 1px solid var(--border-subtle);
      padding-bottom: 0.5rem;
    }

    .tab-btn {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem 1rem;
      border-radius: var(--radius-md);
      font-size: 0.875rem;
      font-weight: 600;
      color: var(--text-muted);
      transition: all var(--transition-fast);

      &:hover {
        color: var(--text-main);
        background-color: var(--bg-surface-hover);
      }

      &.active {
        color: var(--primary);
        background-color: var(--primary-light);
      }
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
      border: 1px solid rgba(16, 185, 129, 0.3);
    }

    .feedback-banner.error {
      background-color: var(--danger-bg);
      color: var(--danger-text);
      border: 1px solid rgba(239, 68, 68, 0.3);
    }

    /* Connected AI APIs view */
    .ai-role-card {
      display: flex;
      gap: 0.875rem;
      align-items: flex-start;
      background: var(--primary-light);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-md);
      padding: 1rem 1.25rem;
      margin-bottom: 1.5rem;
      color: var(--text-main);
    }

    .ai-role-card app-icon { color: var(--primary); margin-top: 0.15rem; }
    .ai-role-card h4 { font-size: 0.875rem; font-weight: 700; margin-bottom: 0.35rem; }
    .ai-role-card p { font-size: 0.8125rem; color: var(--text-muted); line-height: 1.55; }

    .ai-apis-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      margin-bottom: 1.5rem;
      flex-wrap: wrap;
    }

    .section-title {
      font-size: 1.25rem;
      font-weight: 700;
      color: var(--text-main);
    }

    .section-subtitle {
      font-size: 0.875rem;
      color: var(--text-muted);
      margin-top: 0.2rem;
    }

    .models-table-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-md);
      overflow-x: auto;
    }

    .models-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
      font-size: 0.875rem;
    }

    .models-table th {
      padding: 0.875rem 1rem;
      background: var(--bg-subtle);
      color: var(--text-muted);
      font-weight: 600;
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      border-bottom: 1px solid var(--border-subtle);
    }

    .models-table td {
      padding: 0.875rem 1rem;
      border-bottom: 1px solid var(--border-subtle);
      vertical-align: middle;
    }

    .provider-badge-tag {
      font-size: 0.75rem;
      font-weight: 700;
      background: rgba(99, 102, 241, 0.15);
      color: #818cf8;
      padding: 0.2rem 0.5rem;
      border-radius: var(--radius-sm);
    }

    .model-name {
      color: #60a5fa;
      font-weight: 600;
    }

    .status-pill {
      font-size: 0.6875rem;
      font-weight: 700;
      padding: 0.2rem 0.5rem;
      border-radius: var(--radius-sm);
    }

    .status-pill.ok {
      background-color: var(--success-bg);
      color: var(--success);
    }

    .status-pill.err {
      background-color: var(--danger-bg);
      color: var(--danger);
    }

    .status-pill.ready {
      background-color: var(--bg-subtle);
      color: var(--text-muted);
    }

    .table-actions {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .delete-btn {
      color: var(--text-muted);
      padding: 0.35rem;
      border-radius: var(--radius-sm);
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.2s ease;
      &:hover {
        color: var(--danger);
        background: var(--danger-bg);
      }
    }

    .empty-ai-box {
      background: var(--bg-surface);
      border: 1px dashed var(--border-subtle);
      border-radius: var(--radius-md);
      padding: 4rem 2rem;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      gap: 1rem;
    }

    .empty-icon-circle {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      background: var(--primary-light);
      color: var(--primary);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .empty-ai-box h4 {
      font-size: 1.125rem;
      font-weight: 700;
      color: var(--text-main);
    }

    .empty-ai-box p {
      font-size: 0.875rem;
      color: var(--text-muted);
      max-width: 440px;
    }

    /* Modal Form Styles */
    .modal-form-content {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .modal-preset-pills {
      display: flex;
      gap: 0.4rem;
      flex-wrap: wrap;
      margin-top: 0.25rem;
    }

    .mini-pill {
      font-size: 0.75rem;
      font-weight: 600;
      padding: 0.25rem 0.6rem;
      border-radius: 9999px;
      border: 1px solid var(--border-subtle);
      background: var(--bg-subtle);
      color: var(--text-main);
      cursor: pointer;
      transition: all 0.2s ease;
      &:hover {
        border-color: var(--primary);
        background: var(--primary-light);
      }
    }

    .password-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }

    .password-wrapper .form-input {
      padding-right: 2.5rem;
      width: 100%;
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

    .modal-test-status {
      padding: 0.625rem;
      border-radius: var(--radius-sm);
      font-size: 0.8125rem;
      font-weight: 600;
    }

    .modal-test-status.ok {
      background: var(--success-bg);
      color: var(--success-text);
      border: 1px solid rgba(16, 185, 129, 0.3);
    }

    .modal-test-status.err {
      background: var(--danger-bg);
      color: var(--danger-text);
      border: 1px solid rgba(239, 68, 68, 0.3);
    }

    .modal-footer-btns {
      display: flex;
      align-items: center;
      justify-content: space-between;
      width: 100%;
      gap: 0.75rem;
    }

    .footer-right {
      display: flex;
      gap: 0.5rem;
    }
  `]
})
export class AiCenterFeatureComponent implements OnInit {
  private readonly http = inject(HttpClient);

  readonly activeTab = signal<'settings'>('settings');

  readonly feedbackMessage = signal<string | null>(null);
  readonly feedbackType = signal<'success' | 'error'>('success');

  readonly configuredModels = signal<AiConfigDto[]>([]);
  readonly testingProviders = signal<Record<string, boolean>>({});
  connectionStatus: Record<string, { success: boolean; text: string }> = {};

  readonly isAddModalOpen = signal<boolean>(false);
  readonly showApiKey = signal<boolean>(false);
  readonly isTestingModal = signal<boolean>(false);
  readonly isSavingModal = signal<boolean>(false);
  readonly modalTestResult = signal<{ success: boolean; text: string } | null>(null);

  newApiForm = {
    provider: 'deepseek',
    model: 'deepseek-chat',
    base_url: 'https://api.deepseek.com/v1',
    api_key: '',
  };


  ngOnInit(): void {
    this.loadConfigs();
  }

  loadConfigs(): void {
    this.http.get<{ configs: AiConfigDto[] }>('/api/v1/ai/config').subscribe({
      next: (res) => {
        this.configuredModels.set(res.configs || []);
      }
    });
  }

  openAddModal(): void {
    this.modalTestResult.set(null);
    this.isAddModalOpen.set(true);
  }

  closeAddModal(): void {
    this.isAddModalOpen.set(false);
  }

  selectModalPreset(preset: 'deepseek' | 'groq' | 'openrouter' | 'ollama' | 'openai' | 'anthropic' | 'gemini'): void {
    if (preset === 'deepseek') {
      this.newApiForm.provider = 'deepseek';
      this.newApiForm.base_url = 'https://api.deepseek.com/v1';
      this.newApiForm.model = 'deepseek-chat';
    } else if (preset === 'groq') {
      this.newApiForm.provider = 'groq';
      this.newApiForm.base_url = 'https://api.groq.com/openai/v1';
      this.newApiForm.model = 'llama-3.3-70b-versatile';
    } else if (preset === 'openrouter') {
      this.newApiForm.provider = 'openrouter';
      this.newApiForm.base_url = 'https://openrouter.ai/api/v1';
      this.newApiForm.model = 'meta-llama/llama-3.3-70b-instruct';
    } else if (preset === 'ollama') {
      this.newApiForm.provider = 'ollama';
      this.newApiForm.base_url = 'http://localhost:11434/v1';
      this.newApiForm.model = 'qwen2.5:14b';
    } else if (preset === 'openai') {
      this.newApiForm.provider = 'openai';
      this.newApiForm.base_url = '';
      this.newApiForm.model = 'gpt-4o';
    } else if (preset === 'anthropic') {
      this.newApiForm.provider = 'anthropic';
      this.newApiForm.base_url = '';
      this.newApiForm.model = 'claude-3-5-sonnet-20241022';
    } else if (preset === 'gemini') {
      this.newApiForm.provider = 'gemini';
      this.newApiForm.base_url = '';
      this.newApiForm.model = 'gemini-1.5-pro';
    }
  }

  testModalApi(): void {
    if (!this.newApiForm.api_key && this.newApiForm.provider !== 'ollama') {
      this.modalTestResult.set({ success: false, text: 'Please enter an API Key first.' });
      return;
    }

    this.isTestingModal.set(true);
    this.modalTestResult.set(null);

    // Save temporary config and test
    const provider = this.newApiForm.provider.trim().toLowerCase() || 'custom';
    this.http.put(`/api/v1/ai/config/${provider}`, {
      api_key: this.newApiForm.api_key,
      model: this.newApiForm.model,
      base_url: this.newApiForm.base_url || undefined,
      is_active: true,
    }).subscribe({
      next: () => {
        this.http.post<{ message: string; result: any }>(`/api/v1/ai/config/${provider}/test`, {}).subscribe({
          next: (res) => {
            this.isTestingModal.set(false);
            const latency = res.result?.latency_ms ? ` (${res.result.latency_ms}ms)` : '';
            this.modalTestResult.set({ success: true, text: `✓ Handshake verified${latency}` });
          },
          error: (err) => {
            this.isTestingModal.set(false);
            this.modalTestResult.set({ success: false, text: err.error?.message || 'Handshake failed.' });
          }
        });
      },
      error: (err) => {
        this.isTestingModal.set(false);
        this.modalTestResult.set({ success: false, text: err.error?.message || 'Failed to save config.' });
      }
    });
  }

  saveModalApi(): void {
    const provider = this.newApiForm.provider.trim().toLowerCase() || 'custom';
    this.isSavingModal.set(true);

    this.http.put(`/api/v1/ai/config/${provider}`, {
      api_key: this.newApiForm.api_key,
      model: this.newApiForm.model,
      base_url: this.newApiForm.base_url || undefined,
      is_active: true,
    }).subscribe({
      next: () => {
        this.isSavingModal.set(false);
        this.showFeedback(`AI API for ${provider.toUpperCase()} saved successfully!`, 'success');
        this.closeAddModal();
        this.loadConfigs();
      },
      error: (err) => {
        this.isSavingModal.set(false);
        this.showFeedback(err.error?.message || 'Failed to save AI API.', 'error');
      }
    });
  }

  deleteModel(provider: string): void {
    if (!confirm(`Are you sure you want to remove ${provider.toUpperCase()} API?`)) return;

    this.http.delete(`/api/v1/ai/config/${provider}`).subscribe({
      next: () => {
        this.showFeedback(`AI API for ${provider.toUpperCase()} removed.`, 'success');
        this.loadConfigs();
      },
      error: (err) => {
        this.showFeedback(err.error?.message || 'Failed to remove model.', 'error');
      }
    });
  }

  isTestingProvider(provider: string): boolean {
    return !!this.testingProviders()[provider];
  }

  testConnection(provider: string): void {
    this.testingProviders.update(p => ({ ...p, [provider]: true }));

    this.http.post<{ message: string; result: any }>(`/api/v1/ai/config/${provider}/test`, {}).subscribe({
      next: (res) => {
        this.testingProviders.update(p => ({ ...p, [provider]: false }));
        const latency = res.result?.latency_ms ? ` (${res.result.latency_ms}ms)` : '';
        this.connectionStatus[provider] = {
          success: true,
          text: `✓ Connected${latency}`
        };
        this.showFeedback(`Connection verified for ${provider.toUpperCase()}${latency}.`, 'success');
      },
      error: (err) => {
        this.testingProviders.update(p => ({ ...p, [provider]: false }));
        this.connectionStatus[provider] = {
          success: false,
          text: '✕ Failed'
        };
        this.showFeedback(err.error?.message || `Failed to connect to ${provider}.`, 'error');
      }
    });
  }


  private showFeedback(msg: string, type: 'success' | 'error'): void {
    this.feedbackMessage.set(msg);
    this.feedbackType.set(type);
    setTimeout(() => this.feedbackMessage.set(null), 5000);
  }
}
