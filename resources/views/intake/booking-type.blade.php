{{-- ไปกับรถ หรือ จอยทริป (ไปเอง) — ถามเฉพาะลิงก์ที่ทีมงานไม่ได้ล็อกประเภทไว้

     ใบจองหนึ่งใบเป็นได้ประเภทเดียว คำตอบนี้จึงเป็นของทั้งกลุ่ม ไม่ใช่รายคน
     และมันเปลี่ยนสองอย่างตามมาทันที: ราคา กับการมี/ไม่มีจุดขึ้นรถ --}}
<div class="step">
    <span class="n">@include('intake.icon', ['name' => 'bus'])</span>
    <h2>เดินทางแบบไหน</h2>
    <span class="rule"></span>
</div>

<div class="f">
    <div class="choices" data-booking-type>
        <label class="choice">
            <input type="radio" name="booking_type" value="{{ \App\Models\IntakeLink::TYPE_NORMAL }}"
                   @checked(old('booking_type', \App\Models\IntakeLink::TYPE_NORMAL) !== \App\Models\IntakeLink::TYPE_JOIN)>
            <span class="choice-card">
                <strong>ไปกับรถของทริป</strong>
                <span class="choice-note">ขึ้นรถที่จุดนัดหมาย แล้วไป-กลับพร้อมกันทั้งกลุ่ม</span>
            </span>
        </label>
        <label class="choice">
            <input type="radio" name="booking_type" value="{{ \App\Models\IntakeLink::TYPE_JOIN }}"
                   @checked(old('booking_type') === \App\Models\IntakeLink::TYPE_JOIN)>
            <span class="choice-card">
                <strong>จอยทริป — เดินทางไปเอง</strong>
                <span class="choice-note">ขับรถไปเจอกันที่จุดหมาย ไม่มีรถรับ ราคาคนละแบบกับแบบมีรถ</span>
            </span>
        </label>
    </div>
    <p class="hint">ยังไม่แน่ใจก็เลือกไว้ก่อนได้ ทีมงานจะยืนยันกับคุณอีกครั้งก่อนจอง</p>
</div>

@push('scripts')
    <script>
        // จุดขึ้นรถมีความหมายเฉพาะกับคนที่ไปกับรถ — ซ่อนไปเฉย ๆ ไม่พอ ต้องปลด
        // required ด้วย ไม่งั้นเบราว์เซอร์จะปฏิเสธการส่งฟอร์มโดยชี้ไปที่ช่องที่
        // มองไม่เห็น แล้วลูกค้าจะไม่รู้เลยว่าติดตรงไหน
        (function () {
            var group = document.querySelector('[data-booking-type]');
            var pickup = document.querySelector('[data-pickup-block]');
            if (!group || !pickup) { return; }

            var inputs = pickup.querySelectorAll('input[type="radio"]');

            function sync() {
                var choice = group.querySelector('input:checked');
                var isJoin = choice && choice.value === 'join';
                pickup.hidden = isJoin;
                inputs.forEach(function (input) {
                    input.required = !isJoin;
                    if (isJoin) { input.checked = false; }
                });
            }

            group.addEventListener('change', sync);
            sync();
        })();
    </script>
@endpush
