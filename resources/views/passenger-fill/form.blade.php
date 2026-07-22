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

        <form method="POST" action="{{ route('public.passenger-fill.submit', request()->route('token')) }}">
            @csrf

            <div class="section-label">ข้อมูลติดต่อ</div>

            <label class="field" for="name">ชื่อ-นามสกุล <span class="req">*</span></label>
            <input type="text" id="name" name="name" required autocomplete="name"
                   value="{{ old('name', $passenger->name) }}">

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
    </div>
</div>
@endsection
