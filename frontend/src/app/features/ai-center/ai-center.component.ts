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

export interface GeneratedCopyDto {
  platform: string;
  headlines: string[];
  primary_texts: string[];
  descriptions: string[];
  call_to_action: string;
}

export interface AnalysisDto {
  summary: string;
  strengths: string[];
  concerns: string[];
  recommendations: string[];
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
          <h2 class="title">AI Intelligence & Model Hub</h2>
          <p class="subtitle">Unified Bring-Your-Own-Key (BYOK) & Custom API model integrations for creative copy and automated diagnostics.</p>
        </div>
      </div>

      <!-- Navigation Tabs -->
      <div class="nav-tabs">
        <button 
          type="button" 
          class="tab-btn" 
          [class.active]="activeTab() === 'settings'"
          (click)="activeTab.set('settings')"
        >
          <app-icon name="settings" [size]="16"></app-icon>
          <span>Connected AI APIs & Models</span>
        </button>
        <button 
          type="button" 
          class="tab-btn" 
          [class.active]="activeTab() === 'copy'"
          (click)="activeTab.set('copy')"
        >
          <app-icon name="ai" [size]="16"></app-icon>
          <span>Ad Copy Generator</span>
        </button>
        <button 
          type="button" 
          class="tab-btn" 
          [class.active]="activeTab() === 'diagnostics'"
          (click)="activeTab.set('diagnostics')"
        >
          <app-icon name="dashboard" [size]="16"></app-icon>
          <span>Performance Diagnostics</span>
        </button>
      </div>

      <!-- Feedback Banner -->
      @if (feedbackMessage()) {
        <div [class]="'feedback-banner ' + feedbackType()">
          <span>{{ feedbackMessage() }}</span>
        </div>
      }

      <!-- Tab 1: Ad Copy Generator -->
      @if (activeTab() === 'copy') {
        <div class="tab-content">
          <div class="copy-layout">
            <div class="copy-form-panel">
              <h3 class="panel-title">Creative Parameters</h3>
              
              <div class="field">
                <label class="form-label">Target Network</label>
                <div class="network-chips">
                  <button 
                    type="button" 
                    [class.active]="copyParams.platform === 'meta'" 
                    (click)="copyParams.platform = 'meta'"
                    class="chip"
                  >
                    <app-icon name="meta" [size]="14"></app-icon>
                    <span>Meta Ads</span>
                  </button>
                  <button 
                    type="button" 
                    [class.active]="copyParams.platform === 'google'" 
                    (click)="copyParams.platform = 'google'"
                    class="chip"
                  >
                    <app-icon name="google" [size]="14"></app-icon>
                    <span>Google Ads</span>
                  </button>
                  <button 
                    type="button" 
                    [class.active]="copyParams.platform === 'tiktok'" 
                    (click)="copyParams.platform = 'tiktok'"
                    class="chip"
                  >
                    <app-icon name="tiktok" [size]="14"></app-icon>
                    <span>TikTok Ads</span>
                  </button>
                </div>
              </div>

              <div class="field">
                <label class="form-label">Product or Brand Name *</label>
                <input 
                  type="text" 
                  [(ngModel)]="copyParams.product_name" 
                  placeholder="e.g. EcoGlow Smart Lamp" 
                  class="form-input" 
                />
              </div>

              <div class="field">
                <label class="form-label">Campaign Objective</label>
                <select [(ngModel)]="copyParams.objective" class="form-input">
                  <option value="Conversions">Conversions / Purchases</option>
                  <option value="Lead Generation">Lead Generation</option>
                  <option value="Traffic">Website Traffic</option>
                  <option value="Brand Awareness">Brand Awareness</option>
                </select>
              </div>

              <div class="field">
                <label class="form-label">Copy Tone</label>
                <select [(ngModel)]="copyParams.tone" class="form-input">
                  <option value="Persuasive & High Energy">Persuasive & High Energy</option>
                  <option value="Professional & Analytical">Professional & Analytical</option>
                  <option value="Relatable & Casual">Relatable & Casual (UGC-Style)</option>
                  <option value="Urgent & Direct">Urgent & Direct (FOMO / Offer)</option>
                </select>
              </div>

              <div class="field">
                <label class="form-label">Target Audience / Value Proposition</label>
                <textarea 
                  [(ngModel)]="copyParams.target_audience" 
                  placeholder="e.g. Remote workers, eco-conscious homeowners who need soft circadian lighting." 
                  rows="3" 
                  class="form-input"
                ></textarea>
              </div>

              <app-button 
                variant="primary" 
                [loading]="isGeneratingCopy()" 
                (clicked)="generateCopy()"
                class="w-full"
              >
                <app-icon name="sparkles" [size]="16"></app-icon>
                <span>Generate Ad Copy Variations</span>
              </app-button>
            </div>

            <!-- Copy Results Panel -->
            <div class="copy-results-panel">
              @if (isGeneratingCopy()) {
                <div class="loading-state">
                  <span class="spinner"></span>
                  <span>Synthesizing high-converting copy with configured AI model...</span>
                </div>
              } @else if (generatedCopy()) {
                <div class="results-container">
                  <div class="results-header">
                    <h4>Generated Variations for {{ generatedCopy()!.platform | uppercase }}</h4>
                    <span class="badge-cta">CTA: {{ generatedCopy()!.call_to_action }}</span>
                  </div>

                  <div class="variation-section">
                    <h5>Headlines</h5>
                    <div class="variation-list">
                      @for (hl of generatedCopy()!.headlines; track hl) {
                        <div class="variation-item">{{ hl }}</div>
                      }
                    </div>
                  </div>

                  <div class="variation-section">
                    <h5>Primary Texts / Body Copy</h5>
                    <div class="variation-list">
                      @for (pt of generatedCopy()!.primary_texts; track pt) {
                        <div class="variation-item">{{ pt }}</div>
                      }
                    </div>
                  </div>

                  <div class="variation-section">
                    <h5>Short Descriptions</h5>
                    <div class="variation-list">
                      @for (desc of generatedCopy()!.descriptions; track desc) {
                        <div class="variation-item">{{ desc }}</div>
                      }
                    </div>
                  </div>
                </div>
              } @else {
                <div class="empty-panel">
                  <app-icon name="ai" [size]="40"></app-icon>
                  <p>Configure your parameters on the left and click Generate to produce ad copy.</p>
                </div>
              }
            </div>
          </div>
        </div>
      }

