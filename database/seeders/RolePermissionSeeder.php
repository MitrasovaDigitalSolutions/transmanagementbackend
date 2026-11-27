<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'vehicles.view', 'vehicles.create', 'vehicles.update', 'vehicles.delete',
            'vehicle-types.view', 'vehicle-types.create', 'vehicle-types.update', 'vehicle-types.delete',
            'crews.view', 'crews.create', 'crews.update', 'crews.delete',
            'schedules.view', 'schedules.create', 'schedules.update', 'schedules.delete',
            'users.view', 'users.create', 'users.update', 'users.delete',
            'roles.view', 'roles.create', 'roles.update', 'roles.delete',
            'media.view', 'media.update', 'media.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate('superadmin', 'web')->syncPermissions($permissions);

        $adminPermissions = array_values(array_filter(
            $permissions,
            fn (string $permission) => ! in_array($permission, ['roles.create', 'roles.update', 'roles.delete'], true)
        ));

        Role::findOrCreate('admin', 'web')->syncPermissions($adminPermissions);

        Role::findOrCreate('crew', 'web')->syncPermissions([
            'vehicles.view',
            'schedules.view',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
