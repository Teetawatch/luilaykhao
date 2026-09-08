<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\User;
use App\Services\AccountClaimService;
use App\Support\PhoneNumber;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * "ทีมงานจองให้ แต่การจองไม่ขึ้นในแอป" — ปลายทางของทั้งสองประตูใน
 * [AccountClaimService] ทั้งฝั่งลูกค้าที่ล็อกอินแล้ว และฝั่งลิงก์เปิดใช้บัญชี
 */
class AccountClaimController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AccountClaimService $claims) {}

    /**
     * GET /me/claimable-bookings
     *
     * ตอบแค่ "มีกี่ใบ" กับชื่อทริป — เบอร์ที่กรอกตอนสมัครไม่เคยถูกยืนยัน จึงยัง
     * ไม่ใช่หลักฐานพอที่จะเห็นรายละเอียดใบจอง (ในนั้นมีเลขบัตรและข้อมูลสุขภาพ
     * ของผู้เดินทางทุกคน) หน้าที่ของ endpoint นี้คือชวนให้กดยืนยันเท่านั้น
     */
    public function index(Request $request): JsonResponse
    {
        $bookings = $this->claims->claimableFor($request->user());

        return $this->success([
            'count' => $bookings->count(),
            'trips' => $bookings
                ->map(fn (Booking $booking) => [
                    'trip_title' => $booking->schedule?->trip?->title,
                    'departure_date' => $booking->schedule?->departure_date?->toDateString(),
                ])
                ->values(),
        ]);
    }

    /**
     * POST /bookings/claim — เลขที่จอง + เบอร์ (4 ตัวท้ายก็พอ)
     */
    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'booking_ref' => ['required', 'string', 'max:40'],
            'phone' => ['required', 'string', 'min:4', 'max:20'],
        ], [
            'booking_ref.required' => 'กรุณากรอกเลขที่การจอง',
            'phone.required' => 'กรุณากรอกเบอร์โทรที่ใช้จอง',
            'phone.min' => 'กรอกเบอร์โทรอย่างน้อย 4 ตัวท้าย',
        ]);

        try {
            $booking = $this->claims->claimByReference($request->user(), $data['booking_ref'], $data['phone']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $booking->load(['schedule.trip', 'passengers', 'pickupPoint']);

        return $this->success(
            new BookingResource($booking),
            'ผูกการจองเข้าบัญชีของคุณแล้ว',
        );
    }

    /**
     * GET /account/claim/{token} — พรีวิวก่อนตั้งรหัสผ่าน (ใช้โดยหน้าเว็บ /claim)
     */
    public function preview(string $token): JsonResponse
    {
        $shadow = $this->claims->resolveClaimToken($token);

        if (! $shadow) {
            return $this->error('ลิงก์นี้ใช้ไม่ได้แล้ว อาจถูกใช้ไปแล้วหรือหมดอายุ', 404);
        }

        return $this->success($this->previewPayload($shadow));
    }

    /**
     * POST /account/claim/{token}
     *
     * ปลายทางเดียวสองทางเดิน: ยังไม่มีบัญชี → ตั้งรหัสผ่านทับบัญชีเงาไปเลย,
     * มีบัญชีอยู่แล้ว → พิสูจน์ด้วยรหัสผ่านของบัญชีนั้นแล้วย้ายใบจองมารวม
     */
    public function activate(Request $request, string $token): JsonResponse
    {
        $shadow = $this->claims->resolveClaimToken($token);

        if (! $shadow) {
            return $this->error('ลิงก์นี้ใช้ไม่ได้แล้ว อาจถูกใช้ไปแล้วหรือหมดอายุ', 404);
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'name' => ['nullable', 'string', 'max:255'],
        ], [
            'email.required' => 'กรุณากรอกอีเมล',
            'password.required' => 'กรุณาตั้งรหัสผ่าน',
            'password.min' => 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร',
        ]);

        $email = mb_strtolower(trim($data['email']));
        $existing = User::where('email', $email)->where('id', '!=', $shadow->id)->first();

        if ($existing) {
            // บัญชีที่มีอยู่แล้วต้องพิสูจน์ว่าเป็นของคนที่ถือลิงก์จริง ไม่งั้นคนที่ได้
            // ลิงก์หลุดมาจะยัดใบจองเข้าอีเมลของใครก็ได้
            if (! $existing->password || ! Hash::check($data['password'], $existing->password)) {
                return $this->error('อีเมลนี้มีบัญชีอยู่แล้ว กรุณากรอกรหัสผ่านของบัญชีเดิมเพื่อรับการจอง', 422);
            }

            $moved = $this->claims->mergeInto($existing, $shadow);

            return $this->success([
                'user' => ['id' => $existing->id, 'name' => $existing->name, 'email' => $existing->email],
                'token' => $existing->createToken('auth-token')->plainTextToken,
                'bookings_moved' => $moved,
                'merged' => true,
            ], 'ย้ายการจอง '.$moved.' รายการเข้าบัญชีของคุณแล้ว');
        }

        try {
            $user = $this->claims->activate($shadow, $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'token' => $user->createToken('auth-token')->plainTextToken,
            'bookings_moved' => $this->claims->bookingsOf($user)->count(),
            'merged' => false,
        ], 'เปิดใช้บัญชีเรียบร้อยแล้ว');
    }

    /**
     * ข้อมูลที่ปลอดภัยพอจะโชว์ให้คนที่ถือลิงก์เห็น — ชื่อทริปกับวันเดินทางพอให้รู้ว่า
     * ลิงก์นี้เป็นของจริง แต่ไม่มีรายชื่อผู้เดินทาง เบอร์ หรือยอดเงิน
     *
     * @return array<string, mixed>
     */
    private function previewPayload(User $shadow): array
    {
        return [
            'name' => $shadow->name,
            'phone_hint' => PhoneNumber::last4($shadow->phone),
            'bookings' => $this->claims->bookingsOf($shadow)
                ->map(fn (Booking $booking) => [
                    'booking_ref' => $booking->booking_ref,
                    'trip_title' => $booking->schedule?->trip?->title,
                    'departure_date' => $booking->schedule?->departure_date?->toDateString(),
                    'status' => $booking->status,
                ])
                ->values(),
        ];
    }
}
