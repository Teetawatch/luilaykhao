<?php

namespace App\Http\Requests\Auth;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the email to lowercase before validation so the domain
     * allowlist and unique check behave consistently.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email') && is_string($this->email)) {
            $this->merge(['email' => mb_strtolower(trim($this->email))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:20'],
            'nickname' => ['nullable', 'string', 'max:255'],
            'blood_group' => ['nullable', 'string', 'max:10'],
            'email' => ['required', 'email', 'unique:users,email', $this->allowedDomainRule()],
            'phone' => ['nullable', 'string', 'max:20'],
            'id_card' => ['nullable', 'string', 'digits:13'],
            // วันเกิดใช้ต่อในขั้นตอนจอง (ประกันการเดินทาง/ตั๋วเครื่องบิน) เก็บตั้งแต่
            // สมัครจะได้ไม่ต้องไล่ถามทีหลัง — ยังเป็น nullable เพราะช่องทางอื่น
            // (LIFF, สมัครผ่านโซเชียล) ยังไม่มีช่องนี้
            'birth_date' => ['nullable', 'date', 'before:today'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'emergency_phone' => ['nullable', 'string', 'max:20'],
            'allergies' => ['nullable', 'string'],
            'health_notes' => ['nullable', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'referral_code' => ['nullable', 'string', 'max:16'],
        ];
    }

    /**
     * Reject email addresses whose domain is not in the allowlist of
     * well-known active providers (config/email_domains.php).
     */
    protected function allowedDomainRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! str_contains($value, '@')) {
                return; // Let the `email` rule report malformed addresses.
            }

            $domain = mb_strtolower(substr(strrchr($value, '@'), 1));
            $allowed = config('email_domains.allowed', []);

            if (! in_array($domain, $allowed, true)) {
                $fail('กรุณาใช้อีเมลจากผู้ให้บริการที่รองรับ เช่น Gmail, Hotmail, Outlook, Yahoo หรือ iCloud');
            }
        };
    }
}
