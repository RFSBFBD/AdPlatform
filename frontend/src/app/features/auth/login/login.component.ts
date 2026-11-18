import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from '../../../core/services/auth.service';
import { ButtonComponent } from '../../../shared/components/button/button.component';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, ButtonComponent],
  template: `
    <div class="login-wrapper">
      <div class="login-card">
        <div class="login-header">
          <div class="brand-badge">AdPlatform</div>
          <h2 class="title">Sign in to your account</h2>
          <p class="subtitle">Enter your official credentials to access the platform.</p>
        </div>

        @if (errorMessage()) {
          <div class="alert-error" role="alert">
            <span class="alert-icon">⚠️</span>
            <span>{{ errorMessage() }}</span>
          </div>
        }

        <form [formGroup]="loginForm" (ngSubmit)="onSubmit()" class="login-form">
          <div class="form-group">
            <label for="email" class="form-label">Email Address</label>
            <input
              id="email"
              type="email"
              formControlName="email"
              class="form-input"
              placeholder="name@company.com"
              autocomplete="email"
              [class.invalid]="isFieldInvalid('email')"
            />
            @if (isFieldInvalid('email')) {
              <span class="field-error">Please enter a valid email address.</span>
            }
          </div>

          <div class="form-group">
            <div class="label-row">
              <label for="password" class="form-label">Password</label>
            </div>
            <input
              id="password"
              type="password"
              formControlName="password"
              class="form-input"
              placeholder="••••••••••••"
              autocomplete="current-password"
              [class.invalid]="isFieldInvalid('password')"
            />
            @if (isFieldInvalid('password')) {
              <span class="field-error">Password is required.</span>
            }
          </div>

          <div class="form-actions">
            <app-button
              type="submit"
              variant="primary"
              [fullWidth]="true"
              [loading]="isLoading()"
              [disabled]="loginForm.invalid"
            >
              Sign In
            </app-button>
          </div>
        </form>

        <div class="login-footer">
          <span class="security-notice">🔒 Secured with first-party Laravel Sanctum tokens</span>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .login-wrapper {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
      background-color: var(--bg-app);
    }

    .login-card {
      background-color: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 2.5rem 2rem;
      width: 100%;
      max-width: 440px;
      box-shadow: var(--shadow-lg);
    }

    .login-header {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      margin-bottom: 2rem;
    }

    .brand-badge {
      font-weight: 700;
      font-size: 0.8125rem;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      padding: 0.25rem 0.75rem;
      background-color: var(--primary-light);
      color: var(--primary);
      border-radius: var(--radius-full);
      margin-bottom: 1rem;
    }

    .title {
      font-size: 1.5rem;
      font-weight: 700;
      color: var(--text-main);
      letter-spacing: -0.02em;
    }

    .subtitle {
      font-size: 0.875rem;
      color: var(--text-muted);
      margin-top: 0.375rem;
    }

    .alert-error {
      background-color: var(--danger-bg);
      border: 1px solid var(--danger);
      color: var(--danger-text);
      padding: 0.75rem 1rem;
      border-radius: var(--radius-md);
      font-size: 0.875rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
      margin-bottom: 1.25rem;
    }

    .login-form {
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 0.375rem;
    }

    .form-label {
      font-size: 0.875rem;
      font-weight: 500;
      color: var(--text-main);
    }

    .form-input {
      padding: 0.625rem 0.875rem;
      border: 1px solid var(--border-strong);
      border-radius: var(--radius-md);
      background-color: var(--bg-app);
      color: var(--text-main);
      font-size: 0.9375rem;
      transition: all var(--transition-fast);

      &:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px var(--primary-ring);
      }

      &.invalid {
        border-color: var(--danger);
      }
    }

    .field-error {
      font-size: 0.75rem;
      color: var(--danger-text);
    }

    .form-actions {
      margin-top: 0.5rem;
    }

    .login-footer {
      margin-top: 2rem;
      text-align: center;
      border-top: 1px solid var(--border-subtle);
      padding-top: 1.25rem;
    }

    .security-notice {
      font-size: 0.75rem;
      color: var(--text-subtle);
    }
  `]
})
export class LoginComponent {
  private readonly fb = inject(FormBuilder);
  private readonly authService = inject(AuthService);
  private readonly router = inject(Router);

  readonly isLoading = signal<boolean>(false);
  readonly errorMessage = signal<string | null>(null);

  readonly loginForm = this.fb.nonNullable.group({
    email: ['admin@adplatform.local', [Validators.required, Validators.email]],
    password: ['AdminSecurePassword2026!', [Validators.required, Validators.minLength(6)]],
  });

  isFieldInvalid(fieldName: 'email' | 'password'): boolean {
    const field = this.loginForm.get(fieldName);
    return !!field && field.invalid && (field.dirty || field.touched);
  }

  onSubmit(): void {
    if (this.loginForm.invalid) {
      this.loginForm.markAllAsTouched();
      return;
    }

    this.isLoading.set(true);
    this.errorMessage.set(null);

    this.authService.login(this.loginForm.getRawValue()).subscribe({
      next: () => {
        this.isLoading.set(false);
        this.router.navigate(['/dashboard']);
      },
      error: (err) => {
        this.isLoading.set(false);
        const msg = err.error?.error?.message || 'Login failed. Please check your credentials.';
        this.errorMessage.set(msg);
      }
    });
  }
}
