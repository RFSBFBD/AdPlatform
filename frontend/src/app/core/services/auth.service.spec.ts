import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { AuthService } from './auth.service';

describe('AuthService (no fake sessions)', () => {
  let service: AuthService;
  let http: HttpTestingController;

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    });
    service = TestBed.inject(AuthService);
    http = TestBed.inject(HttpTestingController);
  });

  const attempt = () => {
    let error: any = null;
    service.login({ email: 'a@b.co', password: 'x' }).subscribe({ error: (e) => (error = e) });
    return { req: http.expectOne('/api/v1/auth/login'), get error() { return error; } };
  };

  it('does not create a session when the API answers 405', () => {
    const a = attempt();
    a.req.flush('nope', { status: 405, statusText: 'Method Not Allowed' });
    expect(service.isAuthenticated()).toBe(false);
    expect(localStorage.getItem('ad_platform_auth_token')).toBeNull();
    expect(a.error.error.error.message).toContain('unavailable');
  });

  it('does not create a session when HTML is returned with 200', () => {
    const a = attempt();
    a.req.flush('<!doctype html><html></html>');
    expect(service.isAuthenticated()).toBe(false);
    expect(a.error).not.toBeNull();
  });

  it('shows credentials error on 401', () => {
    const a = attempt();
    a.req.flush({}, { status: 401, statusText: 'Unauthorized' });
    expect(a.error.error.error.message).toContain('credentials');
  });

  it('creates a session only from a backend token', () => {
    const a = attempt();
    a.req.flush({ success: true, data: { user: { id: 1, uuid: 'u', name: 'N', email: 'a@b.co', status: 'active', roles: ['Admin'], permissions: [] }, token: 'real', token_type: 'Bearer' } });
    expect(service.isAuthenticated()).toBe(true);
    expect(service.isPreviewSession()).toBe(false);
  });

  it('refuses to start a preview session by default', () => {
    expect(service.startPreviewSession()).toBe(false);
    expect(service.isAuthenticated()).toBe(false);
  });

  it('drops a stored preview token', () => {
    localStorage.setItem('ad_platform_auth_token', 'preview_cloud_token_abc');
    localStorage.setItem('ad_platform_auth_user', JSON.stringify({ id: 1, roles: ['Owner'], permissions: [] }));
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])] });
    const fresh = TestBed.inject(AuthService);
    expect(fresh.isAuthenticated()).toBe(false);
  });
});
