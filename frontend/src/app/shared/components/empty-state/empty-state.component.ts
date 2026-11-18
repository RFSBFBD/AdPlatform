import { Component, input, output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ButtonComponent } from '../button/button.component';

@Component({
  selector: 'app-empty-state',
  standalone: true,
  imports: [CommonModule, ButtonComponent],
  template: `
    <div class="empty-state">
      <div class="empty-icon-wrap" aria-hidden="true">
        <ng-content select="[icon]"></ng-content>
      </div>

      <h3 class="empty-title">{{ title() }}</h3>
      <p class="empty-description">{{ description() }}</p>

      @if (actionLabel()) {
        <div class="empty-action">
          <app-button (clicked)="actionClicked.emit()">
            {{ actionLabel() }}
          </app-button>
        </div>
      }
    </div>
  `,
  styles: [`
    .empty-state {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 3.5rem 1.5rem;
      background-color: var(--bg-surface);
      border: 1px dashed var(--border-strong);
      border-radius: var(--radius-lg);
      max-width: 520px;
      margin: 2rem auto;
    }

    .empty-icon-wrap {
      width: 54px;
      height: 54px;
      border-radius: 50%;
      background-color: var(--bg-subtle);
      color: var(--text-muted);
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 1.25rem;
    }

    .empty-title {
      font-size: 1.125rem;
      font-weight: 600;
      color: var(--text-main);
      margin-bottom: 0.5rem;
    }

    .empty-description {
      font-size: 0.875rem;
      color: var(--text-muted);
      line-height: 1.5;
      margin-bottom: 1.5rem;
      max-width: 380px;
    }

    .empty-action {
      display: flex;
      gap: 0.75rem;
    }
  `]
})
export class EmptyStateComponent {
  readonly title = input.required<string>();
  readonly description = input.required<string>();
  readonly actionLabel = input<string>();
  readonly actionClicked = output<void>();
}
