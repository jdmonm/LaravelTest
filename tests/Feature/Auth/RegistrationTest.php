<?php

use App\Actions\Fortify\CreateNewUser;
use App\Enums\LibraryRole;
use App\Models\User;
use Database\Seeders\LibraryAuthorizationSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->seed(LibraryAuthorizationSeeder::class);
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    Notification::fake();

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    expect(User::query()->where('email', 'test@example.com')->sole()->hasRole(LibraryRole::Member))
        ->toBeTrue();

    Notification::assertSentTo(
        User::query()->where('email', 'test@example.com')->sole(),
        VerifyEmail::class,
    );
});

test('registration rolls back when the member role is unavailable', function () {
    Role::query()->where('name', LibraryRole::Member->value)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(fn () => app(CreateNewUser::class)->create([
        'name' => 'Missing Role User',
        'email' => 'missing-role@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]))->toThrow(RoleDoesNotExist::class);

    expect(User::query()->where('email', 'missing-role@example.com')->exists())->toBeFalse();
});

test('registration requests are rate limited by IP address', function () {
    $payload = [
        'name' => 'Rate Limited User',
        'email' => 'invalid-email',
        'password' => 'password',
        'password_confirmation' => 'password',
    ];

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post(route('register.store'), $payload)->assertSessionHasErrors('email');
    }

    $this->post(route('register.store'), $payload)->assertStatus(429);
});
