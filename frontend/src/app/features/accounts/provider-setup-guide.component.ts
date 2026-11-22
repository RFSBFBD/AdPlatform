import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { PROVIDER_GUIDES, ProviderGuide } from './provider-guides';

/**
 * Renders the official activation guide for one ad provider from the shared
 * PROVIDER_GUIDES data. Used on Connected Accounts and inside each Developer
 * API Hub credential card, so the steps/links live in exactly one place.
 */
@Component({
  selector: 'app-provider-setup-guide',
  standalone: true,
  imports: [CommonModule],
  template: `
    @if (guide; as g) {
      <details class="setup-guide" [attr.data-guide]="g.id">
        <summary>How to connect {{ g.id === 'tiktok' ? 'TikTok' : (g.id | titlecase) }} (official steps)</summary>
        <p class="guide-state">{{ g.built ? 'Connection code exists in this platform (not yet run against a live provider).' : 'Not built in this platform yet. This only prepares your account.' }}</p>
        <p><a [href]="g.portalUrl" target="_blank" rel="noopener noreferrer">Open: {{ g.portalLabel }}</a></p>
        <ol>@for (st of g.steps; track st) { <li>{{ st }}</li> }</ol>
        <strong>You need</strong>
        <ul>@for (n of g.needs; track n) { <li>{{ n }}</li> }</ul>
        <strong>Limits</strong>
        <ul>@for (l of g.limits; track l) { <li>{{ l }}</li> }</ul>
        <div class="guide-links">@for (d of g.docs; track d.url) { <a [href]="d.url" target="_blank" rel="noopener noreferrer">{{ d.label }}</a> }</div>
      </details>
    }
  `,
  styles: [`
    .setup-guide { margin-top: 0.75rem; font-size: 0.8125rem; color: var(--text-muted, #64748b); }
    .setup-guide summary { cursor: pointer; font-weight: 600; color: var(--text-main); }
    .setup-guide ol, .setup-guide ul { margin: 0.375rem 0 0.5rem 1.125rem; padding: 0; }
    .setup-guide li { margin-bottom: 0.25rem; }
    .setup-guide a { color: #6366f1; text-decoration: underline; }
    .guide-links { display: flex; flex-direction: column; gap: 0.25rem; margin-top: 0.375rem; }
    .guide-state { font-style: italic; }
  `]
})
export class ProviderSetupGuideComponent {
  @Input({ required: true }) providerId!: string;

  get guide(): ProviderGuide | undefined {
    return PROVIDER_GUIDES[this.providerId as ProviderGuide['id']];
  }
}
