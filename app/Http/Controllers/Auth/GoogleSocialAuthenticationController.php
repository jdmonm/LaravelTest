<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\SocialAuthenticationException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleSocialAuthentication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Socialite\Exceptions\DriverMissingConfigurationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

class GoogleSocialAuthenticationController extends Controller
{
    private const INTENT_SESSION_KEY = 'social-auth.google.intent';

    private const LINK_USER_ID_SESSION_KEY = 'social-auth.google.link-user-id';

    private const LINK_EXPIRES_AT_SESSION_KEY = 'social-auth.google.link-expires-at';

    /**
     * Start a state-protected Google OAuth flow.
     */
    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->isConfigured()) {
            return to_route('login')->with('status', 'Google sign-in is not configured yet.');
        }

        $intent = $request->routeIs('auth.google.link') ? 'link' : 'sign-in';

        if ($intent === 'link' && ! $request->user() instanceof User) {
            abort(401);
        }

        $request->session()->put(self::INTENT_SESSION_KEY, $intent);

        if ($intent === 'link') {
            $request->session()->put([
                self::LINK_USER_ID_SESSION_KEY => $request->user()->getKey(),
                self::LINK_EXPIRES_AT_SESSION_KEY => now()->addMinutes(10)->getTimestamp(),
            ]);
        } else {
            $request->session()->forget([
                self::LINK_USER_ID_SESSION_KEY,
                self::LINK_EXPIRES_AT_SESSION_KEY,
            ]);
        }

        return Socialite::driver('google')->redirect();
    }

    /**
     * Complete a state-protected Google OAuth flow.
     */
    public function callback(Request $request, GoogleSocialAuthentication $googleAuthentication): RedirectResponse
    {
        $intent = $request->session()->pull(self::INTENT_SESSION_KEY);
        $linkUserId = $request->session()->pull(self::LINK_USER_ID_SESSION_KEY);
        $linkExpiresAt = $request->session()->pull(self::LINK_EXPIRES_AT_SESSION_KEY);

        if (! in_array($intent, ['sign-in', 'link'], true)) {
            return to_route('login')->with('status', 'Google sign-in could not be completed. Please try again.');
        }

        try {
            $googleUser = Socialite::driver('google')->user();

            if (! $googleUser instanceof GoogleUser) {
                throw SocialAuthenticationException::googleAccountIsNotEligible();
            }

            if ($intent === 'link') {
                $user = $request->user();

                if (
                    ! $user instanceof User
                    || $user->getKey() !== $linkUserId
                    || ! is_int($linkExpiresAt)
                    || $linkExpiresAt < time()
                ) {
                    return to_route('login')->with('status', 'Google account linking could not be completed. Please try again.');
                }

                $googleAuthentication->link($user, $googleUser);

                return to_route('security.edit')->with('status', 'Google account linked.');
            }

            $user = $googleAuthentication->resolveForSignIn($googleUser);

            if ($user->hasEnabledTwoFactorAuthentication()) {
                $request->session()->put([
                    'login.id' => $user->getKey(),
                    'login.remember' => true,
                ]);
                TwoFactorAuthenticationChallenged::dispatch($user);

                return to_route('two-factor.login');
            }

            Auth::login($user, true);
            $request->session()->regenerate();
            $request->session()->put('auth.password_confirmed_at', time());

            return redirect()->intended(route('dashboard', absolute: false));
        } catch (SocialAuthenticationException $exception) {
            return $this->failedAuthentication($request, $intent, $exception->getMessage());
        } catch (InvalidStateException) {
            return $this->failedAuthentication($request, $intent, 'Google sign-in could not be verified. Please try again.');
        } catch (DriverMissingConfigurationException) {
            return $this->failedAuthentication($request, $intent, 'Google sign-in is not configured yet.');
        } catch (Throwable $exception) {
            report($exception);

            return $this->failedAuthentication($request, $intent, 'Google sign-in is temporarily unavailable. Please try again.');
        }
    }

    private function failedAuthentication(Request $request, string $intent, string $message): RedirectResponse
    {
        if ($intent === 'link' && $request->user() instanceof User) {
            return to_route('security.edit')->with('status', $message);
        }

        return to_route('login')->with('status', $message);
    }

    private function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }
}
