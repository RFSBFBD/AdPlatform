<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Core permissions
        $permissions = [
            'view-analytics',
            'export-reports',
            'manage-accounts',
            'sync-campaigns',
            'manage-billing',
            'manage-developers',
            'manage-users',
            'manage-ai',
        ];

        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, 'web');
        }

        // Roles definition
        $ownerRole = Role::findOrCreate('Owner', 'web');
        $ownerRole->syncPermissions(Permission::all());

        $adminRole = Role::findOrCreate('Admin', 'web');
        $adminRole->syncPermissions([
            'view-analytics',
            'export-reports',
            'manage-accounts',
            'sync-campaigns',
            'manage-developers',
            'manage-users',
            'manage-ai',
        ]);

        $analystRole = Role::findOrCreate('Analyst', 'web');
        $analystRole->syncPermissions([
            'view-analytics',
            'export-reports',
            'sync-campaigns',
        ]);

        $viewerRole = Role::findOrCreate('Viewer', 'web');
        $viewerRole->syncPermissions([
            'view-analytics',
        ]);

        // Bootstrap owner. There is no built-in password: it comes from ADMIN_BOOTSTRAP_PASSWORD.
        // In production, without that variable no owner is created. Elsewhere a random one is generated
        // (reset it with the password-reset flow).
        $bootstrapPassword = env('ADMIN_BOOTSTRAP_PASSWORD');
        if (!$bootstrapPassword && app()->environment('production')) {
            return;
        }

        $owner = User::firstOrCreate(
            ['email' => env('ADMIN_BOOTSTRAP_EMAIL', 'admin@adplatform.local')],
            [
                'name' => 'System Owner',
                'password' => Hash::make($bootstrapPassword ?: \Illuminate\Support\Str::random(32)),
                'status' => 'active',
            ]
        );

        if (!$owner->hasRole('Owner')) {
            $owner->assignRole('Owner');
        }
    }
}
