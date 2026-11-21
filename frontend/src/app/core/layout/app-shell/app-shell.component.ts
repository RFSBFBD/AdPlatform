import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule, Router } from '@angular/router';
import { ThemeService } from '../../services/theme.service';
import { AuthService } from '../../services/auth.service';
import { BrandLogoComponent } from '../../../shared/components/brand-logo/brand-logo.component';
import { IconComponent } from '../../../shared/components/icon/icon.component';
import { ThemeSwitchComponent } from '../../../shared/components/theme-switch/theme-switch.component';
import { RouteProgressBarComponent } from '../../../shared/components/route-progress-bar/route-progress-bar.component';

interface NavItem {
  label: string;
  route: string;
  icon: string;
  badge?: string;
}

@Component({
  selector: 'app-shell',
  standalone: true,
  imports: [
    CommonModule, 
    RouterModule, 
    BrandLogoComponent, 
    IconComponent, 
    ThemeSwitchComponent, 
    RouteProgressBarComponent
  ],
  template: `
    <!-- Top Route Loading Tap Indicator -->
    <app-route-progress-bar></app-route-progress-bar>

    <div class="shell-layout" [class.mobile-open]="isMobileMenuOpen()">
      <!-- Mobile Backdrop -->
      @if (isMobileMenuOpen()) {
        <div class="mobile-backdrop" (click)="closeMobileMenu()"></div>
      }

      <!-- Sidebar -->
      <aside class="sidebar">
        <div class="sidebar-brand">
          <app-brand-logo [size]="34"></app-brand-logo>
          <div class="brand-info">
            <span class="brand-title">AdPlatform</span>
            <span class="brand-badge">SaaS Intelligence</span>
          </div>
        </div>

        <nav class="sidebar-nav">
          <div class="nav-section-title">CORE PLATFORM</div>
          @for (item of navItems; track item.route) {
            <a 
              [routerLink]="item.route" 
              routerLinkActive="active" 
              class="nav-link"
              (click)="closeMobileMenu()"
            >
              <app-icon [name]="item.icon" [size]="18" class="nav-icon"></app-icon>
              <span class="nav-label">{{ item.label }}</span>
              @if (item.badge) {
                <span class="nav-item-badge">{{ item.badge }}</span>
              }
            </a>
          }
        </nav>

        <div class="sidebar-footer">
          <div class="user-pill">
            <div class="user-avatar">{{ getUserInitials() }}</div>
            <div class="user-details">
              <span class="user-name">{{ authService.currentUser()?.name || 'User' }}</span>
              <span class="user-role">{{ getPrimaryRole() | titlecase }}</span>
            </div>
            <button 
              type="button" 
              class="logout-icon-btn" 
              (click)="authService.logout()" 
              title="Sign Out"
              aria-label="Sign Out"
            >
              <app-icon name="logout" [size]="16"></app-icon>
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
              <app-icon name="menu" [size]="20"></app-icon>
            </button>
            <h1 class="page-title">{{ getPageTitle() }}</h1>
          </div>

          <div class="topbar-right">
            <!-- Modern Animated Sun/Moon/Clouds Theme Switch -->
            <app-theme-switch size="12.5px"></app-theme-switch>
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
      height: 68px;
      padding: 0 1.25rem;
      display: flex;
      align-items: center;
      gap: 0.85rem;
      border-bottom: 1px solid var(--sidebar-border);
    }

    .brand-info {
      display: flex;
      flex-direction: column;
    }

    .brand-title {
      font-weight: 700;
      font-size: 1.05rem;
      color: var(--text-main);
      letter-spacing: -0.02em;
    }

    .brand-badge {
      font-size: 0.6875rem;
      font-weight: 600;
      color: var(--primary);
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .sidebar-nav {
      flex: 1;
      padding: 1.25rem 0.875rem;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 0.25rem;
    }

    .nav-section-title {
      font-size: 0.6875rem;
      font-weight: 700;
      letter-spacing: 0.06em;
      color: var(--text-subtle);
      padding: 0.5rem 0.75rem 0.35rem;
    }

    .nav-link {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.625rem 0.875rem;
      border-radius: var(--radius-md);
      color: var(--text-muted);
      font-weight: 500;
      font-size: 0.875rem;
      transition: all var(--transition-fast);
      position: relative;
    }

    .nav-link:hover {
      background-color: var(--bg-subtle);
      color: var(--text-main);
    }

    .nav-link.active {
      background-color: var(--primary-light);
      color: var(--primary);
      font-weight: 600;
    }

    .nav-link.active .nav-icon {
      color: var(--primary);
    }

    .nav-item-badge {
      margin-left: auto;
      font-size: 0.65rem;
      font-weight: 700;
      background: linear-gradient(135deg, #6366f1, #06b6d4);
      color: #ffffff;
      padding: 0.15rem 0.45rem;
      border-radius: var(--radius-full);
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }

    .sidebar-footer {
      padding: 1rem;
      border-top: 1px solid var(--sidebar-border);
    }

    .user-pill {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.5rem;
      border-radius: var(--radius-md);
      background-color: var(--bg-subtle);
      border: 1px solid var(--border-subtle);
    }

    .user-avatar {
      width: 34px;
      height: 34px;
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

    .user-details {
      flex: 1;
      min-width: 0;
      display: flex;
      flex-direction: column;
    }

    .user-name {
      font-size: 0.8125rem;
      font-weight: 600;
      color: var(--text-main);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .user-role {
      font-size: 0.6875rem;
      color: var(--text-subtle);
    }

    .logout-icon-btn {
      color: var(--text-muted);
      padding: 0.35rem;
      border-radius: var(--radius-sm);
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all var(--transition-fast);
    }

    .logout-icon-btn:hover {
      color: var(--danger);
      background-color: var(--danger-bg);
    }

    /* Main Content */
    .main-wrapper {
      flex: 1;
      display: flex;
      flex-direction: column;
      min-width: 0;
      overflow: hidden;
    }

    .topbar {
      height: 68px;
      padding: 0 1.5rem;
      background-color: var(--header-bg);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--sidebar-border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-shrink: 0;
      z-index: 20;
    }

    .topbar-left {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .hamburger-btn {
      display: none;
      color: var(--text-main);
      padding: 0.35rem;
      border-radius: var(--radius-sm);
    }

    .page-title {
      font-size: 1.125rem;
      font-weight: 700;
      color: var(--text-main);
      letter-spacing: -0.01em;
    }

    .content-container {
      flex: 1;
      overflow-y: auto;
      padding: 1.75rem 2rem;
    }

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

  // Modern navigation items: Billing and Accounts removed per user request, AI Chatbot replaced with Operations Research Engine
  readonly navItems: NavItem[] = [
    { label: 'Overview Dashboard', route: '/dashboard', icon: 'dashboard' },
    { label: 'Campaigns', route: '/campaigns', icon: 'campaigns' },
    { label: 'Operations Research', route: '/operations-research', icon: 'chart', badge: 'OR' },
    { label: 'AI Model APIs', route: '/ai-center', icon: 'ai' },
    { label: 'Developer API Hub', route: '/developer-settings', icon: 'developer' },
    { label: 'Team & Access', route: '/team', icon: 'team' },
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
    return current ? current.label : 'AdPlatform Intelligence';
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
