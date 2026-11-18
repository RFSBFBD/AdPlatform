import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Router } from '@angular/router';
import { ThemeService } from '../../services/theme.service';
import { AuthService } from '../../services/auth.service';

interface NavItem {
  label: string;
  route: string;
  icon: string;
}

@Component({
  selector: 'app-shell',
  standalone: true,
  imports: [CommonModule, RouterModule],
  template: `
    <div class="shell-layout" [class.mobile-open]="isMobileMenuOpen()">
      <!-- Mobile Backdrop -->
      @if (isMobileMenuOpen()) {
        <div class="mobile-backdrop" (click)="closeMobileMenu()"></div>
      }

      <!-- Sidebar -->
      <aside class="sidebar">
        <div class="sidebar-brand">
          <div class="brand-logo">AD</div>
          <div class="brand-info">
            <span class="brand-title">AdPlatform</span>
            <span class="brand-badge">SaaS</span>
          </div>
        </div>

        <nav class="sidebar-nav">
          @for (item of navItems; track item.route) {
            <a 
              [routerLink]="item.route" 
              routerLinkActive="active" 
              class="nav-link"
              (click)="closeMobileMenu()"
            >
              <span class="nav-icon">{{ item.icon }}</span>
              <span class="nav-label">{{ item.label }}</span>
            </a>
          }
        </nav>

        <div class="sidebar-footer">
          <div class="user-pill">
            <div class="user-avatar">{{ getUserInitials() }}</div>
            <div class="user-details">
              <span class="user-name">{{ authService.currentUser()?.name || 'User' }}</span>
              <span class="user-role">{{ getPrimaryRole() }}</span>
            </div>
            <button 
              type="button" 
              class="logout-icon-btn" 
              (click)="authService.logout()" 
              title="Sign Out"
              aria-label="Sign Out"
            >
              🚪
            </button>
          </div>
        </div>
      </aside>

      <!-- Main Content Area -->
      <div class="main-wrapper">
        <!-- Header -->
        <header class="topbar">
          <div class="topbar-left">
            <button 
              type="button" 
              class="hamburger-btn" 
              (click)="toggleMobileMenu()" 
              aria-label="Toggle navigation menu"
            >
              ☰
            </button>
            <h1 class="page-title">{{ getPageTitle() }}</h1>
          </div>

          <div class="topbar-right">
            <!-- Theme Toggle Button -->
            <button 
              type="button" 
              class="icon-btn theme-toggle" 
              (click)="themeService.toggleTheme()" 
              [title]="themeService.currentTheme() === 'dark' ? 'Switch to Light Mode' : 'Switch to Dark Mode'"
              aria-label="Toggle color theme"
            >
              @if (themeService.currentTheme() === 'dark') {
                <span class="theme-icon">☀️</span>
              } @else {
                <span class="theme-icon">🌙</span>
              }
            </button>
          </div>
        </header>

        <!-- Page Outlet -->
        <main class="content-container">
          <router-outlet></router-outlet>
        </main>
      </div>
    </div>
  `,
  styles: [`
    .shell-layout {
      display: flex;
      height: 100vh;
      overflow: hidden;
      background-color: var(--bg-app);
    }

    /* Sidebar */
    .sidebar {
      width: 260px;
      background-color: var(--sidebar-bg);
      border-right: 1px solid var(--sidebar-border);
      display: flex;
      flex-direction: column;
      flex-shrink: 0;
      transition: transform var(--transition-normal);
      z-index: 50;
    }

    .sidebar-brand {
      height: 64px;
      padding: 0 1.25rem;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      border-bottom: 1px solid var(--sidebar-border);
    }

    .brand-logo {
      width: 36px;
      height: 36px;
      border-radius: var(--radius-md);
      background: linear-gradient(135deg, var(--primary), #818cf8);
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 0.875rem;
    }

    .brand-info {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .brand-title {
      font-weight: 700;
      font-size: 1.0625rem;
      color: var(--text-main);
      letter-spacing: -0.02em;
    }

    .brand-badge {
      font-size: 0.6875rem;
      font-weight: 600;
      text-transform: uppercase;
      padding: 0.125rem 0.375rem;
      background-color: var(--primary-light);
      color: var(--primary);
      border-radius: var(--radius-sm);
    }

    .sidebar-nav {
      flex: 1;
      padding: 1rem 0.75rem;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 0.25rem;
    }

    .nav-link {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.625rem 0.875rem;
      border-radius: var(--radius-md);
      color: var(--text-muted);
      font-size: 0.875rem;
      font-weight: 500;
      transition: all var(--transition-fast);

      &:hover {
        background-color: var(--bg-subtle);
        color: var(--text-main);
      }

      &.active {
        background-color: var(--primary-light);
        color: var(--primary);
        font-weight: 600;
      }
    }

    .nav-icon {
      font-size: 1.125rem;
      width: 22px;
      text-align: center;
    }

    .sidebar-footer {
      padding: 1rem 0.75rem;
      border-top: 1px solid var(--sidebar-border);
    }

    .user-pill {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.5rem;
      border-radius: var(--radius-md);
      background-color: var(--bg-subtle);
    }

    .user-avatar {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background-color: var(--primary);
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 0.8125rem;
      flex-shrink: 0;
    }

    .user-details {
      display: flex;
      flex-direction: column;
      overflow: hidden;
      flex: 1;
    }

    .user-name {
      font-size: 0.8125rem;
      font-weight: 600;
      color: var(--text-main);
      white-space: nowrap;
      text-overflow: ellipsis;
      overflow: hidden;
    }

    .user-role {
      font-size: 0.6875rem;
      color: var(--text-muted);
    }

    .logout-icon-btn {
      color: var(--text-muted);
      font-size: 1rem;
      padding: 0.25rem;
      border-radius: var(--radius-sm);
      transition: all var(--transition-fast);

      &:hover {
        color: var(--danger);
        background-color: var(--bg-hover);
      }
    }

    /* Main Area */
    .main-wrapper {
      flex: 1;
      display: flex;
      flex-direction: column;
      min-width: 0;
      height: 100vh;
      overflow: hidden;
    }

    .topbar {
      height: 64px;
      padding: 0 1.5rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid var(--border-subtle);
      background-color: var(--header-bg);
      backdrop-filter: blur(8px);
      z-index: 10;
    }

    .topbar-left {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .hamburger-btn {
      display: none;
      font-size: 1.25rem;
      color: var(--text-main);
      padding: 0.375rem;
      border-radius: var(--radius-sm);
    }

    .page-title {
      font-size: 1.125rem;
      font-weight: 600;
      color: var(--text-main);
    }

    .topbar-right {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    .icon-btn {
      width: 38px;
      height: 38px;
      border-radius: var(--radius-md);
      display: flex;
      align-items: center;
      justify-content: center;
      background-color: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      color: var(--text-main);
      transition: all var(--transition-fast);

      &:hover {
        background-color: var(--bg-hover);
        border-color: var(--border-strong);
      }
    }

    .content-container {
      flex: 1;
      padding: 1.75rem;
      overflow-y: auto;
    }

    /* Responsive Breakpoints */
    @media (max-width: 768px) {
      .sidebar {
        position: fixed;
        top: 0;
        bottom: 0;
        left: 0;
        transform: translateX(-100%);
      }

      .mobile-open .sidebar {
        transform: translateX(0);
      }

      .mobile-backdrop {
        position: fixed;
        inset: 0;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 40;
      }

      .hamburger-btn {
        display: flex;
      }

      .content-container {
        padding: 1rem;
      }
    }
  `]
})
export class AppShellComponent {
  readonly themeService = inject(ThemeService);
  readonly authService = inject(AuthService);
  private readonly router = inject(Router);

  readonly isMobileMenuOpen = signal<boolean>(false);

  readonly navItems: NavItem[] = [
    { label: 'Overview Dashboard', route: '/dashboard', icon: '📊' },
    { label: 'Campaigns', route: '/campaigns', icon: '🎯' },
    { label: 'Ad Accounts', route: '/accounts', icon: '🔗' },
    { label: 'Billing & Payments', route: '/billing', icon: '💳' },
    { label: 'AI Center', route: '/ai-center', icon: '✨' },
    { label: 'Developer API Settings', route: '/developer-settings', icon: '⚙️' },
    { label: 'Team & Access', route: '/team', icon: '👥' },
  ];

  toggleMobileMenu(): void {
    this.isMobileMenuOpen.update(v => !v);
  }

  closeMobileMenu(): void {
    this.isMobileMenuOpen.set(false);
  }

  getPageTitle(): string {
    const url = this.router.url;
    const current = this.navItems.find(i => url.startsWith(i.route));
    return current ? current.label : 'AdPlatform';
  }

  getUserInitials(): string {
    const name = this.authService.currentUser()?.name;
    if (!name) return 'U';
    return name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
  }

  getPrimaryRole(): string {
    return this.authService.currentUser()?.roles?.[0] || 'User';
  }
}
