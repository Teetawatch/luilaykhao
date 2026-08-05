<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * "ลืมรหัสผ่าน" — the way back into an account whose password is gone.
 *
 * Everything token-shaped is delegated to Laravel's password broker: it stores
 * a hash (never the token), expires it, and refuses to mint a second one inside
 * the throttle window. This controller only decides what the customer is told.
 */
class PasswordResetController extends Controller
{
    use ApiResponse;

    /**
     * The one message this endpoint ever returns on the happy path.
     *
     * Deliberately identical whether or not the address has an account: any
     * difference in wording, status code or timing turns this into a way to ask
     * "is this person a customer of yours?", which is exactly what the address
     * list of a booking site is worth stealing.
     */
    private const SENT_MESSAGE = 'ถ้าอีเมลนี้มีบัญชีอยู่ เราได้ส่งลิงก์ตั้งรหัสผ่านใหม่ไปให้แล้วครับ กรุณาตรวจสอบกล่องจดหมาย (รวมถึงอีเมลขยะ)';

    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = $this->findByEmail($validated['email']);

        if (! $user) {
            return $this->success(null, self::SENT_MESSAGE);
        }

        $status = Password::sendResetLink(['email' => $user->email]);

        // RESET_THROTTLED means a link went out moments ago and is still valid,
        // so from the customer's point of view the answer is the same: go look
        // in your inbox. Only a genuine send failure is worth surfacing.
        if (! in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return $this->error('ส่งลิงก์ไม่สำเร็จ กรุณาลองใหม่อีกครั้งครับ', 500);
        }

        return $this->success(null, self::SENT_MESSAGE);
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $this->findByEmail($validated['email']);

        if (! $user) {
            return $this->error('ลิงก์นี้หมดอายุหรือถูกใช้ไปแล้วครับ กรุณาขอลิงก์ใหม่อีกครั้ง', 422);
        }

        // Hand the broker the address exactly as stored, so its own lookup and
        // token comparison land on the same row this one did.
        $validated['email'] = $user->email;

        $status = Password::reset($validated, function (User $user, string $password) {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                // Choosing a password from a link we mailed proves the address
                // is theirs, so stop nagging them to verify it separately.
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            // Whoever was holding a session on this account — including whoever
            // prompted the reset — is logged out. A password reset is the tool
            // people reach for when they think someone else got in.
            $user->tokens()->delete();

            event(new PasswordReset($user));
        });

        if ($status === Password::PASSWORD_RESET) {
            return $this->success(null, 'ตั้งรหัสผ่านใหม่เรียบร้อยแล้วครับ เข้าสู่ระบบด้วยรหัสผ่านใหม่ได้เลย');
        }

        if ($status === Password::INVALID_TOKEN) {
            return $this->error('ลิงก์นี้หมดอายุหรือถูกใช้ไปแล้วครับ กรุณาขอลิงก์ใหม่อีกครั้ง', 422);
        }

        return $this->error('ตั้งรหัสผ่านใหม่ไม่สำเร็จ กรุณาขอลิงก์ใหม่อีกครั้งครับ', 422);
    }

    /**
     * Resolve the account by address without caring about letter case.
     *
     * Not just `where('email', strtolower(...))`: MySQL compares strings
     * case-insensitively but Postgres does not, and this app is headed for
     * Postgres — there, someone who signed up as "Somchai@…" would be told their
     * own address has no account. whereLike gives us ILIKE on Postgres, then the
     * exact comparison below throws out the rows LIKE matched on its wildcards
     * (an underscore in an address is one), so a reset link can never be aimed
     * at a neighbouring account.
     */
    private function findByEmail(string $email): ?User
    {
        $normalized = strtolower(trim($email));

        return User::query()
            ->whereLike('email', $normalized, caseSensitive: false)
            ->get()
            ->first(fn (User $user) => strtolower((string) $user->email) === $normalized);
    }
}
