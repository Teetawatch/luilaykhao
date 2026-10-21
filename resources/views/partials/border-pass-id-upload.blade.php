{{--
    แนบบัตรประชาชนหน้า/หลังของคนที่เลือก Border Pass — กฎของด่าน

    ตัวแปร: $frontName, $backName (ชื่อ input), $hasFront, $hasBack (แนบไว้แล้วไหม)
    อยู่ในกล่อง [data-border-pass-note] เสมอ สคริปต์ travel-document-toggle จะ
    disable ช่องเหล่านี้เมื่อเลือกพาสปอร์ต ไฟล์จึงไม่ถูกส่งไปโดยไม่ตั้งใจ
--}}
<div style="margin:10px 0 4px;display:grid;gap:10px;">
    @foreach ([[$frontName, $hasFront, 'บัตรประชาชน (ด้านหน้า)'], [$backName, $hasBack, 'บัตรประชาชน (ด้านหลัง)']] as [$inputName, $has, $label])
        <label style="display:block;font-size:13.5px;font-weight:700;color:#334155;">
            {{ $label }} @if ($has)<span style="color:#15803d;font-weight:700;">· แนบแล้ว ✓ (เลือกใหม่ = แทนที่)</span>@else<span style="color:#dc2626;">*</span>@endif
            <input type="file" name="{{ $inputName }}" accept="image/*,.pdf,application/pdf"
                   style="display:block;margin-top:6px;font-size:13px;width:100%;">
        </label>
    @endforeach
    <p style="font-size:12px;color:#64748b;line-height:1.55;margin:0;">
        🔒 {{ \App\Support\TripDocumentRequirements::BORDER_PASS_NOTE }}
    </p>
</div>
