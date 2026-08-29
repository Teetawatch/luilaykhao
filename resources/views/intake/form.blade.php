@extends('intake.layout')

@section('title', 'กรอกข้อมูลผู้เดินทาง')

@php
    $heroImage = $trip?->cover_image ? url($trip->cover_image) : null;
    $heroTitle = $trip?->title ?: 'กรอกข้อมูลผู้เดินทาง';
    $heroEyebrow = $trip ? 'ฟอร์มข้อมูลผู้เดินทาง' : 'ฝากข้อมูลไว้กับทีมงาน';
    $heroEyebrowIcon = $trip ? 'note' : 'sparkle';
    $heroSub = $trip
        ? 'กรอกข้อมูลของคุณไว้ล่วงหน้า ทีมงานจะติดต่อกลับเพื่อยืนยันที่นั่งและวิธีชำระเงิน'
        : 'ยังไม่ต้องรู้ว่าจะไปรอบไหนก็ฝากข้อมูลไว้ได้ ทีมงานจะช่วยแนะนำทริปที่เหมาะกับคุณ';
    $heroChips = [];
    if ($trip && $schedule) {
        $heroChips[] = ['icon' => 'calendar', 'text' => $schedule->dateRangeLabelThai()];
    }
    $heroChips[] = ['icon' => 'clock', 'text' => 'ใช้เวลาประมาณ 2 นาที'];
@endphp

@section('content')
    {{-- ต้องบอกให้ชัดตั้งแต่ต้น ไม่งั้นลูกค้าจะเข้าใจว่ากรอกแล้ว = ได้ที่นั่งแล้ว --}}
    <div class="callout callout--warn">
        @include('intake.icon', ['name' => 'alert'])
        <div>
            <strong>การกรอกฟอร์มนี้ยังไม่ใช่การจอง</strong>
            ที่นั่งจะถูกกันให้เมื่อทีมงานยืนยันกับคุณอีกครั้ง
        </div>
    </div>

    <p class="lead">
        กรอกข้อมูลของ <strong>ตัวคุณเอง</strong> ก่อนได้เลย ไม่ต้องสมัครสมาชิก
        ถ้ามาหลายคน กรอกเสร็จแล้วจะได้ลิงก์ไว้ส่งต่อให้เพื่อนกรอกของตัวเอง
    </p>

    @if ($errors->any())
        <div class="callout callout--error">
            @include('intake.icon', ['name' => 'alert'])
            <div>
                <strong>กรุณาตรวจสอบข้อมูลอีกครั้ง</strong>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('public.intake.submit', $link->token) }}" data-guard>
        @csrf

        <div class="step">
            <span class="n">@include('intake.icon', ['name' => 'calendar'])</span>
            <h2>{{ $link->trip_schedule_id ? 'การเดินทางของคุณ' : 'ทริปที่สนใจ' }}</h2>
            <span class="rule"></span>
        </div>

        @if ($schedule)
            @include('intake.round', ['schedule' => $schedule, 'roundLabel' => 'รอบเดินทางที่คุณกำลังกรอก'])
        @endif

        @if (! $link->trip_schedule_id)
            <div class="f">
                <label class="field" for="schedule_id">เลือกรอบเดินทาง</label>
                <select id="schedule_id" name="schedule_id">
                    <option value="">ยังไม่ระบุ — ให้ทีมงานแนะนำ</option>
                    @foreach ($scheduleOptions as $option)
                        <option value="{{ $option->id }}" @selected((int) old('schedule_id') === $option->id)>
                            {{ $option->trip->title }} · {{ $option->departureLabelShort() }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="f">
            <span class="field" style="display:block">มากันกี่คน</span>
            <div class="stepper">
                <div class="stepper-box" data-stepper>
                    <button type="button" data-step="-1" aria-label="ลดจำนวน">@include('intake.icon', ['name' => 'minus'])</button>
                    <input type="number" id="party_size" name="party_size" inputmode="numeric"
                           min="1" max="{{ \App\Services\CustomerIntakeService::MAX_PEOPLE }}"
                           value="{{ old('party_size', 1) }}" aria-label="จำนวนผู้เดินทาง">
                    <button type="button" data-step="1" aria-label="เพิ่มจำนวน">@include('intake.icon', ['name' => 'plus'])</button>
                </div>
                <span class="unit">คน</span>
            </div>
            <p class="hint">กรอกคร่าว ๆ ได้ แก้ทีหลังได้ ใช้บอกทีมงานว่าต้องรอเพื่อนอีกกี่คน</p>
        </div>

        @include('intake.person-fields', ['isInternational' => (bool) $trip?->isInternational()])

        <div class="step">
            <span class="n">@include('intake.icon', ['name' => 'note'])</span>
            <h2>ฝากบอกทีมงาน</h2>
            <span class="rule"></span>
        </div>

        <div class="f">
            <label class="field" for="note">มีอะไรอยากบอกไหม <span class="opt">(ไม่บังคับ)</span></label>
            <textarea id="note" name="note" maxlength="1000"
                      placeholder="เช่น ขอที่นั่งติดกัน, ขึ้นรถที่ปั๊มไหน, สอบถามเรื่องอะไร">{{ old('note') }}</textarea>
        </div>

        <div class="f">
            <span class="field" style="display:block">รู้จักเราจากช่องทางไหน</span>
            <div class="pills">
                @foreach (['' => 'ไม่ระบุ', 'line' => 'LINE', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'other' => 'อื่น ๆ'] as $value => $label)
                    <label class="pill">
                        <input type="radio" name="source" value="{{ $value }}" @checked(old('source', '') === $value)>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        <div class="form-actions">
            <label class="check check--consent" style="margin-bottom:14px">
                <input type="checkbox" name="consent" value="1" required @checked(old('consent'))>
                <span>ยินยอมให้เก็บข้อมูลนี้เพื่อจัดการการเดินทางและทำประกัน</span>
            </label>

            <button type="submit" class="btn">
                @include('intake.icon', ['name' => 'send']) ส่งข้อมูลให้ทีมงาน
            </button>
        </div>

        <div class="privacy">
            @include('intake.icon', ['name' => 'lock'])
            <span>
                ข้อมูลนี้ใช้สำหรับติดต่อกลับ ทำประกันการเดินทาง และการดูแลระหว่างทริปเท่านั้น
                หากไม่ได้เดินทางกับเรา ข้อมูลจะถูกลบอัตโนมัติภายใน
                {{ \App\Models\CustomerIntake::RETENTION_DAYS }} วัน
            </span>
        </div>
    </form>
@endsection
