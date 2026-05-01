<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\MailService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private MailService $mailService,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'title' => $request->title,
            'nickname' => $request->nickname,
            'blood_group' => $request->blood_group,
            'email' => $request->email,
            'phone' => $request->phone,
            'id_card' => $request->id_card,
            'emergency_contact' => $request->emergency_contact,
            'emergency_phone' => $request->emergency_phone,
            'allergies' => $request->allergies,
            'health_notes' => $request->health_notes,
            'password' => Hash::make($request->password),
        ]);

        $user->assignRole('customer');
        $user->load('roles');

        $token = $user->createToken('auth-token')->plainTextToken;

        // Send welcome email
        $this->mailService->sendWelcomeEmail($user);

        return $this->success([
            'user' => $this->formatUser($user),
            'token' => $token,
        ], 'ลงทะเบียนสำเร็จ', 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->error('อีเมลหรือรหัสผ่านไม่ถูกต้อง', 401);
        }

        $user->load('roles');
        $token = $user->createToken('auth-token')->plainTextToken;

        return $this->success([
            'user' => $this->formatUser($user),
            'token' => $token,
        ], 'เข้าสู่ระบบสำเร็จ');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'ออกจากระบบสำเร็จ');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('roles');

        return $this->success($this->formatUser($user));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'min:6', 'confirmed'],
            'avatar' => ['nullable', 'image', 'max:5120'], // 5MB
            'title' => ['nullable', 'string', 'max:20'],
            'nickname' => ['nullable', 'string', 'max:255'],
            'id_card' => ['nullable', 'string', 'digits:13'],
            'blood_group' => ['nullable', 'string', 'max:10'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'emergency_phone' => ['nullable', 'string', 'max:255'],
            'allergies' => ['nullable', 'string'],
            'health_notes' => ['nullable', 'string'],
        ]);

        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }
        if (isset($validated['phone'])) {
            $user->phone = $validated['phone'];
        }
        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        // Additional profile fields
        foreach (['title', 'nickname', 'id_card', 'blood_group', 'emergency_contact', 'emergency_phone', 'allergies', 'health_notes'] as $field) {
            if (array_key_exists($field, $validated)) {
                $user->$field = $validated[$field];
            }
        }

        if ($request->hasFile('avatar')) {
            // Delete old avatar if exists
            if ($user->avatar && file_exists(public_path($user->avatar))) {
                @unlink(public_path($user->avatar));
            }

            $file = $request->file('avatar');
            $filename = time() . '_avatar_' . $user->id . '.' . $file->getClientOriginalExtension();
            
            // Ensure avatars directory exists
            if (!file_exists(public_path('avatars'))) {
                mkdir(public_path('avatars'), 0755, true);
            }
            
            $file->move(public_path('avatars'), $filename);
            $user->avatar = '/avatars/' . $filename;
        }

        $user->save();
        $user->load('roles');

        return $this->success($this->formatUser($user->fresh()), 'อัปเดตโปรไฟล์สำเร็จ');
    }


    public function socialRedirect(Request $request, string $provider): \Illuminate\Http\RedirectResponse
    {
        $this->validateProvider($provider);

        $driver = Socialite::driver($provider)->stateless();
        $state = $this->makeSocialState($request->query('return_to'));

        if ($state) {
            $driver = $driver->with([
                'state' => $state,
            ]);
        }

        $redirectResponse = $driver->redirect();

        \Log::info('Social Redirect URL', [
            'provider' => $provider,
            'url' => $redirectResponse->getTargetUrl(),
        ]);

        return $redirectResponse;
    }

    public function socialCallback(Request $request, string $provider): \Illuminate\Http\RedirectResponse
    {
        $this->validateProvider($provider);

        $frontendUrl = env('FRONTEND_URL', config('app.url'));
        $mobileReturnTo = $this->mobileReturnToFromState($request->get('state'));

        \Log::info('Social Callback params', [
            'provider' => $provider,
            'params' => $request->all(),
            'url' => $request->fullUrl(),
        ]);

        // Handle error from provider (e.g. user cancelled)
        if ($request->has('error')) {
            $errorCode = (string) $request->get('error');
            $errorMessage = $request->get('error_description') ?: $request->get('error_message') ?: $request->get('error');
            $isCancelled = in_array($errorCode, ['access_denied', 'user_cancelled_login'], true);
            $errorType = $isCancelled ? 'social_auth_cancelled' : 'social_auth_failed';

            return $this->redirectSocialError($frontendUrl, $mobileReturnTo, $errorType, $errorMessage);
        }

        // Check if code is present
        if (!$request->has('code')) {
            return $this->redirectSocialError($frontendUrl, $mobileReturnTo, 'social_auth_failed', 'ไม่พบรหัสยืนยันตัวตน กรุณาลองใหม่อีกครั้ง');
        }

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Exception $e) {
            \Log::error('Social Login Error: ' . $e->getMessage(), [
                'provider' => $provider,
                'trace' => $e->getTraceAsString()
            ]);
            
            $message = $e->getMessage();
            // Simplify Guzzle error messages for the user if they contain JSON strings
            if (str_contains($message, '{')) {
                $message = 'เกิดข้อผิดพลาดในการเชื่อมต่อกับผู้ให้บริการภายนอก';
            }
            
            return $this->redirectSocialError($frontendUrl, $mobileReturnTo, 'social_auth_failed', $message);
        }

        try {
            $socialId = (string) $socialUser->getId();
            $email = $socialUser->getEmail();

            if (!$email) {
                $sanitizedSocialId = preg_replace('/[^a-zA-Z0-9]/', '', $socialId) ?: Str::random(12);
                $email = $provider . '_' . $sanitizedSocialId . '@social.local';
            }

            $user = User::where('social_provider', $provider)
                ->where('social_id', $socialId)
                ->first();

            if (!$user) {
                if ($socialUser->getEmail()) {
                    $user = User::where('email', $socialUser->getEmail())->first();
                }

                if ($user) {
                    $user->update([
                        'social_provider' => $provider,
                        'social_id' => $socialId,
                        'avatar' => $user->avatar ?: $socialUser->getAvatar(),
                    ]);
                } else {
                    $user = User::create([
                        'name' => $socialUser->getName() ?: ($socialUser->getNickname() ?: 'User'),
                        'email' => $email,
                        'social_provider' => $provider,
                        'social_id' => $socialId,
                        'avatar' => $socialUser->getAvatar(),
                        'password' => null,
                    ]);
                    $user->assignRole('customer');

                    // Send welcome email for new social users
                    $this->mailService->sendWelcomeEmail($user);
                }
            }

            $user->load('roles');
            $token = $user->createToken('auth-token')->plainTextToken;

            return $this->redirectSocialSuccess($frontendUrl, $mobileReturnTo, $token, $this->formatUser($user));
        } catch (\Throwable $e) {
            \Log::error('Social user processing error: ' . $e->getMessage(), [
                'provider' => $provider,
                'social_id' => $socialUser->getId(),
                'email' => $socialUser->getEmail(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->redirectSocialError($frontendUrl, $mobileReturnTo, 'social_auth_failed', 'เกิดข้อผิดพลาดในการเข้าสู่ระบบ กรุณาลองใหม่อีกครั้ง');
        }
    }

    private function makeSocialState(?string $returnTo): string
    {
        $payload = [
            'nonce' => Str::random(24),
        ];

        if ($returnTo && $this->isAllowedMobileReturnTo($returnTo)) {
            $payload['return_to'] = $returnTo;
        }

        return rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    }

    private function mobileReturnToFromState(mixed $state): ?string
    {
        if (!is_string($state) || $state === '') {
            return null;
        }

        $encoded = strtr($state, '-_', '+/');
        $padding = strlen($encoded) % 4;
        if ($padding > 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }

        $json = base64_decode($encoded, true);
        if ($json === false) {
            return null;
        }

        $payload = json_decode($json, true);
        $returnTo = is_array($payload) ? ($payload['return_to'] ?? null) : null;

        return is_string($returnTo) && $this->isAllowedMobileReturnTo($returnTo)
            ? $returnTo
            : null;
    }

    private function isAllowedMobileReturnTo(string $url): bool
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? null) === 'luilaykhao'
            && ($parts['host'] ?? null) === 'auth'
            && ($parts['path'] ?? null) === '/social/callback';
    }

    private function redirectSocialSuccess(
        string $frontendUrl,
        ?string $mobileReturnTo,
        string $token,
        array $user,
    ): \Illuminate\Http\RedirectResponse {
        $baseUrl = $mobileReturnTo ?: rtrim($frontendUrl, '/') . '/auth/social/callback';

        return $this->redirectWithQuery($baseUrl, [
            'token' => $token,
            'user' => json_encode($user),
        ]);
    }

    private function redirectSocialError(
        string $frontendUrl,
        ?string $mobileReturnTo,
        string $error,
        mixed $message,
    ): \Illuminate\Http\RedirectResponse {
        $baseUrl = $mobileReturnTo ?: rtrim($frontendUrl, '/') . '/login';

        return $this->redirectWithQuery($baseUrl, [
            'error' => $error,
            'message' => (string) $message,
        ]);
    }

    private function redirectWithQuery(string $baseUrl, array $params): \Illuminate\Http\RedirectResponse
    {
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return redirect($baseUrl . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }

    private function validateProvider(string $provider): void
    {
        if (!in_array($provider, ['google', 'facebook', 'line'])) {
            abort(422, 'ผู้ให้บริการ OAuth ไม่รองรับ');
        }
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar_url' => $user->avatar ? url(ltrim($user->avatar, '/')) : null,
            'title' => $user->title,
            'nickname' => $user->nickname,
            'id_card' => $user->id_card,
            'blood_group' => $user->blood_group,
            'emergency_contact' => $user->emergency_contact,
            'emergency_phone' => $user->emergency_phone,
            'allergies' => $user->allergies,
            'health_notes' => $user->health_notes,
            'roles' => $user->roles->pluck('name'),
            'social_provider' => $user->social_provider,
            'created_at' => $user->created_at?->toISOString(),
        ];
    }

}
