import { vi } from 'vitest';
import { By } from '@angular/platform-browser';
import { ProviderAppConfigComponent } from './provider-app-config.component';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { AccountsFeatureComponent } from './accounts.component';
import { AuthService } from '../../core/services/auth.service';
import { routes } from '../../app.routes';

const CONFIGS = [
  {
    id: 1, provider: 'meta', app_id: 'meta-app-1', has_secret: true, masked_secret: '••••••••abcd',
    has_developer_token: false, masked_developer_token: null, redirect_uri: null, api_version: null,
    is_enabled: true, test_status: 'configured', test_error: null, last_tested_at: '2026-11-22T10:00:00+00:00',
  },
  {
    id: 2, provider: 'google', app_id: '', has_secret: false, masked_secret: null,
    has_developer_token: false, masked_developer_token: null, redirect_uri: null, api_version: null,
    is_enabled: true, test_status: 'untested', test_error: null, last_tested_at: null,
  },
  {
    id: 3, provider: 'tiktok', app_id: '', has_secret: false, masked_secret: null,
    has_developer_token: false, masked_developer_token: null, redirect_uri: null, api_version: null,
    is_enabled: true, test_status: 'untested', test_error: null, last_tested_at: null,
  },
  {
    id: 4, provider: 'snapchat', app_id: '', has_secret: false, masked_secret: null,
    has_developer_token: false, masked_developer_token: null, redirect_uri: null, api_version: null,
    is_enabled: true, test_status: 'untested', test_error: null, last_tested_at: null,
  },
];

