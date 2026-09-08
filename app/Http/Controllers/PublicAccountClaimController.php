<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountClaimService;
use App\Support\ThaiDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * หน้า /claim/{token} — ปลายทางของ SMS "ทีมงานเปิดการจองให้คุณแล้ว"
 *
 * ลูกค้าที่ทีมงานจองให้ยังไม่มีรหัสผ่าน จึงเข้าแอปมาดูใบจองของตัวเองไม่ได้เลย
 * หน้านี้เปลี่ยนบัญชีเงาที่ถือใบจองอยู่ให้เป็นบัญชีจริงของลูกค้าในขั้นตอนเดียว
 * (หรือถ้าลูกค้าเผลอไปสมัครใหม่ไปแล้ว ก็ย้ายใบจองไปรวมกับบัญชีที่สมัครไว้)
 *
 * เป็น Blade ล้วนแบบเดียวกับหน้ากรอกข้อมูลอื่น ๆ เพราะลิงก์นี้ถูกกดจาก SMS แล้ว
 * เปิดในเบราว์เซอร์ในแอปแชท ซึ่งบางเครื่องโหลด bundle ข้ามโดเมนไม่ผ่าน
 */
class PublicAccountClaimController extends Controller
{
    public function __construct(private readonly AccountClaimService $claims) {}

    public function show(string $token): View
    {
        $shadow = $this->resolveShadow($token);

        return view('claim.form', [
            'token' => $token,
            'shadow' => $shadow,
            'bookings' => $this->claims->bookingsOf($shadow),
            'thaiDate' => fn ($date) => ThaiDate::short($date),
        ]);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $shadow = $this->resolveShadow($token);

        $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
        ], [
            'email.required' => 'กรุณากรอกอีเมล',
            'email.email' => 'รูปแบบอีเมลไม่ถูกต้อง',
            'password.required' => 'กรุณาตั้งรหัสผ่าน',
            'password.min' => 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร',
        ]);

        $email = mb_strtolower(trim($request->input('email')));
        $existing = User::where('email', $email)->where('id', '!=', $shadow->id)->first();

        if ($existing) {
            // ลูกค้าสมัครเองไปแล้ว (นี่คือเคสที่ทำให้ต้องมีหน้านี้ตั้งแต่แรก) — ยืนยัน
            // ด้วยรหัสผ่านของบัญชีเดิม แล้วย้ายใบจองไปรวมที่นั่น
            if (! $existing->password || ! Hash::check($request->input('password'), $existing->password)) {
                return back()
                    ->withInput($request->only('email'))
                    ->withErrors(['email' => 'อีเมลนี้มีบัญชีอยู่แล้ว กรุณากรอกรหัสผ่านของบัญชีเดิมเพื่อรับการจอง']);
            }

            $moved = $this->claims->mergeInto($existing, $shadow);

            return redirect()
                ->route('public.claim.done')
                ->with('claim_done', ['merged' => true, 'count' => $moved, 'email' => $existing->email]);
        }

        $count = $this->claims->bookingsOf($shadow)->count();

        try {
            $user = $this->claims->activate($shadow, [
                'email' => $email,
                'password' => $request->input('password'),
            ]);
        } catch (\Exception $e) {
            return back()->withInput($request->only('email'))->withErrors(['email' => $e->getMessage()]);
        }

        return redirect()
            ->route('public.claim.done')
            ->with('claim_done', ['merged' => false, 'count' => $count, 'email' => $user->email]);
    }

    /**
     * หน้าจบแยกจากหน้าฟอร์ม เพราะ token ถูกล้างทิ้งไปแล้วตอนเปิดใช้บัญชีสำเร็จ
     * ถ้ายัง redirect กลับหน้าเดิม ลูกค้าจะเจอ 404 ทันทีหลังทำสำเร็จ
     */
    public function done(): View|RedirectResponse
    {
        $result = session('claim_done');

        if (! $result) {
            return redirect('/');
        }

        return view('claim.done', ['result' => $result]);
    }

    private function resolveShadow(string $token): User
    {
        return $this->claims->resolveClaimToken($token) ?? abort(404);
    }
}
