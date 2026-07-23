<?php

namespace App\Services;

use App\Enums\LibraryRole;
use App\Exceptions\SocialAuthenticationException;
use App\Models\SocialIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\User as GoogleUser;

class GoogleSocialAuthentication
{
    private const PROVIDER = 'google';

    /**
     * Find the account linked to a Google identity or create a new member account.
     *
     * Existing password accounts must explicitly link Google from Security settings.
     */
    public function resolveForSignIn(GoogleUser $googleUser): User
    {
        [$subject, $email, $name] = $this->eligibleIdentity($googleUser);

        return DB::transaction(function () use ($subject, $email, $name): User {
            $identity = SocialIdentity::query()
                ->with('user')
                ->where('provider', self::PROVIDER)
                ->where('provider_user_id', $subject)
                ->lockForUpdate()
                ->first();

            if ($identity instanceof SocialIdentity) {
                return $identity->user;
            }

            if (User::query()->where('email', $email)->exists()) {
                throw SocialAuthenticationException::googleAccountRequiresLinking();
            }

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Str::password(64),
            ]);
            $user->assignRole(LibraryRole::Member);
            $user->socialIdentities()->create([
                'provider' => self::PROVIDER,
                'provider_user_id' => $subject,
            ]);

            return $user;
        });
    }

    /**
     * Link Google to the already authenticated account after password confirmation.
     */
    public function link(User $user, GoogleUser $googleUser): void
    {
        [$subject] = $this->eligibleIdentity($googleUser);

        DB::transaction(function () use ($user, $subject): void {
            $identity = SocialIdentity::query()
                ->where('provider', self::PROVIDER)
                ->where('provider_user_id', $subject)
                ->lockForUpdate()
                ->first();

            if ($identity instanceof SocialIdentity && $identity->user_id !== $user->id) {
                throw SocialAuthenticationException::googleAccountAlreadyLinked();
            }

            $existingUserIdentity = $user->socialIdentities()
                ->where('provider', self::PROVIDER)
                ->lockForUpdate()
                ->first();

            if ($existingUserIdentity instanceof SocialIdentity) {
                if ($existingUserIdentity->provider_user_id !== $subject) {
                    throw SocialAuthenticationException::googleAccountAlreadyLinked();
                }

                return;
            }

            $user->socialIdentities()->create([
                'provider' => self::PROVIDER,
                'provider_user_id' => $subject,
            ]);
        });
    }

    /**
     * @return array{string, string, string}
     */
    private function eligibleIdentity(GoogleUser $googleUser): array
    {
        $subject = $googleUser->getId();
        $email = $googleUser->getEmail();
        $name = $googleUser->getName();
        $raw = $googleUser->getRaw();

        if (
            blank($subject)
            || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || data_get($raw, 'email_verified') !== true
        ) {
            throw SocialAuthenticationException::googleAccountIsNotEligible();
        }

        $allowedWorkspaceDomain = config('services.google.workspace_domain');

        if (
            is_string($allowedWorkspaceDomain) && filled($allowedWorkspaceDomain)
            && ! hash_equals($allowedWorkspaceDomain, (string) data_get($raw, 'hd'))
        ) {
            throw SocialAuthenticationException::googleAccountIsNotEligible();
        }

        return [
            $subject,
            Str::lower($email),
            filled($name) ? $name : $email,
        ];
    }
}
