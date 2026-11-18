import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ButtonComponent } from '../../shared/components/button/button.component';
import { BadgeComponent } from '../../shared/components/badge/badge.component';

@Component({
  selector: 'app-team-feature',
  standalone: true,
  imports: [CommonModule, ButtonComponent, BadgeComponent],
  template: `
    <div class="team-page">
      <div class="page-header">
        <div>
          <h2 class="title">Team & Access Management</h2>
          <p class="subtitle">Manage user accounts and role-based permissions (RBAC).</p>
        </div>
        <app-button variant="primary" size="sm">+ Invite Member</app-button>
      </div>

      <div class="team-table-card">
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
            <tr>
              <td>
                <div class="user-cell">
                  <div class="avatar">JD</div>
                  <div class="info">
                    <span class="name">John Doe</span>
                    <span class="email">john@example.com</span>
                  </div>
                </div>
              </td>
              <td><app-badge variant="info">Owner</app-badge></td>
              <td><app-badge variant="success">Active</app-badge></td>
              <td>Nov 18, 2026</td>
              <td><span class="action-btn">Edit</span></td>
            </tr>
          </tbody>
        </table>
      </div>
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

    .team-table-card {
      background-color: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      overflow: hidden;
      box-shadow: var(--shadow-sm);
    }

    .team-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
      font-size: 0.875rem;

      th {
        padding: 0.875rem 1.25rem;
        background-color: var(--bg-subtle);
        color: var(--text-muted);
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-bottom: 1px solid var(--border-subtle);
      }

      td {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--border-subtle);
        color: var(--text-main);
      }

      tr:last-child td {
        border-bottom: none;
      }
    }

    .user-cell {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    .avatar {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background-color: var(--primary);
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 0.75rem;
    }

    .info {
      display: flex;
      flex-direction: column;
    }

    .name {
      font-weight: 600;
      color: var(--text-main);
    }

    .email {
      font-size: 0.75rem;
      color: var(--text-muted);
    }

    .action-btn {
      color: var(--primary);
      cursor: pointer;
      font-weight: 500;
      &:hover {
        text-decoration: underline;
      }
    }
  `]
})
export class TeamFeatureComponent {}