      <!-- Tab 2: Performance Diagnostics -->
      @if (activeTab() === 'diagnostics') {
        <div class="tab-content">
          <div class="diagnostics-panel">
            <div class="panel-header">
              <div>
                <h3 class="panel-title">Automated Performance Diagnostics</h3>
                <p class="panel-desc">Evaluate current campaign metrics to uncover budget fatigue and scale opportunities.</p>
              </div>
              <app-button 
                variant="primary" 
                [loading]="isAnalyzing()" 
                (clicked)="runAnalysis()"
              >
                <app-icon name="dashboard" [size]="16"></app-icon>
                <span>Run Diagnostic Analysis</span>
              </app-button>
            </div>

            @if (isAnalyzing()) {
              <div class="loading-state">
                <span class="spinner"></span>
                <span>Auditing active performance parameters...</span>
              </div>
            } @else if (analysisResult()) {
              <div class="analysis-results">
                <div class="summary-box">
                  <h4>Executive Summary</h4>
                  <p>{{ analysisResult()!.summary }}</p>
                </div>

                <div class="pillars-grid">
                  <div class="pillar-card strengths">
                    <h4>Observed Strengths</h4>
                    <ul>
                      @for (s of analysisResult()!.strengths; track s) {
                        <li>{{ s }}</li>
                      }
                    </ul>
                  </div>

                  <div class="pillar-card concerns">
                    <h4>Areas of Concern</h4>
                    <ul>
                      @for (c of analysisResult()!.concerns; track c) {
                        <li>{{ c }}</li>
                      }
                    </ul>
                  </div>

                  <div class="pillar-card recommendations">
                    <h4>Actionable Recommendations</h4>
                    <ul>
                      @for (r of analysisResult()!.recommendations; track r) {
                        <li>{{ r }}</li>
                      }
                    </ul>
                  </div>
                </div>
              </div>
            }
          </div>
        </div>
      }

      <!-- Tab 3: Connected AI APIs (Clean Single Button + Modal) -->
      @if (activeTab() === 'settings') {
        <div class="tab-content">
          <div class="ai-apis-header">
            <div>
              <h3 class="section-title">Connected AI APIs</h3>
              <p class="section-subtitle">Connect any model (DeepSeek, Groq, OpenAI, Claude, Gemini, Ollama) through a single API key.</p>
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
              <p>Add your custom API key (DeepSeek, Groq, OpenAI, Claude, Ollama, etc.) to power ad creative generation and diagnostics.</p>
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

    /* Tab 1 Copy Layout */
    .copy-layout {
      display: grid;
      grid-template-columns: 380px 1fr;
      gap: 1.5rem;
    }

    @media (max-width: 900px) {
      .copy-layout {
        grid-template-columns: 1fr;
      }
    }

    .copy-form-panel, .copy-results-panel, .diagnostics-panel {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-md);
      padding: 1.5rem;
    }

    .panel-title {
      font-size: 1.125rem;
      font-weight: 700;
      color: var(--text-main);
      margin-bottom: 1.25rem;
    }

    .field {
      display: flex;
      flex-direction: column;
      margin-bottom: 1.25rem;
    }

    .network-chips {
      display: flex;
      gap: 0.5rem;
    }

