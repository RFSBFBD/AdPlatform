import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from '../../../core/services/auth.service';
import { ButtonComponent } from '../../../shared/components/button/button.component';
import { BrandLogoComponent } from '../../../shared/components/brand-logo/brand-logo.component';
import { IconComponent } from '../../../shared/components/icon/icon.component';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, ButtonComponent, BrandLogoComponent, IconComponent],
  template: `
    <div class="login-wrapper">
      <div class="login-card">
        <div class="login-header">
          <app-brand-logo [size]="52"></app-brand-logo>
          <h2 class="title">Sign in to AdPlatform</h2>
          <p class="subtitle">Unified cross-channel advertising analytics and AI intelligence.</p>
        </div>

        @if (errorMessage()) {
          <div class="alert-error" role="alert">
            <app-icon name="alert" [size]="16"></app-icon>
            <span>{{ errorMessage() }}</span>
          </div>
        }

        <form [formGroup]="loginForm" (ngSubmit)="onSubmit()" class="login-form">
          <div class="form-group">
            <label for="email" class="form-label">Work Email</label>
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
            <div class="password-wrapper">
              <input
                id="password"
                [type]="showPassword() ? 'text' : 'password'"
                formControlName="password"
                class="form-input password-input"
                placeholder="••••••••••••"
                autocomplete="current-password"
                [class.invalid]="isFieldInvalid('password')"
              />
              <button 
                type="button" 
                class="pwd-toggle" 
                (click)="showPassword.update(v => !v)"
                title="Toggle password visibility"
              >
                <app-icon [name]="showPassword() ? 'eye-off' : 'eye'" [size]="16"></app-icon>
              </button>
            </div>
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
              <span>Sign In to Dashboard</span>
            </app-button>
          </div>
        </form>

        <div class="login-footer">
          <span class="security-notice">
            <app-icon name="key" [size]="13"></app-icon>
            <span>Secured with encrypted first-party tokens</span>
          </span>
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
      border-radius: var(--radius-xl);
      padding: 2.5rem 2.25rem;
      width: 100%;
      max-width: 440px;
      box-shadow: var(--shadow-lg);
    }

    .login-header {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      gap: 0.85rem;
      margin-bottom: 2rem;
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
      line-height: 1.4;
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

    .password-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }

    .password-input {
      padding-right: 2.5rem;
    }

    .pwd-toggle {
      position: absolute;
      right: 0.75rem;
      color: var(--text-muted);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0.25rem;
      cursor: pointer;
    }

    .pwd-toggle:hover {
      color: var(--text-main);
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
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
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
  readonly showPassword = signal<boolean>(false);

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
