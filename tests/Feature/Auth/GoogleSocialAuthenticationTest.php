<?php

use App\Enums\LibraryRole;
use App\Models\SocialIdentity;
use App\Models\User;
use Database\Seeders\LibraryAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LibraryAuthorizationSeeder::class);
    config()->set('services.google.client_id', 'google-client-id');
    config()->set('services.google.client_secret', 'google-client-secret');
    config()->set('services.google.redirect', 'http://localhost/auth/google/callback');
    config()->set('services.google.workspace_domain', null);
});

function googleSocialiteUser(array $attributes = []): GoogleUser
{
    return GoogleUser::fake([
        'id' => 'google-subject-123',
        'name' => 'Google User',
        'email' => 'google.user@example.com',
        'email_verified' => true,
        ...$attributes,
    ]);
}

function beginGoogleSignIn(): void
{
    test()->get(route('auth.google.redirect'))
        ->assertRedirect('https://socialite.fake/google/authorize');
}

test('Google sign-in creates a state-protected authorization redirect', function () {
    $response = $this->get(route('auth.google.redirect'));
    $location = $response->headers->get('Location');
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://accounts.google.com/')
        ->and($query['state'] ?? null)->toBe(session('state'))
        ->and($query['client_id'] ?? null)->toBe('google-client-id')
        ->and($query['redirect_uri'] ?? null)->toBe('http://localhost/auth/google/callback');
});

test('Google sign-in rejects an invalid OAuth state before exchanging a code', function () {
    $this->get(route('auth.google.redirect'));

    $this->get(route('auth.google.callback', ['state' => 'invalid-state']))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Google sign-in could not be verified. Please try again.');
});

test('the login screen exposes Google sign-in only when it is configured', function () {
    $this->get(route('login'))
        ->assertInertia(fn ($page) => $page
            ->component('auth/Login')
            ->where('canSignInWithGoogle', true));

    config()->set('services.google.client_secret', null);

    $this->get(route('login'))
        ->assertInertia(fn ($page) => $page
            ->component('auth/Login')
            ->where('canSignInWithGoogle', false));
});

test('a Google account creates a member account and signs in', function () {
    Socialite::fake('google', googleSocialiteUser());

    beginGoogleSignIn();

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'google.user@example.com')->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->hasRole(LibraryRole::Member))->toBeTrue()
        ->and(SocialIdentity::query()->where([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-subject-123',
        ])->exists())->toBeTrue();
});

test('a linked Google identity signs in to its existing account', function () {
    $user = User::factory()->create();
    $user->assignRole(LibraryRole::Librarian);
    $user->socialIdentities()->create([
        'provider' => 'google',
        'provider_user_id' => 'google-subject-123',
    ]);
    Socialite::fake('google', googleSocialiteUser());

    beginGoogleSignIn();

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect(User::query()->count())->toBe(1)
        ->and($user->fresh()->hasRole(LibraryRole::Librarian))->toBeTrue();
});

test('Google sign-in does not link an existing local account by matching email', function () {
    User::factory()->create(['email' => 'google.user@example.com']);
    Socialite::fake('google', googleSocialiteUser());

    beginGoogleSignIn();

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'This Google account is not linked. Sign in with your existing account and connect Google from Security settings.');

    $this->assertGuest();
    expect(SocialIdentity::query()->count())->toBe(0);
});

test('Google sign-in requires a verified Google email', function () {
    Socialite::fake('google', googleSocialiteUser([
        'email_verified' => false,
    ]));

    beginGoogleSignIn();

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'This Google account cannot be used to sign in.');

    expect(User::query()->count())->toBe(0)
        ->and(SocialIdentity::query()->count())->toBe(0);
});

test('Google sign-in requires the configured Google Workspace domain', function () {
    config()->set('services.google.workspace_domain', 'library.test');
    Socialite::fake('google', googleSocialiteUser([
        'hd' => 'other.test',
    ]));

    beginGoogleSignIn();

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'This Google account cannot be used to sign in.');

    expect(User::query()->count())->toBe(0)
        ->and(SocialIdentity::query()->count())->toBe(0);
});

test('a linked Google account still requires two factor authentication', function () {
    Event::fake([TwoFactorAuthenticationChallenged::class]);
    $user = User::factory()->withTwoFactor()->create();
    $user->socialIdentities()->create([
        'provider' => 'google',
        'provider_user_id' => 'google-subject-123',
    ]);
    Socialite::fake('google', googleSocialiteUser());

    beginGoogleSignIn();

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id)
        ->assertSessionHas('login.remember', true);

    Event::assertDispatched(TwoFactorAuthenticationChallenged::class);
    $this->assertGuest();
});

test('an authenticated user can explicitly link Google after confirming their password', function () {
    $user = User::factory()->create();
    Socialite::fake('google', googleSocialiteUser());

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('auth.google.link'))
        ->assertRedirect('https://socialite.fake/google/authorize');

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('status', 'Google account linked.');

    $this->assertAuthenticatedAs($user);
    expect(SocialIdentity::query()->where([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-subject-123',
    ])->exists())->toBeTrue();
});

test('Google account linking expires after its password-confirmed callback window', function () {
    $user = User::factory()->create();
    Socialite::fake('google', googleSocialiteUser());

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('auth.google.link'));

    $this->withSession([
        'social-auth.google.link-expires-at' => time() - 1,
    ])->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Google account linking could not be completed. Please try again.');

    expect(SocialIdentity::query()->count())->toBe(0);
});

test('security settings report whether Google is linked', function () {
    $user = User::factory()->create();
    $user->socialIdentities()->create([
        'provider' => 'google',
        'provider_user_id' => 'google-subject-123',
    ]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn ($page) => $page
            ->component('settings/Security')
            ->where('canLinkGoogle', true)
            ->where('googleLinked', true));
});

test('Google linking requires a confirmed local session and cannot take another users identity', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->socialIdentities()->create([
        'provider' => 'google',
        'provider_user_id' => 'google-subject-123',
    ]);

    $this->actingAs($user)
        ->get(route('auth.google.link'))
        ->assertRedirect(route('password.confirm'));

    Socialite::fake('google', googleSocialiteUser());

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('auth.google.link'));

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('status', 'This Google account is already linked to another Library Desk account.');

    $this->assertAuthenticatedAs($user);
    expect(SocialIdentity::query()->count())->toBe(1);
});

test('Google sign-in is unavailable until credentials are configured', function () {
    config()->set('services.google.client_id', null);

    $this->get(route('auth.google.redirect'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Google sign-in is not configured yet.');
});
