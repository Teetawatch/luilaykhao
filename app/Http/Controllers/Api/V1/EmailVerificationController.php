<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MailService;
use App\Traits\ApiResponse;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Email verification.
 *
 * Nothing is gated behind being verified today — an unverified customer can
 * still browse, book and pay. What verification buys is a trustworthy recovery
 * path: PasswordResetController will only mail a reset link to the address on
 * the account, so an address nobody ever proved is an account nobody can get
 * back. Treat this as making that promise good, not as a wall.
 */
class EmailVerificationController extends Controller
{
    use ApiResponse;

    public function __construct(private MailService $mailService) {}

    /**
     * Land the customer back in the SPA after clicking the link in their inbox.
     *
     * Signature is checked by hand rather than with the `signed` middleware so a
     * link that expired sitting in an inbox for three days ends on a page that
     * offers to send another one, instead of a bare 403.
     */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return $this->backToApp('expired');
        }

        $user = User::find($id);

        // The hash pins the link to the address it was mailed to: change the
        // email on the account and every link already sent to the old one dies.
        if (! $user || ! hash_equals(sha1((string) $user->getEmailForVerification()), $hash)) {
            return $this->backToApp('invalid');
        }

        if ($user->hasVerifiedEmail()) {
            return $this->backToApp('already');
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return $this->backToApp('success');
    }

    /**
     * Send another verification link to the signed-in customer's own address.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $this->success(null, 'อีเมลนี้ยืนยันเรียบร้อยแล้วครับ');
        }

        // Placeholder addresses minted for social accounts that gave us no email
        // (see AuthController::findOrCreateSocialUser) can never receive mail.
        if (str_ends_with(strtolower((string) $user->email), '@social.local')) {
            return $this->error('บัญชีนี้ยังไม่มีอีเมลจริง กรุณาเพิ่มอีเมลในหน้าโปรไฟล์ก่อนครับ', 422);
        }

        $this->mailService->sendEmailVerificationEmail($user);

        return $this->success(null, 'ส่งลิงก์ยืนยันไปที่อีเมลของคุณแล้วครับ');
    }

    private function backToApp(string $status): RedirectResponse
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return redirect()->away($base.'/profile?verified='.$status);
    }
}
