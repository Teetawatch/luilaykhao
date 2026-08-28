{{-- ช่องข้อมูลของผู้เดินทางหนึ่งคน ใช้ร่วมกันทั้งหน้าลิงก์ทีมงานและหน้ากลุ่ม --}}
@php($isInternational = $isInternational ?? false)

<div class="section-label">ข้อมูลติดต่อ</div>

<div class="row">
    <div style="flex:0 0 110px">
        <label class="field" for="title">คำนำหน้า</label>
        <select id="title" name="title">
            <option value="">ไม่ระบุ</option>
            @foreach (['นาย', 'นาง', 'นางสาว'] as $option)
                <option value="{{ $option }}" @selected(old('title') === $option)>{{ $option }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="field" for="name">ชื่อ-นามสกุล <span class="req">*</span></label>
        <input type="text" id="name" name="name" required autocomplete="name" value="{{ old('name') }}">
    </div>
</div>

<div class="row">
    <div>
        <label class="field" for="nickname">ชื่อเล่น</label>
        <input type="text" id="nickname" name="nickname" value="{{ old('nickname') }}">
    </div>
    <div>
        <label class="field" for="phone">เบอร์โทรศัพท์ <span class="req">*</span></label>
        <input type="tel" id="phone" name="phone" required autocomplete="tel" inputmode="tel" value="{{ old('phone') }}">
    </div>
</div>

<label class="field" for="email">อีเมล</label>
<input type="email" id="email" name="email" autocomplete="email" value="{{ old('email') }}">

@if ($isInternational)
    {{-- ทริปต่างประเทศ: ข้อมูลชุดนี้เอาไปออกตั๋วเครื่องบินตรง ๆ --}}
    <div class="section-label">เอกสารเดินทาง</div>
    <p class="hint">กรอกให้ตรงกับหน้าพาสปอร์ตทุกตัวอักษร เพราะใช้ออกตั๋วเครื่องบิน</p>

    <label class="field" for="name_en">ชื่อ-สกุลภาษาอังกฤษ (ตามพาสปอร์ต) <span class="req">*</span></label>
    <input type="text" id="name_en" name="name_en" required maxlength="255"
           placeholder="SOMCHAI JAIDEE" style="text-transform: uppercase" value="{{ old('name_en') }}">

    <label class="field" for="passport_no">เลขที่พาสปอร์ต <span class="req">*</span></label>
    <input type="text" id="passport_no" name="passport_no" required maxlength="20"
           placeholder="AA1234567" style="text-transform: uppercase" value="{{ old('passport_no') }}">

    <label class="field" for="passport_expires_at">วันหมดอายุพาสปอร์ต <span class="req">*</span></label>
    <input type="date" id="passport_expires_at" name="passport_expires_at" required
           value="{{ old('passport_expires_at') }}">
    <p class="hint">ต้องเหลืออายุอย่างน้อย 6 เดือนนับจากวันเดินทาง</p>
@endif

<div class="section-label">ข้อมูลสำหรับประกันการเดินทาง</div>

<label class="field" for="id_card">เลขบัตรประชาชน</label>
<input type="text" id="id_card" name="id_card" inputmode="numeric" maxlength="17" value="{{ old('id_card') }}">

<div class="row">
    <div>
        <label class="field" for="birth_date">วันเกิด</label>
        <input type="date" id="birth_date" name="birth_date" max="{{ now('Asia/Bangkok')->toDateString() }}"
               value="{{ old('birth_date') }}">
    </div>
    <div>
        <label class="field" for="blood_group">กรุ๊ปเลือด</label>
        <select id="blood_group" name="blood_group">
            <option value="">ไม่ระบุ</option>
            @foreach (['A', 'B', 'AB', 'O'] as $group)
                <option value="{{ $group }}" @selected(old('blood_group') === $group)>{{ $group }}</option>
            @endforeach
        </select>
    </div>
</div>

<div class="section-label">กรณีฉุกเฉิน</div>

<div class="row">
    <div>
        <label class="field" for="emergency_contact">ชื่อผู้ติดต่อ</label>
        <input type="text" id="emergency_contact" name="emergency_contact" value="{{ old('emergency_contact') }}">
    </div>
    <div>
        <label class="field" for="emergency_phone">เบอร์ติดต่อ</label>
        <input type="tel" id="emergency_phone" name="emergency_phone" inputmode="tel" value="{{ old('emergency_phone') }}">
    </div>
</div>

<label class="field" for="allergies">การแพ้อาหาร / แพ้ยา</label>
<textarea id="allergies" name="allergies">{{ old('allergies') }}</textarea>

<label class="field" for="health_notes">โรคประจำตัว / หมายเหตุสุขภาพ</label>
<textarea id="health_notes" name="health_notes">{{ old('health_notes') }}</textarea>

<label class="check">
    <input type="checkbox" name="halal_food" value="1" @checked(old('halal_food'))>
    ต้องการอาหารฮาลาล
</label>