    .network-chips .chip {
      flex: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.4rem;
      padding: 0.5rem;
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-sm);
      background: var(--bg-subtle);
      color: var(--text-muted);
      font-size: 0.8125rem;
      font-weight: 600;
      transition: all var(--transition-fast);

      &.active {
        border-color: var(--primary);
        background: var(--primary-light);
        color: var(--text-main);
      }
    }

    .w-full {
      width: 100%;
    }

    .loading-state {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.75rem;
      padding: 3rem;
      color: var(--text-muted);
      font-size: 0.875rem;
    }

    .spinner {
      width: 18px;
      height: 18px;
      border: 2px solid rgba(255, 255, 255, 0.2);
      border-top-color: var(--primary);
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }

    .results-container {
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
    }

    .results-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid var(--border-subtle);
      padding-bottom: 0.75rem;
    }

    .badge-cta {
      font-size: 0.75rem;
      background: var(--primary-light);
      color: var(--primary);
      padding: 0.25rem 0.6rem;
      border-radius: 9999px;
      font-weight: 600;
    }

    .variation-section h5 {
      font-size: 0.8125rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-muted);
      margin-bottom: 0.5rem;
    }

    .variation-list {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }

    .variation-item {
      background: var(--bg-subtle);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-sm);
      padding: 0.75rem 1rem;
      font-size: 0.875rem;
      color: var(--text-main);
      line-height: 1.45;
    }

    .empty-panel {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 4rem 2rem;
      color: var(--text-muted);
      gap: 0.75rem;
    }

    /* Tab 2 Diagnostics */
    .diagnostics-panel .panel-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 1.5rem;
      gap: 1rem;
      flex-wrap: wrap;
    }

    .panel-desc {
      font-size: 0.875rem;
      color: var(--text-muted);
      margin-top: 0.25rem;
    }

    .analysis-results {
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
    }

    .summary-box {
      background: var(--bg-subtle);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-md);
      padding: 1.25rem;
    }

    .summary-box h4 {
      font-size: 0.9375rem;
      font-weight: 700;
      margin-bottom: 0.35rem;
    }

    .pillars-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 1.25rem;
    }

    .pillar-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-md);
      padding: 1.25rem;
    }

    .pillar-card h4 {
      font-size: 0.875rem;
      font-weight: 700;
      margin-bottom: 0.75rem;
    }

    .pillar-card.strengths h4 { color: var(--success); }
    .pillar-card.concerns h4 { color: var(--warning); }
    .pillar-card.recommendations h4 { color: var(--primary); }

    .pillar-card ul {
      padding-left: 1.25rem;
      font-size: 0.8125rem;
      display: flex;
      flex-direction: column;
      gap: 0.4rem;
      color: var(--text-muted);
    }

    /* Tab 3 Clean AI APIs View */
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

  readonly activeTab = signal<'copy' | 'diagnostics' | 'settings'>('settings');
  readonly isGeneratingCopy = signal<boolean>(false);
  readonly isAnalyzing = signal<boolean>(false);

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

  readonly generatedCopy = signal<GeneratedCopyDto | null>(null);
  readonly analysisResult = signal<AnalysisDto | null>(null);

  copyParams = {
    platform: 'meta',
    product_name: '',
    objective: 'Conversions',
    tone: 'Persuasive & High Energy',
    target_audience: '',
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

  generateCopy(): void {
    if (!this.copyParams.product_name.trim()) {
      this.showFeedback('Please enter a product or brand name.', 'error');
      return;
    }

    this.isGeneratingCopy.set(true);
    this.http.post<{ success: boolean; copy: GeneratedCopyDto }>('/api/v1/ai/generate-copy', this.copyParams).subscribe({
      next: (res) => {
        this.isGeneratingCopy.set(false);
        if (res.success) {
          this.generatedCopy.set(res.copy);
        }
      },
      error: (err) => {
        this.isGeneratingCopy.set(false);
        this.showFeedback(err.error?.message || 'Failed to generate ad copy.', 'error');
      }
    });
  }

  runAnalysis(): void {
    this.isAnalyzing.set(true);
    // The server builds the metrics from the user's own synced data; the client sends no numbers.
    this.http.post<{ success: boolean; analysis: AnalysisDto }>('/api/v1/ai/analyze-performance', {}).subscribe({
      next: (res) => {
        this.isAnalyzing.set(false);
        if (res.success) {
          this.analysisResult.set(res.analysis);
        }
      },
      error: (err) => {
        this.isAnalyzing.set(false);
        this.showFeedback(err.error?.error?.message || 'Analysis is unavailable right now.', 'error');
      }
    });
  }

  private showFeedback(msg: string, type: 'success' | 'error'): void {
    this.feedbackMessage.set(msg);
    this.feedbackType.set(type);
    setTimeout(() => this.feedbackMessage.set(null), 5000);
  }
}
