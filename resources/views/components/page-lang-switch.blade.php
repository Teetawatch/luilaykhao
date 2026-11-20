{{--
  ปุ่มสลับภาษาของหน้าลิงก์ Blade (/t/ /p/ /f/ /r/ /claim/ /pay/) — ?lang= ถูกจำไว้ในคุกกี้
  โดย SetPublicPageLocale ฟอร์มที่ส่งต่อจากหน้านี้จึงอยู่ในภาษาเดิม
--}}
@php
  $pageLang = app()->getLocale() === 'en' ? 'en' : 'th';
  $otherLang = $pageLang === 'en' ? 'th' : 'en';
  // คงพารามิเตอร์เดิมไว้ (หน้าผลการจ่ายเงินต้องใช้) แต่ไม่สะท้อนค่าที่เป็น URL กลับออกไป
  $keep = collect(request()->query())
      ->reject(fn ($value) => ! is_scalar($value) || preg_match('#(^//|://)#', (string) $value))
      ->put('lang', $otherLang)
      ->all();
@endphp
<a href="{{ url()->current().'?'.http_build_query($keep) }}" hreflang="{{ $otherLang }}" lang="{{ $otherLang }}"
   style="position:absolute;top:12px;right:12px;z-index:60;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:#ffffff;border:1px solid #e5e7eb;color:#1f2937;font:600 13px/1.2 system-ui,-apple-system,sans-serif;text-decoration:none;">
  <span aria-hidden="true">🌐</span>{{ $otherLang === 'en' ? 'English' : 'ภาษาไทย' }}
</a>
