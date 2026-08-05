<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * The two links that let someone back into their own account.
 *
 * Both are minted in more than one place (register, resend, the forgot-password
 * broker), and both are only as safe as the thing that builds them — a reset URL
 * pointed at the wrong host hands the token to the wrong site. So the shape of
 * each lives here once.
 */
class AccountLinks
{
    /** How long a "verify your email" link stays good for. */
    public const VERIFY_TTL_HOURS = 48;

    /**
     * Where the customer lands to choose a new password. This is a page in the
     * Vue SPA, not an API route — it reads token+email off the query string and
     * posts them back to /auth/reset-password.
     */
    public static function resetPassword(string $token, string $email): string
    {
        return self::frontend().'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $email,
        ]);
    }

    /**
     * A signed, expiring link to the backend verification route. Signed rather
     * than random-token: nothing has to be stored, and Laravel refuses a URL
     * whose id/hash pair has been edited. The hash is the framework's own
     * sha1(email) so User::hasVerifiedEmail() semantics stay standard.
     */
    public static function verifyEmail(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addHours(self::VERIFY_TTL_HOURS),
            [
                'id' => $user->getKey(),
                'hash' => sha1((string) $user->getEmailForVerification()),
            ],
        );
    }

    private static function frontend(): string
    {
        return rtrim((string) config('app.frontend_url', config('app.url')), '/');
    }
}