describe('AccountsFeatureComponent (unified integrations journey)', () => {
  let fixture: ComponentFixture<AccountsFeatureComponent>;
  let component: AccountsFeatureComponent;
  let httpMock: HttpTestingController;
  let fakeAuth: { currentUser: ReturnType<typeof signal>; hasPermission: (p: string) => boolean };

  function flushRoutine() {
    for (const req of httpMock.match('/api/v1/ad-accounts')) {
      req.flush({ success: true, data: [] });
    }
    for (const req of httpMock.match('/api/v1/analytics/data-coverage')) {
      req.flush('unavailable', { status: 503, statusText: 'unavailable' });
    }
  }

  beforeEach(async () => {
    fakeAuth = { currentUser: signal(null), hasPermission: () => false };
    await TestBed.configureTestingModule({
      imports: [AccountsFeatureComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: AuthService, useValue: fakeAuth },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(AccountsFeatureComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  it('admin: loads the platform app configs and gates Connect per provider', () => {
    fakeAuth.hasPermission = (p: string) => p === 'manage-developers';
    fixture.detectChanges();

    const req = httpMock.expectOne('/api/v1/admin/developer-configs');
    expect(req.request.method).toBe('GET');
    req.flush({ success: true, data: CONFIGS });
    flushRoutine();
    fixture.detectChanges();

    expect(component.devConfigStatus()).toBe('loaded');
    expect(component.isAppConfigured('meta')).toBeTruthy();
    expect(component.isAppConfigured('google')).toBeFalsy();
    expect(component.isConnectDisabled('meta')).toBeFalsy();
    expect(component.isConnectDisabled('google')).toBeTruthy();
  });

  it('member without manage-developers never calls the admin endpoint and is not gated', () => {
    fakeAuth.hasPermission = () => false;
    fixture.detectChanges();

    httpMock.expectNone('/api/v1/admin/developer-configs');
    flushRoutine();
    fixture.detectChanges();

    expect(component.canManageDevelopers()).toBeFalsy();
    expect(fixture.nativeElement.querySelector('app-provider-app-config')).toBeNull();
    expect(fixture.nativeElement.querySelector('input[type=password]')).toBeNull();
    for (const p of ['meta', 'google', 'tiktok', 'snapchat'] as const) {
      expect(component.isConnectDisabled(p)).toBeFalsy();
    }
  });

  it('a failed config load means unknown, so Connect stays available', () => {
    fakeAuth.hasPermission = (p: string) => p === 'manage-developers';
    fixture.detectChanges();

    const req = httpMock.expectOne('/api/v1/admin/developer-configs');
    req.flush('down', { status: 500, statusText: 'down' });
    flushRoutine();
    fixture.detectChanges();

    expect(component.devConfigStatus()).toBe('error');
    for (const p of ['meta', 'google', 'tiktok', 'snapchat'] as const) {
      expect(component.isConnectDisabled(p)).toBeFalsy();
    }
  });

  it('retired /developer-settings route redirects into the merged page behind the same auth shell', () => {
    const legacy = routes
      .flatMap((r) => (r.children ? r.children : [r]))
      .find((r) => r.path === 'developer-settings');
    expect(legacy).toBeDefined();
    expect(legacy!.redirectTo).toBe('accounts');
    expect((legacy as any).loadComponent).toBeUndefined();

    const shell = routes.find((r) => r.path === '' && r.children?.some((c) => c.path === 'accounts'));
    expect(shell).toBeDefined();
    expect(shell!.canActivate?.length).toBeGreaterThan(0);
  });

  it('Looker endpoint is origin-based and only a real schema response verifies it', () => {
    fakeAuth.hasPermission = () => false;
    fixture.detectChanges();
    flushRoutine();

    expect(component.lookerEndpointUrl.startsWith(window.location.origin)).toBeTruthy();
    expect(component.lookerTestStatus()).toBe('untested');

    component.testLookerEndpoint();
    const ok = httpMock.expectOne('/api/v1/reporting/looker-studio?schema=true');
    ok.flush({ schema: [{ name: 'date' }] });
    expect(component.lookerTestStatus()).toBe('connected');

    component.testLookerEndpoint();
    const empty = httpMock.expectOne('/api/v1/reporting/looker-studio?schema=true');
    empty.flush({ schema: [] });
    expect(component.lookerTestStatus()).toBe('error');

    component.testLookerEndpoint();
    const down = httpMock.expectOne('/api/v1/reporting/looker-studio?schema=true');
    down.flush('down', { status: 500, statusText: 'down' });
    expect(component.lookerTestStatus()).toBe('error');
  });
  it('a saved child configuration updates the parent Connect state without reloading', () => {
    fakeAuth.hasPermission = (p: string) => p === 'manage-developers';
    fixture.detectChanges();
    httpMock.expectOne('/api/v1/admin/developer-configs').flush({ success: true, data: CONFIGS });
    flushRoutine();
    fixture.detectChanges();
    expect(component.isConnectDisabled('google')).toBeTruthy();
    const panel = fixture.debugElement.queryAll(By.directive(ProviderAppConfigComponent))[1].componentInstance as ProviderAppConfigComponent;
    panel.appIdInput = 'new-client';
    panel.secretInput = 'fixture-secret';
    panel.tokenInput = 'fixture-token';
    panel.save();
    httpMock.expectOne('/api/v1/admin/developer-configs/google').flush({ success: true, data: { ...CONFIGS[1], app_id: 'new-client', has_secret: true, has_developer_token: true } });
    fixture.detectChanges();
    expect(component.isConnectDisabled('google')).toBeFalsy();
  });

  it('copies the actual endpoint and does not report success when clipboard fails', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
    await component.copyLookerEndpoint();
    expect(writeText).toHaveBeenCalledWith(window.location.origin + '/api/v1/reporting/looker-studio');
    expect(component.copiedLookerUrl()).toBeTruthy();
    writeText.mockRejectedValue(new Error('denied'));
    await component.copyLookerEndpoint();
    expect(component.copiedLookerUrl()).toBeFalsy();
    expect(component.isModalOpen()).toBeTruthy();
  });

  it('malformed successful admin responses remain unknown, not verified missing', () => {
    fakeAuth.hasPermission = (p: string) => p === 'manage-developers';
    fixture.detectChanges();
    httpMock.expectOne('/api/v1/admin/developer-configs').flush({ success: true, data: [] });
    flushRoutine();
    expect(component.devConfigStatus()).toBe('error');
    expect(component.isConnectDisabled('meta')).toBeFalsy();
  });

});
