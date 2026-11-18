import { Component, input } from '@angular/core';
import { CommonModule } from '@angular/common';

export type BadgeVariant = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

@Component({
  selector: 'app-badge',
  standalone: true,
  imports: [CommonModule],
  template: `
    <span [class]="'badge badge-' + variant()">
      <span class="badge-dot" aria-hidden="true"></span>
      <ng-content></ng-content>
    </span>
  `,
  styles: [`
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 0.375rem;
      padding: 0.2rem 0.625rem;
      border-radius: var(--radius-full);
      font-size: 0.75rem;
      font-weight: 500;
      line-height: 1;
      white-space: nowrap;
    }

    .badge-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background-color: currentColor;
    }

    .badge-success {
      background-color: var(--success-bg);
      color: var(--success-text);
    }

    .badge-warning {
      background-color: var(--warning-bg);
      color: var(--warning-text);
    }

    .badge-danger {
      background-color: var(--danger-bg);
      color: var(--danger-text);
    }

    .badge-info {
      background-color: var(--info-bg);
      color: var(--info-text);
    }

    .badge-neutral {
      background-color: var(--bg-subtle);
      color: var(--text-muted);
    }
  `]
})
export class BadgeComponent {
  readonly variant = input<BadgeVariant>('neutral');
}
