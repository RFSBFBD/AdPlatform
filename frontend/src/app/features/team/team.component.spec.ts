import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { TeamFeatureComponent } from './team.component';

describe('TeamFeatureComponent', () => {
  let component: TeamFeatureComponent;
  let fixture: ComponentFixture<TeamFeatureComponent>;
  let httpMock: HttpTestingController;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [TeamFeatureComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
      ]
    }).compileComponents();

    fixture = TestBed.createComponent(TeamFeatureComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('should create and fetch users on init', () => {
    fixture.detectChanges();

    const req = httpMock.expectOne('/api/v1/users');
    expect(req.request.method).toBe('GET');

    req.flush({
      success: true,
      data: [
        {
          id: 1,
          name: 'Sarah Connor',
          email: 'sarah@example.com',
          status: 'active',
          roles: [{ id: 1, name: 'Admin' }],
          created_at: '2026-11-18T12:00:00Z',
        }
      ]
    });

    fixture.detectChanges();

    expect(component.users().length).toBe(1);
    expect(component.users()[0].name).toBe('Sarah Connor');
    expect(component.loading()).toBe(false);

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('.name')?.textContent).toContain('Sarah Connor');
    expect(compiled.querySelector('.email')?.textContent).toContain('sarah@example.com');
  });

  it('should open create user modal when clicked', () => {
    fixture.detectChanges();
    const req = httpMock.expectOne('/api/v1/users');
    req.flush({ success: true, data: [] });

    component.openCreateModal();
    expect(component.showCreateModal()).toBe(true);
    expect(component.newUserName).toBe('');
    expect(component.newUserEmail).toBe('');
    expect(component.newUserPassword).toBe('');
    expect(component.newUserRole).toBe('Analyst');
  });

  it('should compute user initials and role badge variants correctly', () => {
    expect(component.getInitials('John Connor')).toBe('JC');
    expect(component.getInitials('Admin')).toBe('AD');
    expect(component.getRoleBadgeVariant('Owner')).toBe('info');
    expect(component.getRoleBadgeVariant('Admin')).toBe('warning');
    expect(component.getRoleBadgeVariant('Analyst')).toBe('neutral');
  });
});
