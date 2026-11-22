import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { ProviderAppConfigComponent, ProviderConfigDto, createDefaultConfig } from './provider-app-config.component';

describe('ProviderAppConfigComponent', () => {
  let fixture: ComponentFixture<ProviderAppConfigComponent>;
  let component: ProviderAppConfigComponent;
  let httpMock: HttpTestingController;

  function create(config: ProviderConfigDto, status: 'loading' | 'loaded' | 'error' = 'loaded') {
    fixture = TestBed.createComponent(ProviderAppConfigComponent);
    component = fixture.componentInstance;
    fixture.componentRef.setInput('provider', config.provider);
    fixture.componentRef.setInput('config', config);
    fixture.componentRef.setInput('configStatus', status);
    httpMock = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
  }

  function configuredMeta(): ProviderConfigDto {
    return {
      ...createDefaultConfig('meta'),
      app_id: 'meta-app-1',
      has_secret: true,
      masked_secret: '••••••••abcd',
      test_status: 'configured',
    };
  }

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ProviderAppConfigComponent],
      providers: [provideHttpClient(), provideHttpClientTesting()],
    }).compileComponents();
  });

  afterEach(() => httpMock.verify());

  it('shows SETUP NEEDED and stays open when the loaded config is incomplete', () => {
    create(createDefaultConfig('meta'));
    const el: HTMLElement = fixture.nativeElement;
    expect(el.querySelector('app-badge')?.textContent).toContain('SETUP NEEDED');
    expect((el.querySelector('details') as HTMLDetailsElement).open).toBeTruthy();
  });

  it('shows CREDENTIALS SAVED and collapses when the loaded config is complete', () => {
    create(configuredMeta());
    const el: HTMLElement = fixture.nativeElement;
    expect(el.querySelector('app-badge')?.textContent).toContain('CREDENTIALS SAVED');
    expect((el.querySelector('details') as HTMLDetailsElement).open).toBeFalsy();
  });

  it('requires the developer token before calling Google complete', () => {
    const partial: ProviderConfigDto = { ...createDefaultConfig('google'), app_id: 'g-client', has_secret: true, masked_secret: '••••••••wxyz' };
    create(partial);
    expect(fixture.nativeElement.querySelector('app-badge')?.textContent).toContain('SETUP NEEDED');

    create({ ...partial, has_developer_token: true, masked_developer_token: '••••••••tokn' });
    expect(fixture.nativeElement.querySelector('app-badge')?.textContent).toContain('CREDENTIALS SAVED');
  });

  it('reports STATUS UNKNOWN, never SETUP NEEDED, when the config load failed', () => {
    create(createDefaultConfig('tiktok'), 'error');
    const el: HTMLElement = fixture.nativeElement;
    expect(el.querySelector('app-badge')?.textContent).toContain('STATUS UNKNOWN');
    expect(el.querySelector('app-badge')?.textContent).not.toContain('SETUP NEEDED');
  });

  it('save sends the new secret without overwriting redirect metadata, then stores the masked response', () => {
    create(createDefaultConfig('meta'));
    component.appIdInput = 'meta-app-1';
    component.secretInput = 'real-secret-value';
    component.save();

    const req = httpMock.expectOne('/api/v1/admin/developer-configs/meta');
    expect(req.request.method).toBe('PUT');
    expect(req.request.body.app_id).toBe('meta-app-1');
    expect(req.request.body.app_secret).toBe('real-secret-value');
    expect('redirect_uri' in req.request.body).toBeFalsy();

    req.flush({ success: true, message: 'saved', data: configuredMeta() });
    expect(component.config().has_secret).toBeTruthy();
    expect(component.config().masked_secret).toBe('••••••••abcd');
    expect(component.secretInput).toBe('');
  });

  it('save never resends the masked placeholder as the secret', () => {
    create(configuredMeta());
    component.secretInput = '';
    component.save();

    const req = httpMock.expectOne('/api/v1/admin/developer-configs/meta');
    expect('app_secret' in req.request.body).toBeFalsy();
    req.flush({ success: true, message: 'saved', data: configuredMeta() });
  });

  it('test handshake stores the server-reported status', () => {
    create(configuredMeta());
    component.test();

    const req = httpMock.expectOne('/api/v1/admin/developer-configs/meta/test');
    expect(req.request.method).toBe('POST');
    req.flush({ success: true, data: { test_status: 'configured', last_tested_at: '2026-11-22T12:00:00+00:00' } });

    expect(component.config().test_status).toBe('configured');
    expect(component.config().last_tested_at).toBe('2026-11-22T12:00:00+00:00');
  });

  it('builds the redirect URI from the current origin, never a hardcoded host', () => {
    create(createDefaultConfig('snapchat'));
    expect(component.redirectUri).toBe(window.location.origin + '/accounts');
  });
  it('configured Snapchat expands to editable Client ID, Client Secret, callback and saved-field check', async () => {
    create({ ...createDefaultConfig('snapchat'), app_id: 'snap-client', has_secret: true, masked_secret: '••••••••sn42' });
    const el: HTMLElement = fixture.nativeElement;
    const details = el.querySelector('details') as HTMLDetailsElement;
    expect(details.open).toBeFalsy();
    el.querySelector('summary')!.click();
    fixture.detectChanges();
    expect(details.open).toBeTruthy();
    await fixture.whenStable();
    fixture.detectChanges();
    expect(el.querySelector('label[for=snapchatAppId]')?.textContent).toContain('Client ID');
    expect(el.querySelector('label[for=snapchatSecret]')?.textContent).toContain('Client Secret');
    expect((el.querySelector('#snapchatAppId') as HTMLInputElement).value).toBe('snap-client');
    expect(el.textContent).toContain('Redirect URI');
    expect(el.textContent).toContain('Copy');
    expect(el.textContent).toContain('Save Snapchat App');
    expect(el.textContent).toContain('Check saved fields');
    component.appIdInput = 'snap-revised';
    component.secretInput = 'fixture-rotated-secret';
    component.save();
    const req = httpMock.expectOne('/api/v1/admin/developer-configs/snapchat');
    expect(req.request.body.app_id).toBe('snap-revised');
    expect(req.request.body.app_secret).toBe('fixture-rotated-secret');
    req.flush({ success: true, data: { ...component.config(), app_id: 'snap-revised', masked_secret: '••••••••new1' } });
    expect(component.appIdInput).toBe('snap-revised');
    expect(component.secretInput).toBe('');
  });

});
