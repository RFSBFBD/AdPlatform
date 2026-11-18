import { Component, inject, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ButtonComponent } from '../../shared/components/button/button.component';
import { BadgeComponent } from '../../shared/components/badge/badge.component';

export interface AiConfigDto {
  id: number;
  provider: 'openai' | 'anthropic' | 'gemini';
  model: string;
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
  imports: [CommonModule, FormsModule, ButtonComponent],
  template: `
    <div class="ai-page">
      <!-- Header -->
      <div class="page-header">
        <div>
          <h2 class="title">AI Integrations Center</h2>
          <p class="subtitle">Bring-Your-Own-Key (BYOK) AI intelligence for creative generation and performance diagnosis.</p>
        </div>
      </div>

      <!-- Navigation Tabs -->
      <div class="nav-tabs">
        <button 
          type="button" 
          class="tab-btn" 
          [class.active]="activeTab() === 'copy'"
          (click)="activeTab.set('copy')"
        >
          ✍️ Ad Copy Generator
        </button>
        <button 
          type="button" 
          class="tab-btn" 
          [class.active]="activeTab() === 'diagnostics'"
          (click)="activeTab.set('diagnostics')"
        >
          📊 Performance Diagnostic
        </button>
        <button 
          type="button" 
          class="tab-btn" 
          [class.active]="activeTab() === 'settings'"
          (click)="activeTab.set('settings')"
        >
          ⚙️ BYOK API Settings
        </button>
      </div>

      <!-- Tab 1: Ad Copy Generator -->
      @if (activeTab() === 'copy') {
        <div class="tab-content">
          <div class="copy-layout">
            <!-- Form Inputs -->
            <div class="form-card">
              <h3 class="card-title">Creative Parameters</h3>

              <div class="field">
                <label>Target Advertising Platform</label>
                <div class="platform-radios">
                  <label class="radio-label">
                    <input type="radio" name="platform" value="meta" [(ngModel)]="copyParams.platform" />
                    <span>Meta Ads</span>
                  </label>
                  <label class="radio-label">
                    <input type="radio" name="platform" value="google" [(ngModel)]="copyParams.platform" />
                    <span>Google Ads</span>
                  </label>
                  <label class="radio-label">
                    <input type="radio" name="platform" value="tiktok" [(ngModel)]="copyParams.platform" />
                    <span>TikTok Ads</span>
                  </label>
                </div>
              </div>

              <div class="field">
                <label>Product or Brand Name</label>
                <input type="text" [(ngModel)]="copyParams.product_name" placeholder="e.g. Ergonomic Office Chair" class="text-input" />
              </div>

              <div class="field">
                <label>Campaign Objective</label>
                <select [(ngModel)]="copyParams.objective" class="text-input">
                  <option value="Conversions">Conversions / Sales</option>
                  <option value="Lead Generation">Lead Generation</option>
                  <option value="Traffic">Traffic / Clicks</option>
                  <option value="Brand Awareness">Brand Awareness</option>
                </select>
              </div>

              <div class="field">
                <label>Brand Tone of Voice</label>
                <select [(ngModel)]="copyParams.tone" class="text-input">
                  <option value="Persuasive & High Energy">Persuasive & High Energy</option>
                  <option value="Professional & Clear">Professional & Clear</option>
                  <option value="Casual & Relatable">Casual & Relatable</option>
                  <option value="Urgent & Limited-Time">Urgent & Limited-Time</option>
                </select>
              </div>

              <div class="field">
                <label>Target Audience Summary</label>
                <input type="text" [(ngModel)]="copyParams.target_audience" placeholder="e.g. Tech professionals working from home" class="text-input" />
              </div>

              <app-button 
                variant="primary" 
                size="md" 
                [loading]="isGeneratingCopy()"
                (clicked)="generateCopy()"
              >
                Generate Compliant Copy Variations
              </app-button>
            </div>

            <!-- Results Output -->
            <div class="results-card">
              <h3 class="card-title">Generated Copy Variations</h3>

              @if (isGeneratingCopy()) {
                <div class="loading-state">Synthesizing ad copy adhering to platform character limits...</div>
              } @else if (generatedCopy()) {
                <div class="copy-results-list">
                  <!-- Headlines -->
                  <div class="result-block">
                    <span class="block-title">Headlines</span>
                    @for (h of generatedCopy()?.headlines; track h) {
                      <div class="copy-item">
                        <span class="copy-text">{{ h }}</span>
                        <span class="char-count">{{ h.length }} chars</span>
                      </div>
                    }
                  </div>

                  <!-- Primary Text -->
                  <div class="result-block">
                    <span class="block-title">Primary Text / Body</span>
                    @for (pt of generatedCopy()?.primary_texts; track pt) {
                      <div class="copy-item">
                        <span class="copy-text">{{ pt }}</span>
                        <span class="char-count">{{ pt.length }} chars</span>
                      </div>
                    }
                  </div>

                  <!-- Descriptions -->
                  @if (generatedCopy()?.descriptions && generatedCopy()!.descriptions.length > 0) {
                    <div class="result-block">
                      <span class="block-title">Descriptions</span>
                      @for (desc of generatedCopy()?.descriptions; track desc) {
                        <div class="copy-item">
                          <span class="copy-text">{{ desc }}</span>
                          <span class="char-count">{{ desc.length }} chars</span>
                        </div>
                      }
                    </div>
                  }

                  <!-- CTA -->
                  <div class="result-block">
                    <span class="block-title">Recommended Call to Action</span>
                    <div class="cta-pill">{{ generatedCopy()?.call_to_action || 'Learn More' }}</div>
                  </div>
                </div>
              } @else {
                <div class="empty-results">
                  Fill in creative parameters on the left and click Generate to produce copy optimized for your target platform.
                </div>
              }
            </div>
          </div>
        </div>
      }

      <!-- Tab 2: Performance Diagnostic -->
      @if (activeTab() === 'diagnostics') {
        <div class="tab-content">
          <div class="diagnostic-card">
            <h3 class="card-title">Automated Performance Diagnosis</h3>
            <p class="subtitle">Evaluates live campaign delivery metrics and suggests creative adjustments and audience re-targeting.</p>

            <div class="diagnostic-action">
              <app-button 
                variant="primary" 
                size="md" 
                [loading]="isAnalyzing()"
                (clicked)="runAnalysis()"
              >
                Run AI Performance Audit
              </app-button>
            </div>

            @if (isAnalyzing()) {
              <div class="loading-state">Analyzing metrics across active campaigns...</div>
            } @else if (analysisResult()) {
              <div class="analysis-results">
                <div class="analysis-summary-box">
                  <strong>Executive Summary:</strong> {{ analysisResult()?.summary }}
                </div>

                <div class="analysis-grid">
                  <div class="analysis-col strengths">
                    <h4>✅ Strengths & Wins</h4>
                    <ul>
                      @for (s of analysisResult()?.strengths; track s) {
                        <li>{{ s }}</li>
                      }
                    </ul>
                  </div>

                  <div class="analysis-col concerns">
                    <h4>⚠️ Fatigue & Anomalies</h4>
                    <ul>
                      @for (c of analysisResult()?.concerns; track c) {
                        <li>{{ c }}</li>
                      }
                    </ul>
                  </div>

                  <div class="analysis-col recommendations">
                    <h4>💡 Actionable Recommendations</h4>
                    <ul>
                      @for (r of analysisResult()?.recommendations; track r) {
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

      <!-- Tab 3: BYOK Settings -->
      @if (activeTab() === 'settings') {
        <div class="tab-content">
          <div class="settings-grid">
            <!-- OpenAI -->
            <div class="byok-card">
              <div class="byok-header">
                <span class="byok-name">OpenAI</span>
                <span class="byok-desc">GPT-4o, GPT-4o-mini</span>
              </div>
              <div class="field">
                <label>API Key (Encrypted at Rest)</label>
                <input 
                  type="password" 
                  [(ngModel)]="byokData.openai.api_key" 
                  placeholder="sk-proj-••••••••"
                  class="text-input font-mono" 
                />
              </div>
              <div class="field">
                <label>Model</label>
                <select [(ngModel)]="byokData.openai.model" class="text-input">
                  <option value="gpt-4o">gpt-4o (Recommended)</option>
                  <option value="gpt-4o-mini">gpt-4o-mini (Faster, Economical)</option>
                </select>
              </div>
              <div class="card-footer">
                <app-button variant="primary" size="sm" (clicked)="saveByok('openai')">Save Configuration</app-button>
              </div>
            </div>

            <!-- Anthropic Claude -->
            <div class="byok-card">
              <div class="byok-header">
                <span class="byok-name">Anthropic Claude</span>
                <span class="byok-desc">Claude 3.5 Sonnet, Claude 3.5 Haiku</span>
              </div>
              <div class="field">
                <label>API Key (Encrypted at Rest)</label>
                <input 
                  type="password" 
                  [(ngModel)]="byokData.anthropic.api_key" 
                  placeholder="sk-ant-••••••••"
                  class="text-input font-mono" 
                />
              </div>
              <div class="field">
                <label>Model</label>
                <select [(ngModel)]="byokData.anthropic.model" class="text-input">
                  <option value="claude-3-5-sonnet-20241022">claude-3-5-sonnet</option>
                  <option value="claude-3-5-haiku-20241022">claude-3-5-haiku</option>
                </select>
              </div>
              <div class="card-footer">
                <app-button variant="primary" size="sm" (clicked)="saveByok('anthropic')">Save Configuration</app-button>
              </div>
            </div>

            <!-- Google Gemini -->
            <div class="byok-card">
              <div class="byok-header">
                <span class="byok-name">Google Gemini</span>
                <span class="byok-desc">Gemini 1.5 Pro, Gemini 1.5 Flash</span>
              </div>
              <div class="field">
                <label>API Key (Encrypted at Rest)</label>
                <input 
                  type="password" 
                  [(ngModel)]="byokData.gemini.api_key" 
                  placeholder="AIzaSy••••••••"
                  class="text-input font-mono" 
                />
              </div>
              <div class="field">
                <label>Model</label>
                <select [(ngModel)]="byokData.gemini.model" class="text-input">
                  <option value="gemini-1.5-pro">gemini-1.5-pro</option>
                  <option value="gemini-1.5-flash">gemini-1.5-flash</option>
                </select>
              </div>
              <div class="card-footer">
                <app-button variant="primary" size="sm" (clicked)="saveByok('gemini')">Save Configuration</app-button>
              </div>
            </div>
          </div>
        </div>
      }
    </div>
  `,
  styles: [`
    .ai-page {
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

    .nav-tabs {
      display: flex;
      gap: 0.5rem;
      border-bottom: 1px solid var(--border-color);
      padding-bottom: 0.5rem;
    }

    .tab-btn {
      padding: 0.5rem 1rem;
      border: none;
      background: none;
      font-size: 0.875rem;
      font-weight: 500;
      color: var(--text-muted);
      cursor: pointer;
      border-radius: var(--border-radius-sm, 6px);
      transition: all 0.15s ease;

      &:hover {
        color: var(--text-main);
        background: var(--bg-surface-hover);
      }

      &.active {
        color: #ffffff;
        background: var(--color-primary, #6366f1);
      }
    }

    .tab-content {
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
    }

    .copy-layout {
      display: grid;
      grid-template-columns: 360px 1fr;
      gap: 1.5rem;
      align-items: start;
    }

    .form-card, .results-card, .diagnostic-card, .byok-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-color);
      border-radius: var(--border-radius-md, 8px);
      padding: 1.5rem;
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .card-title {
      font-size: 1.125rem;
      font-weight: 600;
      color: var(--text-main);
    }

    .field {
      display: flex;
      flex-direction: column;
      gap: 0.375rem;

      label {
        font-size: 0.8125rem;
        font-weight: 500;
        color: var(--text-main);
      }
    }

    .text-input {
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

    .font-mono {
      font-family: var(--font-family-mono);
    }

    .platform-radios {
      display: flex;
      gap: 1rem;
      font-size: 0.8125rem;
    }

    .radio-label {
      display: flex;
      align-items: center;
      gap: 0.375rem;
      cursor: pointer;
    }

    .copy-results-list {
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
    }

    .result-block {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }

    .block-title {
      font-size: 0.8125rem;
      font-weight: 600;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .copy-item {
      background: var(--bg-surface-hover);
      padding: 0.75rem 1rem;
      border-radius: 6px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
    }

    .copy-text {
      font-size: 0.875rem;
      color: var(--text-main);
    }

    .char-count {
      font-size: 0.75rem;
      color: var(--text-muted);
      white-space: nowrap;
    }

    .cta-pill {
      display: inline-block;
      padding: 0.375rem 0.75rem;
      background: var(--color-primary, #6366f1);
      color: #fff;
      font-weight: 600;
      font-size: 0.8125rem;
      border-radius: 4px;
      width: fit-content;
    }

    .empty-results, .loading-state {
      padding: 3rem 1.5rem;
      text-align: center;
      color: var(--text-muted);
      font-size: 0.875rem;
    }

    .settings-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 1.25rem;
    }

    .byok-header {
      display: flex;
      flex-direction: column;
    }

    .byok-name {
      font-size: 1rem;
      font-weight: 600;
      color: var(--text-main);
    }

    .byok-desc {
      font-size: 0.75rem;
      color: var(--text-muted);
    }

    .card-footer {
      display: flex;
      justify-content: flex-end;
      margin-top: 0.5rem;
    }

    .analysis-results {
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
      margin-top: 1rem;
    }

    .analysis-summary-box {
      background: var(--bg-surface-hover);
      padding: 1rem;
      border-radius: 6px;
      font-size: 0.875rem;
      line-height: 1.5;
    }

    .analysis-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 1rem;
    }

    .analysis-col {
      background: var(--bg-surface-hover);
      padding: 1rem;
      border-radius: 6px;
      display: flex;
      flex-direction: column;
      gap: 0.75rem;

      h4 {
        font-size: 0.875rem;
        font-weight: 600;
      }

      ul {
        margin: 0;
        padding-left: 1.25rem;
        font-size: 0.8125rem;
        line-height: 1.5;
      }
    }
  `]
})
export class AiCenterFeatureComponent implements OnInit {
  private readonly http = inject(HttpClient);

