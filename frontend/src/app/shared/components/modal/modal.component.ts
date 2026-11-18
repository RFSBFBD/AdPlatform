import { Component, input, output, HostListener } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-modal',
  standalone: true,
  imports: [CommonModule],
  template: `
    @if (isOpen()) {
      <div class="modal-backdrop" (click)="onBackdropClick($event)" role="presentation">
        <div 
          class="modal-dialog" 
          role="dialog" 
          aria-modal="true" 
          [attr.aria-labelledby]="title() ? 'modal-title' : null"
        >
          <div class="modal-header">
            @if (title()) {
              <h2 id="modal-title" class="modal-title">{{ title() }}</h2>
            }
            <button 
              type="button" 
              class="close-btn" 
              (click)="close.emit()" 
              aria-label="Close modal"
            >
              ✕
            </button>
          </div>

          <div class="modal-body">
            <ng-content></ng-content>
          </div>

          <div class="modal-footer">
            <ng-content select="[footer]"></ng-content>
          </div>
        </div>
      </div>
    }
  `,
  styles: [`
    .modal-backdrop {
      position: fixed;
      inset: 0;
      background-color: rgba(0, 0, 0, 0.6);
      backdrop-filter: blur(4px);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
      z-index: 1000;
      animation: fadeIn var(--transition-fast) ease-out;
    }

    .modal-dialog {
      background-color: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      width: 100%;
      max-width: 560px;
      box-shadow: var(--shadow-lg);
      display: flex;
      flex-direction: column;
      max-height: 90vh;
      animation: scaleUp var(--transition-fast) ease-out;
    }

    .modal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 1.25rem 1.5rem;
      border-bottom: 1px solid var(--border-subtle);
    }

    .modal-title {
      font-size: 1.125rem;
      font-weight: 600;
      color: var(--text-main);
    }

    .close-btn {
      color: var(--text-muted);
      font-size: 1.125rem;
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: var(--radius-sm);
      transition: all var(--transition-fast);

      &:hover {
        background-color: var(--bg-subtle);
        color: var(--text-main);
      }
    }

    .modal-body {
      padding: 1.5rem;
      overflow-y: auto;
      color: var(--text-main);
      font-size: 0.9375rem;
      line-height: 1.6;
    }

    .modal-footer {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 0.75rem;
      padding: 1rem 1.5rem;
      border-top: 1px solid var(--border-subtle);
      background-color: var(--bg-subtle);
      border-bottom-left-radius: var(--radius-lg);
      border-bottom-right-radius: var(--radius-lg);
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    @keyframes scaleUp {
      from { transform: scale(0.96); opacity: 0; }
      to { transform: scale(1); opacity: 1; }
    }
  `]
})
export class ModalComponent {
  readonly isOpen = input<boolean>(false);
  readonly title = input<string>();
  readonly close = output<void>();

  @HostListener('document:keydown.escape')
  onEscape(): void {
    if (this.isOpen()) {
      this.close.emit();
    }
  }

  onBackdropClick(event: MouseEvent): void {
    if (event.target === event.currentTarget) {
      this.close.emit();
    }
  }
}
