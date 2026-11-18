import { Component, input, output } from '@angular/core';
import { CommonModule } from '@angular/common';

export type ButtonVariant = 'primary' | 'secondary' | 'outline' | 'danger' | 'ghost';
export type ButtonSize = 'sm' | 'md' | 'lg';

@Component({
  selector: 'app-button',
  standalone: true,
  imports: [CommonModule],
  template: `
    <button
      [type]="type()"
      [disabled]="disabled() || loading()"
      [class]="buttonClasses()"
      (click)="onClick($event)"
      [attr.aria-busy]="loading()"
    >
      @if (loading()) {
        <span class="spinner" aria-hidden="true"></span>
      }
      <ng-content></ng-content>
    </button>
  `,
  styles: [`
    button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      font-weight: 500;
      border-radius: var(--radius-md);
      transition: all var(--transition-fast);
      white-space: nowrap;
      user-select: none;
      border: 1px solid transparent;

      &:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
      }

      &:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }
    }

    /* Sizes */
    .btn-sm {
      padding: 0.375rem 0.75rem;
      font-size: 0.8125rem;
      height: 32px;
    }

    .btn-md {
      padding: 0.5rem 1rem;
      font-size: 0.875rem;
      height: 40px;
    }

    .btn-lg {
      padding: 0.625rem 1.25rem;
      font-size: 1rem;
      height: 48px;
    }

    /* Variants */
    .btn-primary {
      background-color: var(--primary);
      color: var(--primary-text);
      &:hover:not(:disabled) {
        background-color: var(--primary-hover);
        box-shadow: var(--shadow-sm);
      }
    }

    .btn-secondary {
      background-color: var(--bg-subtle);
      color: var(--text-main);
      border-color: var(--border-subtle);
      &:hover:not(:disabled) {
        background-color: var(--bg-hover);
      }
    }

    .btn-outline {
      background-color: transparent;
      color: var(--text-main);
      border-color: var(--border-strong);
      &:hover:not(:disabled) {
        background-color: var(--bg-subtle);
      }
    }

    .btn-danger {
      background-color: var(--danger);
      color: #ffffff;
      &:hover:not(:disabled) {
        background-color: #dc2626;
      }
    }

    .btn-ghost {
      background-color: transparent;
      color: var(--text-muted);
      &:hover:not(:disabled) {
        background-color: var(--bg-subtle);
        color: var(--text-main);
      }
    }

    .spinner {
      width: 14px;
      height: 14px;
      border: 2px solid currentColor;
      border-right-color: transparent;
      border-radius: 50%;
      animation: spin 0.6s linear infinite;
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }
  `]
})
export class ButtonComponent {
  readonly variant = input<ButtonVariant>('primary');
  readonly size = input<ButtonSize>('md');
  readonly type = input<'button' | 'submit' | 'reset'>('button');
  readonly disabled = input<boolean>(false);
  readonly loading = input<boolean>(false);
  readonly fullWidth = input<boolean>(false);

  readonly clicked = output<MouseEvent>();

  protected buttonClasses(): string {
    return [
      `btn-${this.variant()}`,
      `btn-${this.size()}`,
      this.fullWidth() ? 'w-full' : ''
    ].filter(Boolean).join(' ');
  }

  protected onClick(event: MouseEvent): void {
    if (!this.disabled() && !this.loading()) {
      this.clicked.emit(event);
    }
  }
}
