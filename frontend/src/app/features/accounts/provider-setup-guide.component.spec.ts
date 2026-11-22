import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ProviderSetupGuideComponent } from './provider-setup-guide.component';
import { PROVIDER_GUIDES } from './provider-guides';

describe('ProviderSetupGuideComponent', () => {
  let fixture: ComponentFixture<ProviderSetupGuideComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ProviderSetupGuideComponent],
    }).compileComponents();
    fixture = TestBed.createComponent(ProviderSetupGuideComponent);
  });

  it('renders the shared guide for a known provider with portal link and sections', () => {
    fixture.componentInstance.providerId = 'meta';
    fixture.detectChanges();
    const el: HTMLElement = fixture.nativeElement;
    const details = el.querySelector('details.setup-guide');
    expect(details).not.toBeNull();
    expect(details!.getAttribute('data-guide')).toBe('meta');
    const portal = el.querySelector('a[href]') as HTMLAnchorElement;
    expect(portal.href).toBe(PROVIDER_GUIDES.meta.portalUrl);
    const text = el.textContent || '';
    expect(text).toContain('official steps');
    expect(text).toContain('You need');
    expect(text).toContain('Limits');
    for (const step of PROVIDER_GUIDES.meta.steps) {
      expect(text).toContain(step);
    }
  });

  it('renders nothing for an unknown provider id', () => {
    fixture.componentInstance.providerId = 'unknown-provider';
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('details')).toBeNull();
  });
});
