import { Component, inject, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ButtonComponent } from '../../shared/components/button/button.component';
import { BadgeComponent, BadgeVariant } from '../../shared/components/badge/badge.component';
import { ModalComponent } from '../../shared/components/modal/modal.component';
import { IconComponent } from '../../shared/components/icon/icon.component';
import { AuthService } from '../../core/services/auth.service';

export interface UserRoleDto {
  id: number;
  name: string;
}

export interface UserDto {
  id: number;
  name: string;
  email: string;
  status: string;
  roles: UserRoleDto[];
  created_at: string;
}

@Component({
  selector: 'app-team-feature',
  standalone: true,
  imports: [CommonModule, FormsModule, ButtonComponent, BadgeComponent, ModalComponent, IconComponent],
  template: `
    <div class="team-page">
      <div class="page-header">
        <div>
          <h2 class="title">Team & Access Management</h2>
          <p class="subtitle">
            Manage authenticated team members, login credentials, and role-based permissions ({{ users().length }} member{{ users().length === 1 ? '' : 's' }}).
          </p>
        </div>
        <app-button variant="primary" size="sm" (clicked)="openCreateModal()">
          <app-icon name="plus" [size]="16"></app-icon>
          <span>Create Team Member</span>
        </app-button>
      </div>

      @if (feedbackMessage()) {
        <div [class]="'feedback-banner ' + feedbackType()">
          <span>{{ feedbackMessage() }}</span>
        </div>
      }

      <div class="team-table-card">
        @if (loading()) {
          <div class="loading-state">
            <span class="spinner"></span>
            <span>Loading team members...</span>
          </div>
        } @else if (users().length === 0) {
          <div class="empty-state">
            <p>No team members found.</p>
          </div>
        } @else {
          <table class="team-table">
            <thead>
              <tr>
                <th>User</th>
                <th>Role</th>
                <th>Status</th>
                <th>Joined Date</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @for (user of users(); track user.id) {
                <tr>
                  <td>
                    <div class="user-cell">
                      <div class="avatar">{{ getInitials(user.name) }}</div>
                      <div class="info">
                        <span class="name">{{ user.name }}</span>
                        <span class="email">{{ user.email }}</span>
                      </div>
                    </div>
                  </td>
                  <td>
                    <app-badge [variant]="getRoleBadgeVariant(getUserRole(user))">
                      {{ getUserRole(user) }}
                    </app-badge>
                  </td>
                  <td>
                    <app-badge [variant]="user.status === 'active' ? 'success' : 'neutral'">
                      {{ user.status || 'Active' }}
                    </app-badge>
                  </td>
                  <td>{{ formatDate(user.created_at) }}</td>
                  <td>
                    @if (canManage(user)) {
                      <div class="row-actions">
                        <select
                          class="form-input role-select"
                          [attr.aria-label]="'Change role for ' + user.name"
                          [ngModel]="getUserRole(user)"
                          [disabled]="busyUserId() === user.id"
                          (ngModelChange)="changeRole(user, $event)"
                        >
                          @for (r of assignableRoles(); track r) {
                            <option [value]="r">{{ r }}</option>
                          }
                        </select>
                        <app-button variant="ghost" size="sm" (clicked)="askRemove(user)">Remove</app-button>
                      </div>
                    } @else {
                      <span class="muted">{{ isSelf(user) ? 'You' : 'Owner only' }}</span>
                    }
                  </td>
                </tr>
              }
            </tbody>
          </table>
        }
      </div>

      <!-- Direct User Creation Modal -->
      <app-modal 
        [isOpen]="showCreateModal()" 
        title="Create Team Member & Access"
        (close)="showCreateModal.set(false)"
      >
        <div class="create-form">
          <div class="form-group">
            <label class="form-label" for="userName">Full Name</label>
            <input 
              id="userName" 
              type="text" 
              class="form-input" 
              placeholder="e.g. John Doe" 
              [(ngModel)]="newUserName"
            />
          </div>

          <div class="form-group">
            <label class="form-label" for="userEmail">Login Email</label>
            <input 
              id="userEmail" 
              type="email" 
              class="form-input" 
              placeholder="colleague@company.com" 
              [(ngModel)]="newUserEmail"
            />
          </div>

          <div class="form-group">
            <div class="label-row">
              <label class="form-label" for="userPassword">Initial Password</label>
              <button type="button" class="btn-link" (click)="generateRandomPassword()">
                Generate Secure Password
              </button>
            </div>
            <div class="password-input-wrapper">
              <input 
                id="userPassword" 
                [type]="showPassword() ? 'text' : 'password'" 
                class="form-input password-input" 
                placeholder="Minimum 8 characters" 
                [(ngModel)]="newUserPassword"
              />
              <button 
                type="button" 
                class="password-toggle-btn" 
                (click)="showPassword.update(v => !v)"
                title="Toggle password visibility"
              >
                <app-icon [name]="showPassword() ? 'eye-off' : 'eye'" [size]="16"></app-icon>
              </button>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="userRole">Platform Role</label>
            <select id="userRole" class="form-input" [(ngModel)]="newUserRole">
              <option value="Admin">Admin (Full Management & Settings)</option>
              <option value="Analyst">Analyst (Analytics & Reports)</option>
              <option value="Viewer">Viewer (Read-Only Analytics)</option>
            </select>
          </div>

          @if (formError()) {
            <div class="form-error">
              <span>{{ formError() }}</span>
            </div>
          }
        </div>

        <div footer class="modal-actions">
          <app-button 
            variant="ghost" 
            size="sm" 
            (clicked)="showCreateModal.set(false)"
          >
            Cancel
          </app-button>
          <app-button 
            variant="primary" 
            size="sm" 
            [loading]="submitting()" 
            (clicked)="submitCreateUser()"
          >
            Create User Account
          </app-button>
        </div>
      </app-modal>

      <app-modal
        [isOpen]="!!removeTarget()"
        title="Remove team member"
        (close)="removeTarget.set(null)"
      >
        <p class="confirm-text">
          Remove {{ removeTarget()?.name }} ({{ removeTarget()?.email }})? They lose access immediately. This cannot be undone.
        </p>
        <div footer class="modal-actions">
          <app-button variant="ghost" size="sm" (clicked)="removeTarget.set(null)">Cancel</app-button>
          <app-button variant="danger" size="sm" [loading]="removing()" (clicked)="confirmRemove()">Remove member</app-button>
        </div>
      </app-modal>
    </div>
  `,
  styles: [`
    .team-page {
      display: flex;
      flex-direction: column;
      gap: 1.75rem;
    }

    .page-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
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

    .feedback-banner {
      padding: 0.875rem 1.25rem;
      border-radius: var(--radius-md);
      font-size: 0.875rem;
      font-weight: 500;
    }

    .feedback-banner.success {
      background-color: var(--success-bg);
      color: var(--success-text);
      border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .feedback-banner.error {
      background-color: var(--danger-bg);
      color: var(--danger-text);
      border: 1px solid rgba(239, 68, 68, 0.2);
    }

    .team-table-card {
      background-color: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-sm);
      overflow-x: auto;
    }

    .team-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
    }

    .team-table th {
      padding: 0.875rem 1.25rem;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-subtle);
      border-bottom: 1px solid var(--border-subtle);
      background-color: var(--bg-subtle);
    }

    .team-table td {
      padding: 1rem 1.25rem;
      font-size: 0.875rem;
      color: var(--text-main);
      border-bottom: 1px solid var(--border-subtle);
    }

    .team-table tr:last-child td {
      border-bottom: none;
    }

    @media (max-width: 640px) {
      .team-table th:nth-child(3),
      .team-table td:nth-child(3),
      .team-table th:nth-child(4),
      .team-table td:nth-child(4) {
        display: none;
      }
      .team-table th,
      .team-table td {
        padding: 0.75rem;
      }
      .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.75rem;
      }
      .row-actions {
        flex-direction: column;
        align-items: flex-start;
      }
      .team-table .avatar {
        display: none;
      }
      .info .email {
        overflow-wrap: anywhere;
      }
      .role-select {
        min-width: 96px;
      }
    }

    .row-actions {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      flex-wrap: wrap;
    }

    .role-select {
      width: auto;
      min-width: 110px;
      padding: 0.375rem 0.5rem;
    }

    .muted {
      color: var(--text-muted);
      font-size: 0.8125rem;
    }

    .confirm-text {
      font-size: 0.9375rem;
      color: var(--text-main);
      line-height: 1.5;
    }

    .user-cell {
      display: flex;
      align-items: center;
      gap: 0.875rem;
    }

    .avatar {
      width: 36px;
      height: 36px;
      border-radius: var(--radius-full);
      background: linear-gradient(135deg, #6366f1, #8b5cf6);
      color: #ffffff;
      font-size: 0.8125rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .info {
      display: flex;
      flex-direction: column;
    }

    .info .name {
      font-weight: 600;
      color: var(--text-main);
    }

    .info .email {
      font-size: 0.75rem;
      color: var(--text-muted);
    }

    .loading-state, .empty-state {
      padding: 3rem 1.5rem;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 0.75rem;
      color: var(--text-muted);
      font-size: 0.875rem;
    }

    .spinner {
      width: 24px;
      height: 24px;
      border: 2px solid var(--border-strong);
      border-top-color: var(--primary);
      border-radius: var(--radius-full);
      animation: spin 0.8s linear infinite;
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }

    .create-form {
      display: flex;
      flex-direction: column;
      gap: 1.15rem;
    }

    .form-group {
      display: flex;
      flex-direction: column;
    }

    .label-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 0.375rem;
    }

    .btn-link {
      font-size: 0.75rem;
      color: var(--primary);
      font-weight: 500;
      cursor: pointer;
      padding: 0;
    }

    .btn-link:hover {
      text-decoration: underline;
    }

    .password-input-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }

    .password-input {
      padding-right: 2.5rem;
    }

    .password-toggle-btn {
      position: absolute;
      right: 0.75rem;
      color: var(--text-muted);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0.25rem;
      border-radius: var(--radius-sm);
    }

    .password-toggle-btn:hover {
      color: var(--text-main);
    }

    .form-error {
      padding: 0.625rem 0.875rem;
      border-radius: var(--radius-md);
      background-color: var(--danger-bg);
      color: var(--danger-text);
      font-size: 0.8125rem;
    }

    .modal-actions {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 0.75rem;
    }
  `]
})
export class TeamFeatureComponent implements OnInit {
  private readonly http = inject(HttpClient);
  private readonly auth = inject(AuthService);

