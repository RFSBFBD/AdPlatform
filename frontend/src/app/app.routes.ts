import { Routes } from '@angular/router';
import { AppShellComponent } from './core/layout/app-shell/app-shell.component';
import { authGuard, guestGuard } from './core/guards/auth.guard';

export const routes: Routes = [
  {
    path: 'login',
    canActivate: [guestGuard],
    loadComponent: () => import('./features/auth/login/login.component').then(m => m.LoginComponent)
  },
  {
    path: '',
    component: AppShellComponent,
    canActivate: [authGuard],
    children: [
      {
        path: '',
        pathMatch: 'full',
        redirectTo: 'dashboard'
      },
      {
        path: 'dashboard',
        loadComponent: () => import('./features/dashboard/dashboard.component').then(m => m.DashboardFeatureComponent)
      },
      {
        path: 'accounts',
        loadComponent: () => import('./features/accounts/accounts.component').then(m => m.AccountsFeatureComponent)
      },
      {
        path: 'business-profile',
        loadComponent: () => import('./features/business-profile/business-profile.component').then(m => m.BusinessProfileComponent)
      },
      {
        path: 'campaigns',
        loadComponent: () => import('./features/campaigns/campaigns.component').then(m => m.CampaignsFeatureComponent)
      },
      {
        path: 'operations-research',
        loadComponent: () => import('./features/operations-research/operations-research.component').then(m => m.OperationsResearchComponent)
      },
      {
        path: 'ai-center',
        loadComponent: () => import('./features/ai-center/ai-center.component').then(m => m.AiCenterFeatureComponent)
      },
      {
        // Retired as a separate page: developer app setup now lives as Step 1
        // inside the unified Integrations & Connected Accounts page.
        path: 'developer-settings',
        redirectTo: 'accounts'
      },
      {
        path: 'team',
        loadComponent: () => import('./features/team/team.component').then(m => m.TeamFeatureComponent)
      }
    ]
  },
  {
    path: '**',
    redirectTo: 'dashboard'
  }
];
