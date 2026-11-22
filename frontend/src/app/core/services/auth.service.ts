import { Injectable, inject, signal, computed } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { Observable, tap, map, catchError, throwError } from 'rxjs';
import { ALLOW_PREVIEW_SESSION, PREVIEW_TOKEN_PREFIX } from '../config/runtime';

export interface UserProfile {
  id: number;
  uuid: string;
  name: string;
  email: string;
  status: string;
  roles: string[];
  permissions: string[];
}

export interface LoginResponse {
  success: boolean;
  data: {
    user: UserProfile;
    token: string;
    token_type: string;
  };
}

@Injectable({
  providedIn: 'root'
})
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);
  private readonly tokenKey = 'ad_platform_auth_token';
  private readonly userKey = 'ad_platform_auth_user';

  readonly currentUser = signal<UserProfile | null>(this.loadStoredUser());
  readonly token = signal<string | null>(this.loadStoredToken());
  readonly isAuthenticated = computed(() => !!this.token() && !!this.currentUser());

  login(credentials: { email: string; password: string }): Observable<LoginResponse> {
    return this.http.post<LoginResponse>('/api/v1/auth/login', credentials).pipe(
      tap((res) => {
        if (res?.success && res.data?.token && !res.data.token.startsWith(PREVIEW_TOKEN_PREFIX)) {
          this.setSession(res.data.user, res.data.token);
        }
      }),
      map((res) => {
        if (!this.isAuthenticated()) {
          // e.g. an HTML page served instead of the API: not a real login.
          throw { status: 0 };
        }
        return res;
      }),
      catchError((err) => throwError(() => this.toLoginError(err, credentials.email)))
    );
  }

  /** True only when the current session is a demo preview, not a backend-issued session. */
  readonly isPreviewSession = computed(() => (this.token() ?? '').startsWith(PREVIEW_TOKEN_PREFIX));

  /** Starts an explicit, clearly labelled demo session. Disabled unless ALLOW_PREVIEW_SESSION is true. */
  startPreviewSession(): boolean {
    if (!ALLOW_PREVIEW_SESSION) {
      return false;
    }
    const previewUser: UserProfile = {
      id: 0,
      uuid: 'preview',
      name: 'Preview (not signed in)',
      email: 'preview@local',
      status: 'preview',
      roles: ['Preview'],
      permissions: ['view-analytics'],
    };
    this.setSession(previewUser, PREVIEW_TOKEN_PREFIX + Math.random().toString(36).substring(2));
    return true;
  }

  /** Turns transport/backend failures into an honest error, never a fake session. */
  toLoginError(err: any, _email?: string): { status: number; error: { error: { message: string } } } {
    const status: number = err?.status ?? 0;
    const apiMessage = err?.error?.error?.message;
    let message: string;
    if (apiMessage) {
      message = apiMessage;
    } else if (status === 401 || status === 422) {
      message = 'Login failed. Please check your credentials.';
    } else if (status === 429) {
      message = 'Too many attempts. Try again later.';
    } else {
      message = 'The authentication service is unavailable (the API did not respond correctly). You are not signed in.';
    }
    return { status, error: { error: { message } } };
  }

  logout(): void {
    if (this.token()) {
      this.http.post('/api/v1/auth/logout', {}).subscribe({
        complete: () => this.clearSession(),
        error: () => this.clearSession(),
      });
    } else {
      this.clearSession();
    }
  }

  hasRole(role: string): boolean {
    const user = this.currentUser();
    return user ? user.roles.includes(role) : false;
  }

  hasPermission(permission: string): boolean {
    const user = this.currentUser();
    if (!user) return false;
    if (user.roles.includes('Owner')) return true; // Owner has all permissions
    return user.permissions.includes(permission);
  }

  private setSession(user: UserProfile, token: string): void {
    this.currentUser.set(user);
    this.token.set(token);
    localStorage.setItem(this.tokenKey, token);
    localStorage.setItem(this.userKey, JSON.stringify(user));
  }

  private clearSession(): void {
    this.currentUser.set(null);
    this.token.set(null);
    localStorage.removeItem(this.tokenKey);
    localStorage.removeItem(this.userKey);
    this.router.navigate(['/login']);
  }

  private loadStoredToken(): string | null {
    if (typeof localStorage !== 'undefined') {
      const t = localStorage.getItem(this.tokenKey);
      if (t && t.startsWith(PREVIEW_TOKEN_PREFIX) && !ALLOW_PREVIEW_SESSION) {
        localStorage.removeItem(this.tokenKey);
        localStorage.removeItem(this.userKey);
        return null;
      }
      return t;
    }
    return null;
  }

  private loadStoredUser(): UserProfile | null {
    if (typeof localStorage !== 'undefined' && localStorage.getItem(this.tokenKey)) {
      const stored = localStorage.getItem(this.userKey);
      if (stored) {
        try {
          return JSON.parse(stored);
        } catch {
          return null;
        }
      }
    }
    return null;
  }
}