  readonly busyUserId = signal<number | null>(null);
  readonly removeTarget = signal<UserDto | null>(null);
  readonly removing = signal<boolean>(false);

  readonly users = signal<UserDto[]>([]);
  readonly loading = signal<boolean>(false);
  readonly submitting = signal<boolean>(false);
  readonly showCreateModal = signal<boolean>(false);
  readonly showPassword = signal<boolean>(false);

  readonly feedbackMessage = signal<string | null>(null);
  readonly feedbackType = signal<'success' | 'error'>('success');
  readonly formError = signal<string | null>(null);

  newUserName = '';
  newUserEmail = '';
  newUserPassword = '';
  newUserRole = 'Analyst';

  ngOnInit(): void {
    this.loadUsers();
  }

  loadUsers(): void {
    this.loading.set(true);
    this.http.get<{ success: boolean; data: UserDto[] }>('/api/v1/users').subscribe({
      next: (res) => {
        this.loading.set(false);
        this.users.set(res.data || []);
      },
      error: () => {
        this.loading.set(false);
        this.feedbackMessage.set('Failed to load team members.');
        this.feedbackType.set('error');
      }
    });
  }

  openCreateModal(): void {
    this.newUserName = '';
    this.newUserEmail = '';
    this.newUserPassword = '';
    this.newUserRole = 'Analyst';
    this.showPassword.set(false);
    this.formError.set(null);
    this.showCreateModal.set(true);
  }

