<?php

namespace Database\Seeders;

use App\Enums\LibraryPermission;
use App\Enums\LibraryRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class LibraryAuthorizationSeeder extends Seeder
{
    /**
     * Seed the roles and permissions used by the library.
     */
    public function run(): void
    {
        $permissionRegistrar = app(PermissionRegistrar::class);
        $permissionRegistrar->forgetCachedPermissions();

        foreach (LibraryPermission::cases() as $permission) {
            Permission::findOrCreate($permission->value);
        }

        foreach (
            [
                LibraryRole::Admin->value => LibraryPermission::cases(),
                LibraryRole::Librarian->value => [
                    LibraryPermission::ViewBooks,
                    LibraryPermission::CreateBooks,
                    LibraryPermission::UpdateBooks,
                    LibraryPermission::DeleteBooks,
                    LibraryPermission::ViewLoans,
                    LibraryPermission::ManageLoans,
                    LibraryPermission::ViewMembers,
                    LibraryPermission::ManageMembers,
                    LibraryPermission::ViewReports,
                ],
                LibraryRole::Member->value => [
                    LibraryPermission::ViewBooks,
                    LibraryPermission::ViewOwnLoans,
                ],
            ] as $roleName => $permissions
        ) {
            $role = Role::findOrCreate($roleName);

            $role->syncPermissions(array_map(
                static fn (LibraryPermission $permission): string => $permission->value,
                $permissions,
            ));
        }

        $permissionRegistrar->forgetCachedPermissions();
    }
}
