@extends('passenger-fill.layout')

@section('title', 'กรอกข้อมูลผู้เดินทาง')

@section('content')
<div class="card">
    <div class="card-header">
        <div class="brand">ลุยเลเขา</div>
        <h1>กรอกข้อมูลผู้เดินทาง</h1>
        @if ($trip)
            <p>{{ $trip->title }}</p>
        @endif
    </div>

    <div class="card-body">
        <p class="lead">
            เพื่อนของคุณจองทริปนี้ให้แล้ว เหลือแค่ข้อมูลของคุณเอง
            กรอกเสร็จแล้วกดบันทึกได้เลย <strong>ไม่ต้องสมัครสมาชิก</strong>
        </p>

        @if ($errors->any())
            <div class="alert alert-error">
                กรุณาตรวจสอบข้อมูลอีกครั้ง
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('public.passenger-fill.submit', request()->route('token')) }}" enctype="multipart/form-data">
            @csrf

            <div class="section-label">ข้อมูลติดต่อ</div>

            <label class="field" for="name">ชื่อ-นามสกุล <span class="req">*</span></label>
            <input type="text" id="name" name="name" required autocomplete="name"
                   @if (($passenger->nationality ?: 'TH') === 'TH') placeholder="ชื่อจริง นามสกุล ภาษาไทย" @endif
                   value="{{ old('name', $passenger->name) }}">
            @if (($passenger->nationality ?: 'TH') === 'TH')
                <p class="hint">กรอกเป็นภาษาไทยตามบัตรประชาชน ใช้ส่งทำประกันการเดินทาง</p>
            @endif

            <div class="row">
                <div>
                    <label class="field" for="nickname">ชื่อเล่น</label>
                    <input type="text" id="nickname" name="nickname"
                           value="{{ old('nickname', $passenger->nickname) }}">
                </div>
                <div>
                    <label class="field" for="phone">เบอร์โทรศัพท์ <span class="req">*</span></label>
                    <input type="tel" id="phone" name="phone" required autocomplete="tel"
                           value="{{ old('phone', $passenger->phone) }}">
                </div>
            </div>

            <label class="field" for="email">อีเมล</label>
            <input type="email" id="email" name="email" autocomplete="email"
                   value="{{ old('email', $passenger->email) }}">

            @php($offersBorderPass = $trip?->offersBorderPass() && ($passenger->nationality ?: 'TH') === 'TH')
            @if ($trip?->collectsPassport())
                {{-- ทริปต่างประเทศ: ข้อมูลชุดนี้เอาไปออกตั๋วเครื่องบินตรง ๆ --}}
                <div class="section-label">เอกสารเดินทาง</div>

                @if ($offersBorderPass)
                    @php($docChoice = old('travel_document', $passenger->travel_document))
                    <p class="hint" style="margin-top:0">ทริปนี้ใช้พาสปอร์ต หรือ Border Pass ก็ได้ คุณมีพาสปอร์ตไหม</p>
                    <label class="check">
                        <input type="radio" name="travel_document" data-doc-choice value="passport" required @checked($docChoice === 'passport')>
                        มีพาสปอร์ต (อายุเหลือเกิน 6 เดือน)
                    </label>
                    <label class="check">
                        <input type="radio" name="travel_document" data-doc-choice value="border_pass" required @checked($docChoice === 'border_pass')>
                        ไม่มี ให้ทีมงานทำ Border Pass ให้
                    </label>
                    <div class="hint" data-border-pass-note hidden style="margin-top:0">
                        ทีมงานยื่นทำ Border Pass จากเลขบัตรประชาชนด้านล่าง วันเดินทางพกบัตรประชาชนตัวจริงมาด้วย
                        — ด่านขอรูปบัตรประชาชนด้านหน้าและด้านหลังเป็นหลักฐาน
                        @include('partials.border-pass-id-upload', [
                            'frontName' => 'border_pass_front',
                            'backName' => 'border_pass_back',
                            'hasFront' => $idFiles[\App\Support\TripDocumentRequirements::BORDER_PASS_FRONT] ?? false,
                            'hasBack' => $idFiles[\App\Support\TripDocumentRequirements::BORDER_PASS_BACK] ?? false,
                        ])
                    </div>
                @endif

                <div data-passport-fields>
                    <p class="hint" style="margin-top:0">กรอกให้ตรงกับหน้าพาสปอร์ตทุกตัวอักษร</p>

                    <label class="field" for="name_en">ชื่อ-สกุลภาษาอังกฤษ (ตามพาสปอร์ต)</label>
                    <input type="text" id="name_en" name="name_en" required maxlength="255"
                           placeholder="SOMCHAI JAIDEE" style="text-transform: uppercase"
                           value="{{ old('name_en', $passenger->name_en) }}">

                    <label class="field" for="passport_no">เลขที่พาสปอร์ต</label>
                    <input type="text" id="passport_no" name="passport_no" required maxlength="20"
                           placeholder="AA1234567" style="text-transform: uppercase"
                           value="{{ old('passport_no', $passenger->passport_no) }}">

                    <label class="field" for="passport_expires_at">วันหมดอายุพาสปอร์ต</label>
                    <input type="date" id="passport_expires_at" name="passport_expires_at" required
                           value="{{ old('passport_expires_at', $passenger->passport_expires_at?->toDateString()) }}">
                    <p class="hint">ต้องเหลืออายุอย่างน้อย 6 เดือนนับจากวันเดินทาง</p>
                </div>
            @elseif ($trip?->travelDocumentMode() === \App\Models\Trip::DOCS_ON_SITE)
                <div class="section-label">เอกสารเดินทาง</div>
                <p class="hint" style="margin-top:0">ไม่ต้องกรอกล่วงหน้า วันเดินทางนำเอกสารเดินทางฉบับจริงมาแสดงกับทีมงาน</p>
            @endif

            <div class="section-label">ข้อมูลสำหรับประกันการเดินทาง</div>

            <label class="field" for="id_card">เลขบัตรประชาชน</label>
            <input type="text" id="id_card" name="id_card" inputmode="numeric" maxlength="20"
                   value="{{ old('id_card', $passenger->id_card) }}">

            <div class="row">
                <div>
                    <label class="field" for="birth_date">วันเกิด</label>
                    <input type="date" id="birth_date" name="birth_date"
                           value="{{ old('birth_date', $passenger->birth_date?->toDateString()) }}">
                </div>
                <div>
                    <label class="field" for="blood_group">กรุ๊ปเลือด</label>
                    <select id="blood_group" name="blood_group">
                        <option value="">ไม่ระบุ</option>
                        @foreach (['A', 'B', 'AB', 'O'] as $group)
                            <option value="{{ $group }}"
                                @selected(old('blood_group', $passenger->blood_group) === $group)>
                                {{ $group }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="section-label">กรณีฉุกเฉิน</div>

            <div class="row">
                <div>
                    <label class="field" for="emergency_contact">ชื่อผู้ติดต่อ</label>
                    <input type="text" id="emergency_contact" name="emergency_contact"
                           value="{{ old('emergency_contact', $passenger->emergency_contact) }}">
                </div>
                <div>
                    <label class="field" for="emergency_phone">เบอร์ติดต่อ</label>
                    <input type="tel" id="emergency_phone" name="emergency_phone"
                           value="{{ old('emergency_phone', $passenger->emergency_phone) }}">
                </div>
            </div>

            <label class="field" for="allergies">การแพ้อาหาร / แพ้ยา</label>
            <textarea id="allergies" name="allergies">{{ old('allergies', $passenger->allergies) }}</textarea>

            <label class="field" for="health_notes">โรคประจำตัว / หมายเหตุสุขภาพ</label>
            <textarea id="health_notes" name="health_notes">{{ old('health_notes', $passenger->health_notes) }}</textarea>

            <label class="check">
                <input type="checkbox" name="halal_food" value="1"
                       @checked(old('halal_food', $passenger->halal_food))>
                ต้องการอาหารฮาลาล
            </label>

            <button type="submit" class="btn">บันทึกข้อมูล</button>

            <p class="privacy">
                ข้อมูลนี้ใช้สำหรับทำประกันการเดินทางและการดูแลระหว่างทริปเท่านั้น
                ลิงก์นี้ใช้ได้ครั้งเดียวและจะใช้ไม่ได้อีกหลังกดบันทึก
            </p>
        </form>
        @include('partials.travel-document-toggle')
    </div>
</div>
@endsection
