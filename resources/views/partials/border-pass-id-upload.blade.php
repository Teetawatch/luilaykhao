{{--
    แนบบัตรประชาชนหน้า/หลังของคนที่เลือก Border Pass — กฎของด่าน

    ตัวแปร: $frontName, $backName (ชื่อ input), $hasFront, $hasBack (แนบไว้แล้วไหม)
    อยู่ในกล่อง [data-border-pass-note] เสมอ สคริปต์ travel-document-toggle จะ
    disable ช่องเหล่านี้เมื่อเลือกพาสปอร์ต ไฟล์จึงไม่ถูกส่งไปโดยไม่ตั้งใจ
--}}
<div style="margin:10px 0 4px;display:grid;gap:10px;">
    {{-- วิธีถ่ายบัตรให้ด่านรับ — ข้อความเดียวกับที่แอปและเว็บแสดง (TripDocumentRequirements::borderPass) --}}
    <details style="font-size:12.5px;color:#334155;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:8px 12px;">
        <summary style="cursor:pointer;font-weight:700;color:#0f766e;">ดูวิธีถ่ายบัตรให้ผ่าน</summary>
        @foreach (\App\Support\TripDocumentRequirements::borderPass() as $requirement)
            <p style="margin:8px 0 2px;font-weight:700;">{{ $requirement['label'] }}</p>
            <ul style="margin:0;padding-left:18px;line-height:1.6;">
                @foreach (explode("\n", $requirement['guide']) as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        @endforeach
    </details>
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