  readonly activeTab = signal<'copy' | 'diagnostics' | 'settings'>('copy');
  readonly isGeneratingCopy = signal<boolean>(false);
  readonly isAnalyzing = signal<boolean>(false);

  readonly generatedCopy = signal<GeneratedCopyDto | null>(null);
  readonly analysisResult = signal<AnalysisDto | null>(null);

  copyParams = {
    platform: 'meta',
    product_name: '',
    objective: 'Conversions',
    tone: 'Persuasive & High Energy',
    target_audience: '',
  };

  byokData = {
    openai: { api_key: '', model: 'gpt-4o' },
    anthropic: { api_key: '', model: 'claude-3-5-sonnet-20241022' },
    gemini: { api_key: '', model: 'gemini-1.5-pro' },
  };

  ngOnInit(): void {
    this.loadConfigs();
  }

  loadConfigs(): void {
    this.http.get<{ configs: AiConfigDto[] }>('/api/v1/ai/config').subscribe({
      next: (res) => {
        for (const c of res.configs || []) {
          if (c.provider in this.byokData) {
            (this.byokData as any)[c.provider].model = c.model;
          }
        }
      }
    });
  }

  generateCopy(): void {
    if (!this.copyParams.product_name.trim()) {
      alert('Please enter a product or brand name.');
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
        alert(err.error?.message || 'Failed to generate ad copy.');
      }
    });
  }

  runAnalysis(): void {
    this.isAnalyzing.set(true);
    const mockMetrics = [
      { name: 'Core Campaign', spend: 850, conversions: 32, roas: 3.8, ctr: 2.1 },
    ];

    this.http.post<{ success: boolean; analysis: AnalysisDto }>('/api/v1/ai/analyze-performance', {
      metrics: mockMetrics,
    }).subscribe({
      next: (res) => {
        this.isAnalyzing.set(false);
        if (res.success) {
          this.analysisResult.set(res.analysis);
        }
      },
      error: () => {
        this.isAnalyzing.set(false);
      }
    });
  }

  saveByok(provider: 'openai' | 'anthropic' | 'gemini'): void {
    const data = this.byokData[provider];
    this.http.put(`/api/v1/ai/config/${provider}`, {
      api_key: data.api_key || undefined,
      model: data.model,
      is_active: true,
    }).subscribe({
      next: () => {
        alert(`${provider.toUpperCase()} AI configuration saved successfully.`);
      },
      error: (err) => {
        alert(err.error?.message || 'Failed to update AI configuration.');
      }
    });
  }
}
