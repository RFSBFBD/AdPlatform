import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { TeamFeatureComponent, UserDto } from './team.component';
import { AuthService } from '../../core/services/auth.service';

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

  describe('role management', () => {
    const mk = (id: number, role: string): UserDto => ({
      id, name: 'User ' + id, email: `u${id}@example.com`, status: 'active',
      roles: [{ id, name: role }], created_at: '2026-11-18T12:00:00Z',
    });
    const actAs = (id: number, role: string) => {
      TestBed.inject(AuthService).currentUser.set({
        id, uuid: 'x', name: 'Me', email: 'me@example.com', status: 'active', roles: [role], permissions: ['manage-users'],
      });
    };

    it('lets an Admin manage others but not self or an Owner', () => {
      actAs(1, 'Admin');
      expect(component.canManage(mk(1, 'Admin'))).toBe(false);
      expect(component.canManage(mk(2, 'Owner'))).toBe(false);
      expect(component.canManage(mk(3, 'Viewer'))).toBe(true);
      expect(component.assignableRoles()).toEqual(['Admin', 'Analyst', 'Viewer']);
    });

    it('lets an Owner manage another Owner and assign Owner', () => {
      actAs(1, 'Owner');
      expect(component.canManage(mk(2, 'Owner'))).toBe(true);
      expect(component.canManage(mk(1, 'Owner'))).toBe(false);
      expect(component.assignableRoles()[0]).toBe('Owner');
    });

    it('changes a role through the API and reloads', () => {
      actAs(1, 'Admin');
      fixture.detectChanges();
      httpMock.expectOne('/api/v1/users').flush({ success: true, data: [mk(3, 'Viewer')] });
      component.changeRole(mk(3, 'Viewer'), 'Analyst');
      const req = httpMock.expectOne('/api/v1/users/3/role');
      expect(req.request.method).toBe('PUT');
      expect(req.request.body).toEqual({ role: 'Analyst' });
      req.flush({ success: true });
      httpMock.expectOne('/api/v1/users').flush({ success: true, data: [mk(3, 'Analyst')] });
      expect(component.feedbackType()).toBe('success');
    });

    it('shows the server message when a role change is refused', () => {
      actAs(1, 'Admin');
      fixture.detectChanges();
      httpMock.expectOne('/api/v1/users').flush({ success: true, data: [mk(3, 'Viewer')] });
      component.changeRole(mk(3, 'Viewer'), 'Owner');
      httpMock.expectOne('/api/v1/users/3/role').flush(
        { success: false, error: { message: 'Only an Owner can assign or change the Owner role.' } },
        { status: 403, statusText: 'Forbidden' },
      );
      httpMock.expectOne('/api/v1/users').flush({ success: true, data: [mk(3, 'Viewer')] });
      expect(component.feedbackType()).toBe('error');
      expect(component.feedbackMessage()).toContain('Only an Owner');
    });

    it('removes a member only after confirmation', () => {
      actAs(1, 'Admin');
      fixture.detectChanges();
      httpMock.expectOne('/api/v1/users').flush({ success: true, data: [mk(3, 'Viewer')] });
      component.askRemove(mk(3, 'Viewer'));
      httpMock.expectNone('/api/v1/users/3');
      component.confirmRemove();
      const req = httpMock.expectOne('/api/v1/users/3');
      expect(req.request.method).toBe('DELETE');
      req.flush({ success: true });
      httpMock.expectOne('/api/v1/users').flush({ success: true, data: [] });
      expect(component.removeTarget()).toBeNull();
      expect(component.feedbackType()).toBe('success');
    });
  });
});
