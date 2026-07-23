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

test('the database seeder skips demo data outside local and testing', function () {
    $existingUser = User::factory()->create(['email' => 'admin@library.test']);
    $environment = app()->environment();

    app()->instance('env', 'production');

    try {
        app(DatabaseSeeder::class)->setContainer(app())->run();
    } finally {
        app()->instance('env', $environment);
    }

    expect($existingUser->fresh()->getRoleNames())->toBeEmpty();

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('books', 0);
});
