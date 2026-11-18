import { Injectable, signal, effect, PLATFORM_ID, inject } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';

export type Theme = 'light' | 'dark';

@Injectable({
  providedIn: 'root'
})
export class ThemeService {
  private readonly platformId = inject(PLATFORM_ID);
  private readonly storageKey = 'ad_platform_theme';

  readonly currentTheme = signal<Theme>('light');

  constructor() {
    if (isPlatformBrowser(this.platformId)) {
      const initialTheme = this.resolveInitialTheme();
      this.currentTheme.set(initialTheme);
      this.applyTheme(initialTheme);

      // Listen for OS theme changes if user hasn't explicitly set a preference
      if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
        const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        if (mediaQuery && typeof mediaQuery.addEventListener === 'function') {
          mediaQuery.addEventListener('change', (e) => {
            if (!localStorage.getItem(this.storageKey)) {
              const newTheme: Theme = e.matches ? 'dark' : 'light';
              this.currentTheme.set(newTheme);
              this.applyTheme(newTheme);
            }
          });
        }
      }
    }
  }

  toggleTheme(): void {
    const nextTheme: Theme = this.currentTheme() === 'light' ? 'dark' : 'light';
    this.setTheme(nextTheme);
  }

  setTheme(theme: Theme): void {
    this.currentTheme.set(theme);
    if (isPlatformBrowser(this.platformId)) {
      localStorage.setItem(this.storageKey, theme);
      this.applyTheme(theme);
    }
  }

  private resolveInitialTheme(): Theme {
    const saved = localStorage.getItem(this.storageKey) as Theme | null;
    if (saved === 'light' || saved === 'dark') {
      return saved;
    }
    const prefersDark = typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia('(prefers-color-scheme: dark)').matches;
    return prefersDark ? 'dark' : 'light';
  }

  private applyTheme(theme: Theme): void {
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.style.colorScheme = theme;
  }
}
