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

class AuthController extends Controller
{
    use ApiResponse;

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
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

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => $user->roles->pluck('name'),
            'created_at' => $user->created_at?->toISOString(),
        ];
    }
}
