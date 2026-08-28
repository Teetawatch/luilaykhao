@extends('passenger-fill.layout')

@section('title', 'กรอกข้อมูลผู้เดินทาง')

@push('styles')
<style>
    .notice { background: #fffbeb; border: 1px solid #fcd34d; color: #92400e;
              border-radius: 12px; padding: 13px 15px; font-size: 13.5px; margin-bottom: 18px; }
    .notice strong { display: block; margin-bottom: 2px; }
</style>
@endpush

@section('content')
<div class="card">
    <div class="card-header">
        <div class="brand">ลุยเลเขา</div>
        <h1>กรอกข้อมูลผู้เดินทาง</h1>
        @if ($trip)
            <p>{{ $trip->title }} · {{ $schedule->departureLabelThai() }}</p>
        @else
            <p>ฝากข้อมูลไว้กับทีมงาน แล้วเราติดต่อกลับ</p>
        @endif
    </div>

    <div class="card-body">
        <p class="lead">
            กรอกข้อมูลของ <strong>ตัวคุณเอง</strong> ก่อนได้เลย ไม่ต้องสมัครสมาชิก
            ถ้ามาหลายคน กรอกเสร็จแล้วจะได้ลิงก์ไว้ส่งต่อให้เพื่อนกรอกของตัวเอง
        </p>

        {{-- ต้องบอกให้ชัดตั้งแต่ต้น ไม่งั้นลูกค้าจะเข้าใจว่ากรอกแล้ว = ได้ที่นั่งแล้ว --}}
        <div class="notice">
            <strong>การกรอกฟอร์มนี้ยังไม่ใช่การจอง</strong>
            ที่นั่งจะถูกกันให้เมื่อทีมงานยืนยันกับคุณอีกครั้ง
        </div>

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

        <form method="POST" action="{{ route('public.intake.submit', $link->token) }}">
            @csrf

            @if (! $link->trip_schedule_id)
                <div class="section-label">ทริปที่สนใจ</div>
                <label class="field" for="schedule_id">เลือกรอบเดินทาง</label>
                <select id="schedule_id" name="schedule_id">
                    <option value="">ยังไม่ระบุ — ให้ทีมงานแนะนำ</option>
                    @foreach ($scheduleOptions as $option)
                        <option value="{{ $option->id }}" @selected((int) old('schedule_id') === $option->id)>
                            {{ $option->trip->title }} · {{ $option->departureLabelShort() }}
                        </option>
                    @endforeach
                </select>
            @endif

            <label class="field" for="party_size">มากันกี่คน</label>
            <select id="party_size" name="party_size">
                @for ($n = 1; $n <= \App\Services\CustomerIntakeService::MAX_PEOPLE; $n++)
                    <option value="{{ $n }}" @selected((int) old('party_size', 1) === $n)>{{ $n }} คน</option>
                @endfor
            </select>
            <p class="hint">กรอกคร่าว ๆ ได้ แก้ทีหลังได้ ใช้บอกทีมงานว่าต้องรอเพื่อนอีกกี่คน</p>

            @include('intake.person-fields', ['isInternational' => (bool) $trip?->isInternational()])

            <div class="section-label">ฝากบอกทีมงาน</div>

            <label class="field" for="note">มีอะไรอยากบอกไหม</label>
            <textarea id="note" name="note" maxlength="1000"
                      placeholder="เช่น ขอที่นั่งติดกัน, ขึ้นรถที่ปั๊มไหน, สอบถามเรื่องอะไร">{{ old('note') }}</textarea>

            <label class="field" for="source">รู้จักเราจากช่องทางไหน</label>
            <select id="source" name="source">
                <option value="">ไม่ระบุ</option>
                @foreach (['line' => 'LINE', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'other' => 'อื่น ๆ'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('source') === $value)>{{ $label }}</option>
                @endforeach
            </select>

            <label class="check">
                <input type="checkbox" name="consent" value="1" required @checked(old('consent'))>
                ยินยอมให้เก็บข้อมูลนี้เพื่อจัดการการเดินทางและทำประกัน
            </label>

            <button type="submit" class="btn">ส่งข้อมูลให้ทีมงาน</button>

            <p class="privacy">
                ข้อมูลนี้ใช้สำหรับติดต่อกลับ ทำประกันการเดินทาง และการดูแลระหว่างทริปเท่านั้น
                หากไม่ได้เดินทางกับเรา ข้อมูลจะถูกลบอัตโนมัติภายใน
                {{ \App\Models\CustomerIntake::RETENTION_DAYS }} วัน
            </p>
        </form>
    </div>
</div>
@endsection
