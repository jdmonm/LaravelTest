<?php

use App\Enums\LibraryRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the database seeder creates demo accounts and a catalogue', function () {
    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->where('email', 'admin@library.test')->sole();
    $librarian = User::query()->where('email', 'librarian@library.test')->sole();
    $member = User::query()->where('email', 'member@library.test')->sole();

    expect($admin->hasRole(LibraryRole::Admin))->toBeTrue()
        ->and($librarian->hasRole(LibraryRole::Librarian))->toBeTrue()
        ->and($member->hasRole(LibraryRole::Member))->toBeTrue();

    $this->assertDatabaseCount('books', 4);
});
