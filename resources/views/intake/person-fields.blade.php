{{-- ช่องข้อมูลของผู้เดินทางหนึ่งคน ใช้ร่วมกันทั้งหน้าลิงก์ทีมงานและหน้ากลุ่ม --}}
@php($isInternational = $isInternational ?? false)
@php($pickupPoints = $pickupPoints ?? collect())
@php($seatMap = $seatMap ?? null)

<div class="step">
    <span class="n">@include('intake.icon', ['name' => 'user'])</span>
    <h2>ข้อมูลผู้เดินทาง</h2>
    <span class="rule"></span>
</div>
<p class="step-note">กรอกของ<strong>ตัวคุณเอง</strong> ทุกช่องจำเป็นต้องกรอก เพราะใช้ทำประกันการเดินทางและดูแลคุณระหว่างทริป</p>

<div class="f">
    <span class="field" style="display:block">คำนำหน้า <span class="req">*</span></span>
    <div class="pills">
        @foreach (['นาย', 'นาง', 'นางสาว'] as $label)
            <label class="pill">
                <input type="radio" name="title" value="{{ $label }}" required @checked(old('title') === $label)>
                <span>{{ $label }}</span>
            </label>
        @endforeach
    </div>
</div>

<div class="f">
    <label class="field" for="name">ชื่อ-นามสกุล <span class="req">*</span></label>
    <input type="text" id="name" name="name" required autocomplete="name"
           placeholder="ชื่อจริง นามสกุล ตามบัตรประชาชน" value="{{ old('name') }}">
</div>

<div class="grid2">
    <div class="f">
        <label class="field" for="nickname">ชื่อเล่น <span class="req">*</span></label>
        <input type="text" id="nickname" name="nickname" required placeholder="ให้ทีมงานเรียก" value="{{ old('nickname') }}">
    </div>
    <div class="f">
        <label class="field" for="phone">เบอร์โทรศัพท์ <span class="req">*</span></label>
        <input type="tel" id="phone" name="phone" required autocomplete="tel" inputmode="tel"
               placeholder="08X-XXX-XXXX" value="{{ old('phone') }}">
    </div>
</div>

<div class="f">
    <label class="field" for="email">อีเมล <span class="req">*</span></label>
    <input type="email" id="email" name="email" required autocomplete="email" inputmode="email"
           placeholder="สำหรับส่งใบเสร็จและกำหนดการ" value="{{ old('email') }}">
</div>

@if ($isInternational)
    {{-- ทริปต่างประเทศ: ข้อมูลชุดนี้เอาไปออกตั๋วเครื่องบินตรง ๆ --}}
    <div class="step">
        <span class="n">@include('intake.icon', ['name' => 'globe'])</span>
        <h2>เอกสารเดินทาง</h2>
        <span class="rule"></span>
    </div>
    <p class="step-note">กรอกให้ตรงกับหน้าพาสปอร์ตทุกตัวอักษร เพราะใช้ออกตั๋วเครื่องบิน แก้ทีหลังมีค่าธรรมเนียม</p>

    <div class="f">
        <label class="field" for="name_en">ชื่อ-สกุลภาษาอังกฤษ (ตามพาสปอร์ต) <span class="req">*</span></label>
        <input type="text" id="name_en" name="name_en" required maxlength="255" data-uppercase
               autocapitalize="characters" spellcheck="false"
               placeholder="SOMCHAI JAIDEE" style="text-transform: uppercase" value="{{ old('name_en') }}">
    </div>

    <div class="grid2">
        <div class="f">
            <label class="field" for="passport_no">เลขที่พาสปอร์ต <span class="req">*</span></label>
            <input type="text" id="passport_no" name="passport_no" required maxlength="20" data-uppercase
                   autocapitalize="characters" spellcheck="false"
                   placeholder="AA1234567" style="text-transform: uppercase" value="{{ old('passport_no') }}">
        </div>
        <div class="f">
            <label class="field" for="passport_expires_at">วันหมดอายุพาสปอร์ต <span class="req">*</span></label>
            <input type="date" id="passport_expires_at" name="passport_expires_at" required
                   value="{{ old('passport_expires_at') }}">
        </div>
    </div>
    <p class="hint" style="margin-top:-9px;margin-bottom:15px">ต้องเหลืออายุอย่างน้อย 6 เดือนนับจากวันเดินทาง</p>
@endif

@if ($pickupPoints->isNotEmpty())
    {{-- ห่อไว้เพื่อให้ตัวเลือก "จอยทริป" ซ่อนทั้งก้อนได้ (ดู intake.booking-type) --}}
    <div data-pickup-block>
        @include('intake.pickup-choice', ['pickupPoints' => $pickupPoints])
    </div>
@endif

@if ($seatMap)
    {{-- ห่อด้วยเหตุผลเดียวกับจุดขึ้นรถ — คนจอยทริปไม่ได้ขึ้นรถของเรา จึงไม่มีที่นั่ง --}}
    <div data-seat-wrap>
        @include('intake.seat-choice', ['seatMap' => $seatMap])
    </div>
