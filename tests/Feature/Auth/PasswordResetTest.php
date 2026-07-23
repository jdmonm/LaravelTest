<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('password reset requests have the same public response for unknown email addresses', function () {
    $user = User::factory()->create();

    $knownEmailResponse = $this->post(route('password.email'), ['email' => $user->email]);
    $unknownEmailResponse = $this->post(route('password.email'), ['email' => 'unknown@example.com']);

    $knownEmailResponse
        ->assertRedirect()
        ->assertSessionHas('status', trans('passwords.sent'));
    $unknownEmailResponse
        ->assertRedirect()
        ->assertSessionHas('status', trans('passwords.sent'));
});

test('password reset requests are rate limited by email and IP address', function () {
    $payload = ['email' => 'reset-limit@example.com'];

    $this->post(route('password.email'), $payload)->assertRedirect();
    $this->post(route('password.email'), $payload)->assertRedirect();
    $this->post(route('password.email'), $payload)->assertRedirect();
    $this->post(route('password.email'), $payload)->assertStatus(429);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('password resets invalidate sessions with an outdated password hash', function () {
    Notification::fake();

    $user = User::factory()->create();
    $oldPasswordHash = $user->password;

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertRedirect(route('login'));

        return true;
    });

    $this->actingAs($user->refresh())
        ->withSession(['password_hash_web' => $oldPasswordHash])
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('password cannot be reset with invalid token', function () {
    $user = User::factory()->create();

    $response = $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertSessionHasErrors('email');
});
