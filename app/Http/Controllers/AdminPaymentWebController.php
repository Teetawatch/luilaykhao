<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\User;
use App\Services\OutstandingPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * หน้าเว็บ admin (มี login) สำหรับดูรายการลูกค้าที่ยังค้างชำระ
 * และส่งลิงก์ชำระเงินให้รายคน — ใช้ session guard 'web' จำกัดเฉพาะ admin/operator
 */
class AdminPaymentWebController extends Controller
{
    public function __construct(
        private OutstandingPaymentService $outstandingPaymentService,
    ) {}

    public function index(Request $request): View
    {
        if (! $this->authedAdmin()) {
            return view('admin.payments.login');
        }

        $rows = $this->outstandingPaymentService->rows(
            $request->integer('schedule_id') ?: null,
            trim((string) $request->query('search')) ?: null,
        );

        return view('admin.payments.index', [
            'rows' => $rows,
            'totalDue' => round((float) $rows->sum('amount_due'), 2),
            'search' => (string) $request->query('search', ''),
            'scheduleId' => $request->query('schedule_id'),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attempt($credentials)) {
            return back()->withErrors(['email' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'])->onlyInput('email');
        }

        $user = Auth::guard('web')->user();
        if (! $user instanceof User || ! $user->hasAnyRole(['admin', 'operator'])) {
            Auth::guard('web')->logout();

            return back()->withErrors(['email' => 'บัญชีนี้ไม่มีสิทธิ์เข้าถึง'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->route('admin.payments.index');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.payments.index');
    }

    public function sendLink(Request $request, string $ref): RedirectResponse
    {
        if (! $this->authedAdmin()) {
            return redirect()->route('admin.payments.index');
        }

        $validated = $request->validate([
            'channels' => ['nullable', 'array'],
            'channels.*' => ['in:email,sms'],
        ]);
        $channels = empty($validated['channels']) ? ['email'] : array_values(array_unique($validated['channels']));

        $booking = Booking::where('booking_ref', $ref)->firstOrFail();

        try {
            $this->outstandingPaymentService->sendLink($booking, $channels);
        } catch (\Throwable $e) {
            return back()->with('flash_error', "ส่งลิงก์ {$ref} ไม่สำเร็จ: {$e->getMessage()}");
        }

        return back()->with('flash_success', "ส่งลิงก์ชำระเงินให้ {$ref} แล้ว");
    }

    private function authedAdmin(): ?User
    {
        $user = Auth::guard('web')->user();

        return ($user instanceof User && $user->hasAnyRole(['admin', 'operator'])) ? $user : null;
    }
}
