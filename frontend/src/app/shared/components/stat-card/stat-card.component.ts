import { Component, input } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-stat-card',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="stat-card">
      <div class="stat-header">
        <span class="stat-title">{{ title() }}</span>
        @if (badgeText()) {
          <span [class]="'trend-badge ' + (isPositive() ? 'positive' : 'negative')">
            {{ isPositive() ? '↑' : '↓' }} {{ badgeText() }}
          </span>
        }
      </div>

      <div class="stat-body">
        <span class="stat-value">{{ value() }}</span>
        @if (subtext()) {
          <span class="stat-subtext">{{ subtext() }}</span>
        }
      </div>
    </div>
  `,
  styles: [`
    .stat-card {
      background-color: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 1.25rem;
      box-shadow: var(--shadow-sm);
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
      transition: all var(--transition-fast);

      &:hover {
        border-color: var(--border-strong);
        box-shadow: var(--shadow-md);
      }
    }

    .stat-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.5rem;
    }

    .stat-title {
      font-size: 0.875rem;
      font-weight: 500;
      color: var(--text-muted);
    }

    .stat-body {
      display: flex;
      flex-direction: column;
      gap: 0.25rem;
    }

    .stat-value {
      font-size: 1.75rem;
      font-weight: 700;
      color: var(--text-main);
      letter-spacing: -0.025em;
    }

    .stat-subtext {
      font-size: 0.8125rem;
      color: var(--text-subtle);
    }

    .trend-badge {
      font-size: 0.75rem;
      font-weight: 600;
      padding: 0.2rem 0.5rem;
      border-radius: var(--radius-full);
      line-height: 1;

      &.positive {
        background-color: var(--success-bg);
        color: var(--success-text);
      }

      &.negative {
        background-color: var(--danger-bg);
        color: var(--danger-text);
      }
    }
  `]
})
export class StatCardComponent {
  readonly title = input.required<string>();
  readonly value = input.required<string>();
  readonly badgeText = input<string>();
  readonly isPositive = input<boolean>(true);
  readonly subtext = input<string>();
}
