<?php

namespace App\Services;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * หาบัญชีของลูกค้าที่ไม่ได้ล็อกอินอยู่ตอนเกิดใบจอง — หรือเปิด "บัญชีเงา" ให้
 *
 * ใบจองต้องมีเจ้าของเสมอ แต่มีสองทางที่คนกดจองไม่ใช่ลูกค้าที่ล็อกอินอยู่: ทีมงาน
 * จองแทน และลูกค้าจองเองจากลิงก์กรอกข้อมูล (/g/{token}) ทั้งสองทางต้องหาบัญชี
 * เดียวกันให้เจอ ไม่งั้นลูกค้าเข้าแอปแล้วไม่เห็นใบจองของตัวเอง ซึ่งคืออาการเดิมที่
 * บัญชีเงาถูกสร้างมาแก้ ({@see AccountClaimService})
 */
class CustomerAccountService
{
    /**
     * @param  bool  $mayUpdateRealAccount  ทีมงานจองแทน = แก้ชื่อ/เบอร์ของบัญชีจริงได้
     *                                      (ทีมงานคุยกับลูกค้าอยู่) ส่วนฟอร์มสาธารณะ
     *                                      ห้ามแตะบัญชีจริงเด็ดขาด — ใครพิมพ์อีเมลของ
     *                                      คนอื่นลงฟอร์มได้ ไม่ควรเปลี่ยนชื่อบัญชีเขาได้
     */
    public function findOrCreate(string $name, string $phone, ?string $email, bool $mayUpdateRealAccount = false): User
    {
        // เทียบทั้งอีเมล (พิมพ์เล็กเสมอเหมือนตอนสมัคร) และเบอร์ทุกรูปแบบที่คนพิมพ์
        // กันจริง เพราะบัญชีซ้ำหนึ่งใบแปลว่าลูกค้าเข้าแอปแล้วไม่เห็นใบจองของตัวเอง
        $email = filled($email) ? mb_strtolower(trim($email)) : null;

        $user = ($email ? User::where('email', $email)->first() : null)
            // บัญชีจริงของลูกค้ามาก่อนบัญชีเงาเสมอ ถ้าเบอร์เดียวกันมีทั้งสองแบบค้างอยู่
            ?? User::query()->whereIn('phone', PhoneNumber::variants($phone))->orderBy('is_shadow')->first();

        if (! $user) {
            $user = User::create([
                'name' => $name,
                'phone' => $phone,
                'email' => $email ?: 'manual_'.time().'_'.Str::random(4).'@luilaykhao.com',
                'password' => Hash::make(Str::random(16)),
            ]);
            $user->forceFill(['is_shadow' => true])->save();
            $user->assignRole('customer');

            return $user;
        }

        if (! $user->is_shadow && ! $mayUpdateRealAccount) {
            return $user;
        }

        // อีเมลของบัญชีที่ลูกค้าสมัครเองห้ามถูกทับด้วยอีเมลที่พิมพ์มา —
        // ทับแล้วลูกค้าล็อกอินด้วยอีเมลเดิมไม่ได้อีก
        $user->update(array_filter([
            'name' => $name ?: null,
            'phone' => $phone ?: null,
            'email' => ($email && $user->is_shadow) ? $email : null,
        ], fn ($value) => filled($value)));

        return $user;
    }
}