  generateRandomPassword(): void {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%&*';
    let pwd = '';
    for (let i = 0; i < 12; i++) {
      pwd += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    this.newUserPassword = pwd;
    this.showPassword.set(true);
  }

  submitCreateUser(): void {
    if (!this.newUserName.trim()) {
      this.formError.set('Please provide the full name.');
      return;
    }

    if (!this.newUserEmail || !this.newUserEmail.includes('@')) {
      this.formError.set('Please provide a valid email address.');
      return;
    }

    if (!this.newUserPassword || this.newUserPassword.length < 8) {
      this.formError.set('Password must be at least 8 characters long.');
      return;
    }

    this.submitting.set(true);
    this.formError.set(null);

    this.http.post<{ success: boolean; message: string; data?: UserDto }>('/api/v1/users', {
      name: this.newUserName.trim(),
      email: this.newUserEmail.trim().toLowerCase(),
      password: this.newUserPassword,
      role: this.newUserRole,
    }).subscribe({
      next: (res) => {
        this.submitting.set(false);
        this.showCreateModal.set(false);
        this.feedbackMessage.set(`Team member "${this.newUserName}" successfully created with role ${this.newUserRole}.`);
        this.feedbackType.set('success');
        this.loadUsers();
      },
      error: (err) => {
        this.submitting.set(false);
        const msg = err.error?.message || err.error?.errors?.email?.[0] || 'Failed to create team member.';
        this.formError.set(msg);
      }
    });
  }

  isSelf(user: UserDto): boolean {
    return this.auth.currentUser()?.id === user.id;
  }

  private actorIsOwner(): boolean {
    return this.auth.hasRole('Owner');
  }

  assignableRoles(): string[] {
    const roles = ['Admin', 'Analyst', 'Viewer'];
    return this.actorIsOwner() ? ['Owner', ...roles] : roles;
  }

  /** Mirrors the server rules: no self changes; only an Owner may change or remove an Owner. */
  canManage(user: UserDto): boolean {
    if (this.isSelf(user)) return false;
    if (this.getUserRole(user) === 'Owner' && !this.actorIsOwner()) return false;
    return true;
  }

  private showResult(message: string, type: 'success' | 'error'): void {
    this.feedbackMessage.set(message);
    this.feedbackType.set(type);
  }

  changeRole(user: UserDto, role: string): void {
    if (role === this.getUserRole(user)) return;
    this.busyUserId.set(user.id);
    this.http.put<{ success: boolean }>(`/api/v1/users/${user.id}/role`, { role }).subscribe({
      next: () => {
        this.busyUserId.set(null);
        this.showResult(`${user.name} is now ${role}.`, 'success');
        this.loadUsers();
      },
      error: (err) => {
        this.busyUserId.set(null);
        this.showResult(err.error?.error?.message || err.error?.message || 'Failed to change the role.', 'error');
        this.loadUsers();
      }
    });
  }

  askRemove(user: UserDto): void {
    this.removeTarget.set(user);
  }

  confirmRemove(): void {
    const user = this.removeTarget();
    if (!user) return;
    this.removing.set(true);
    this.http.delete<{ success: boolean }>(`/api/v1/users/${user.id}`).subscribe({
      next: () => {
        this.removing.set(false);
        this.removeTarget.set(null);
        this.showResult(`${user.name} was removed.`, 'success');
        this.loadUsers();
      },
      error: (err) => {
        this.removing.set(false);
        this.removeTarget.set(null);
        this.showResult(err.error?.error?.message || err.error?.message || 'Failed to remove the member.', 'error');
      }
    });
  }

  getUserRole(user: UserDto): string {
    return user.roles && user.roles.length > 0 ? user.roles[0].name : 'Viewer';
  }

  getRoleBadgeVariant(role: string): BadgeVariant {
    switch (role.toLowerCase()) {
      case 'owner':
        return 'info';
      case 'admin':
        return 'warning';
      case 'media buyer':
        return 'info';
      default:
        return 'neutral';
    }
  }

  getInitials(name: string): string {
    if (!name) return '??';
    const parts = name.trim().split(/\s+/);
    if (parts.length >= 2) {
      return (parts[0][0] + parts[1][0]).toUpperCase();
    }
    return name.slice(0, 2).toUpperCase();
  }

  formatDate(dateString: string): string {
    if (!dateString) return '—';
    try {
      const d = new Date(dateString);
      return d.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
      });
    } catch {
      return dateString;
    }
  }
}
