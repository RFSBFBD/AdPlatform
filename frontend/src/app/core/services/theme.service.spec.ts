import { TestBed } from '@angular/core/testing';
import { ThemeService, Theme } from './theme.service';

describe('ThemeService', () => {
  let service: ThemeService;

  beforeEach(() => {
    TestBed.configureTestingModule({});
    service = TestBed.inject(ThemeService);
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });

  it('should default to light or dark theme', () => {
    const theme = service.currentTheme();
    expect(['light', 'dark']).toContain(theme);
  });

  it('should toggle theme between light and dark', () => {
    const initial = service.currentTheme();
    service.toggleTheme();
    const toggled = service.currentTheme();
    expect(toggled).not.toEqual(initial);
    expect(['light', 'dark']).toContain(toggled);
  });

  it('should set explicit theme', () => {
    service.setTheme('dark');
    expect(service.currentTheme()).toBe('dark');
    service.setTheme('light');
    expect(service.currentTheme()).toBe('light');
  });
});