@endif

<div class="step">
    <span class="n">@include('intake.icon', ['name' => 'shield'])</span>
    <h2>ข้อมูลสำหรับทำประกัน</h2>
    <span class="rule"></span>
</div>
<p class="step-note">ทริปของเราทำประกันการเดินทางให้ทุกคน บริษัทประกันขอข้อมูลชุดนี้</p>

<div class="f">
    <label class="field" for="id_card">เลขบัตรประชาชน <span class="req">*</span></label>
    <input type="text" id="id_card" name="id_card" required inputmode="numeric" maxlength="17"
           data-id-card placeholder="X-XXXX-XXXXX-XX-X" value="{{ old('id_card') }}">
</div>

<div class="f">
    <label class="field" for="birth_date">วันเกิด <span class="req">*</span></label>
    <input type="date" id="birth_date" name="birth_date" required max="{{ now('Asia/Bangkok')->toDateString() }}"
           value="{{ old('birth_date') }}">
</div>

<div class="f">
    <span class="field" style="display:block">กรุ๊ปเลือด <span class="req">*</span></span>
    <div class="pills">
        {{-- "ไม่ทราบ" เป็นคำตอบที่ยอมรับได้ แต่ต้องกดเลือก ไม่ใช่ข้ามไปเฉย ๆ —
             ให้เดาแล้วกรอกผิดอันตรายกว่าไม่รู้ --}}
        @foreach (['A' => 'A', 'B' => 'B', 'AB' => 'AB', 'O' => 'O', '' => 'ไม่ทราบ'] as $value => $label)
            <label class="pill">
                <input type="radio" name="blood_group" value="{{ $value }}" required
                       @checked(old('blood_group') !== null && old('blood_group') === $value)>
                <span>{{ $label }}</span>
            </label>
        @endforeach
    </div>
</div>

<div class="step">
    <span class="n">@include('intake.icon', ['name' => 'heart'])</span>
    <h2>ความปลอดภัยระหว่างทริป</h2>
    <span class="rule"></span>
</div>
<p class="step-note">ทีมงานดูข้อมูลส่วนนี้เฉพาะตอนที่จำเป็นต้องช่วยเหลือคุณเท่านั้น</p>

<div class="grid2">
    <div class="f">
        <label class="field" for="emergency_contact">ผู้ติดต่อฉุกเฉิน <span class="req">*</span></label>
        <input type="text" id="emergency_contact" name="emergency_contact" required
               placeholder="ชื่อ · ความสัมพันธ์" value="{{ old('emergency_contact') }}">
    </div>
    <div class="f">
        <label class="field" for="emergency_phone">เบอร์ผู้ติดต่อ <span class="req">*</span></label>
        <input type="tel" id="emergency_phone" name="emergency_phone" required inputmode="tel"
               placeholder="08X-XXX-XXXX" value="{{ old('emergency_phone') }}">
    </div>
</div>

{{-- สองช่องนี้บังคับกรอก เพราะ "เว้นว่าง" อ่านไม่ออกว่าแปลว่าไม่มี หรือแปลว่า
     ลูกค้าข้ามไป — ปุ่ม "ไม่มี" ทำให้คำตอบว่าไม่มีเป็นการกดครั้งเดียว ไม่ใช่การพิมพ์ --}}
<div class="f">
    <div class="field-row">
        <label class="field" for="allergies">การแพ้อาหาร / แพ้ยา <span class="req">*</span></label>
        <button type="button" class="none-btn" data-none="allergies">ไม่มี</button>
    </div>
    <textarea id="allergies" name="allergies" required maxlength="500"
              placeholder="เช่น แพ้อาหารทะเล, แพ้ยาเพนิซิลลิน — ถ้าไม่มี กดปุ่ม &quot;ไม่มี&quot;">{{ old('allergies') }}</textarea>
</div>

<div class="f">
    <div class="field-row">
        <label class="field" for="health_notes">โรคประจำตัว / หมายเหตุสุขภาพ <span class="req">*</span></label>
        <button type="button" class="none-btn" data-none="health_notes">ไม่มี</button>
    </div>
    <textarea id="health_notes" name="health_notes" required maxlength="500"
              placeholder="เช่น หอบหืด, ความดัน, ยาที่ต้องพกติดตัว — ถ้าไม่มี กดปุ่ม &quot;ไม่มี&quot;">{{ old('health_notes') }}</textarea>
</div>

<div class="f">
    <span class="field" style="display:block">อาหารฮาลาล <span class="req">*</span></span>
    <div class="pills">
        @foreach (['0' => 'ไม่ต้องการ', '1' => 'ต้องการอาหารฮาลาล'] as $value => $label)
            <label class="pill">
                <input type="radio" name="halal_food" value="{{ $value }}" required
                       @checked(old('halal_food') === $value)>
                <span>{{ $label }}</span>
            </label>
        @endforeach
    </div>
</div>
