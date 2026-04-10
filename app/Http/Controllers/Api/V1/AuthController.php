<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    use ApiResponse;

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'title' => $request->title,
            'nickname' => $request->nickname,
            'blood_group' => $request->blood_group,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
        ]);

        $user->assignRole('customer');
        $user->load('roles');

        $token = $user->createToken('auth-token')->plainTextToken;

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


    public function socialRedirect(string $provider): \Illuminate\Http\RedirectResponse
    {
        $this->validateProvider($provider);

        return Socialite::driver($provider)->stateless()->redirect();
    }

    public function socialCallback(Request $request, string $provider): \Illuminate\Http\RedirectResponse
    {
        $this->validateProvider($provider);

        $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Exception $e) {
            return redirect($frontendUrl . '/login?error=social_auth_failed');
        }

        $user = User::where('social_provider', $provider)
            ->where('social_id', $socialUser->getId())
            ->first();

        if (!$user) {
            $user = User::where('email', $socialUser->getEmail())->first();

            if ($user) {
                $user->update([
                    'social_provider' => $provider,
                    'social_id' => $socialUser->getId(),
                    'avatar' => $user->avatar ?: $socialUser->getAvatar(),
                ]);
            } else {
                $user = User::create([
                    'name' => $socialUser->getName() ?: ($socialUser->getNickname() ?: 'User'),
                    'email' => $socialUser->getEmail(),
                    'social_provider' => $provider,
                    'social_id' => $socialUser->getId(),
                    'avatar' => $socialUser->getAvatar(),
                    'password' => null,
                ]);
                $user->assignRole('customer');
            }
        }

        $user->load('roles');
        $token = $user->createToken('auth-token')->plainTextToken;

        return redirect($frontendUrl . '/auth/social/callback?token=' . $token . '&user=' . urlencode(json_encode($this->formatUser($user))));
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
            'created_at' => $user->created_at?->toISOString(),
        ];
    }

}
