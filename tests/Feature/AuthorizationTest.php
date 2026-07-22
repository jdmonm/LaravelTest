<?php

use App\Enums\LibraryPermission;
use App\Enums\LibraryRole;
use App\Models\User;
use Database\Seeders\LibraryAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('users receive permissions through their library role', function () {
    $permission = Permission::findOrCreate('books.create');
    $librarian = Role::findOrCreate('librarian');
    $librarian->givePermissionTo($permission);

    $user = User::factory()->create();
    $user->assignRole($librarian);

    expect($user->hasRole('librarian'))->toBeTrue()
        ->and($user->can('books.create'))->toBeTrue()
        ->and($user->can('books.delete'))->toBeFalse();
});

test('the library authorization seeder grants the intended capabilities', function () {
    $this->seed(LibraryAuthorizationSeeder::class);

    $admin = Role::findByName(LibraryRole::Admin->value);
    $librarian = Role::findByName(LibraryRole::Librarian->value);
    $member = Role::findByName(LibraryRole::Member->value);

    expect($admin->hasPermissionTo(LibraryPermission::ManageAccess->value))->toBeTrue()
        ->and($librarian->hasPermissionTo(LibraryPermission::DeleteBooks->value))->toBeTrue()
        ->and($librarian->hasPermissionTo(LibraryPermission::ManageAccess->value))->toBeFalse()
        ->and($member->hasPermissionTo(LibraryPermission::ViewBooks->value))->toBeTrue()
        ->and($member->hasPermissionTo(LibraryPermission::ManageLoans->value))->toBeFalse();
});
