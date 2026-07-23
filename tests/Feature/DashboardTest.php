<?php

use App\Enums\LibraryRole;
use App\Models\User;
use Database\Seeders\LibraryAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('the home page redirects into the application', function () {
    $this->get(route('home'))->assertRedirect(route('dashboard'));
});

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('the dashboard receives the authenticated user authorization context', function () {
    $this->seed(LibraryAuthorizationSeeder::class);

    $user = User::factory()->create();
    $user->assignRole(LibraryRole::Librarian);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn(Assert $page) => $page
            ->component('Dashboard')
            ->has('auth.roles', 1)
            ->where('auth.roles.0', LibraryRole::Librarian->value)
            ->has('auth.permissions', 9));
});
