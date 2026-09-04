@extends('intake.layout')

@section('title', 'กรอกข้อมูลผู้เดินทาง')

@php
    $isJoinLink = ($bookingType ?? 'normal') === \App\Models\IntakeLink::TYPE_JOIN;
    $heroImage = $trip?->cover_image ? url($trip->cover_image) : null;
    $heroTitle = $trip?->title ?: 'กรอกข้อมูลผู้เดินทาง';
    $heroEyebrow = $trip ? 'ฟอร์มข้อมูลผู้เดินทาง' : 'ฝากข้อมูลไว้กับทีมงาน';
    $heroEyebrowIcon = $trip ? 'note' : 'sparkle';
    $heroSub = match (true) {
        ($closed ?? false) => 'รอบนี้ปิดรับแล้ว แต่ฝากข้อมูลไว้ได้ ทีมงานจะติดต่อกลับเรื่องรอบอื่นหรือคิวรอที่นั่ง',
        (bool) $trip => 'กรอกข้อมูลของคุณไว้ล่วงหน้า ทีมงานจะติดต่อกลับเพื่อยืนยันที่นั่งและวิธีชำระเงิน',
        default => 'ยังไม่ต้องรู้ว่าจะไปรอบไหนก็ฝากข้อมูลไว้ได้ ทีมงานจะช่วยแนะนำทริปที่เหมาะกับคุณ',
    };
    $heroChips = [];
    if ($trip && $schedule) {
        $heroChips[] = ['icon' => 'calendar', 'text' => $schedule->dateRangeLabelThai()];
    }
    $heroChips[] = ['icon' => 'clock', 'text' => 'ใช้เวลาประมาณ 2 นาที'];

    // การ์ดพรีวิวตอนแอดมินวางลิงก์ในแชท — พูดให้จบในบรรทัดเดียวว่าให้ทำอะไร
    // และไม่ใช่การจอง เพื่อไม่ให้ลูกค้าเข้าใจผิดตั้งแต่ยังไม่กด
    $ogTitle = $trip
        ? 'กรอกข้อมูลผู้เดินทาง · '.$trip->title
        : 'ฝากข้อมูลไว้กับทีมงาน · ลุยเลเขา';
    $ogDescription = $schedule
        ? $schedule->dateRangeLabelThai().' — ใช้เวลาประมาณ 2 นาที กรอกแล้วยังไม่ใช่การจอง ทีมงานจะติดต่อกลับเพื่อยืนยันที่นั่ง'
        : 'กรอกข้อมูลผู้เดินทางไว้ล่วงหน้า ใช้เวลาประมาณ 2 นาที ทีมงานจะติดต่อกลับเพื่อแนะนำทริปที่เหมาะกับคุณ';
@endphp

@section('content')
    {{-- รอบเต็มแล้วต้องรู้ก่อนกรอก ไม่ใช่รู้ตอนทีมงานตอบกลับ — ข้อมูลยังรับอยู่
         เพราะทีมงานเอาไปเสนอรอบอื่นหรือคิวรอที่นั่งให้ได้ --}}
    @if ($closed ?? false)
        <div class="callout callout--warn">
            @include('intake.icon', ['name' => 'alert'])
            <div>
                <strong>รอบนี้ปิดรับแล้ว</strong>
                @if ($scheduleOptions->isNotEmpty())
                    เลือกรอบอื่นของทริปนี้ด้านล่างได้เลย หรือฝากข้อมูลไว้เฉย ๆ
                    ทีมงานจะติดต่อกลับถ้ามีที่ว่างในรอบนี้
                @else
                    ฝากข้อมูลไว้ได้ ทีมงานจะติดต่อกลับถ้ามีที่ว่างหรือมีรอบใหม่
                @endif
            </div>
        </div>
    @else
        {{-- ต้องบอกให้ชัดตั้งแต่ต้น ไม่งั้นลูกค้าจะเข้าใจว่ากรอกแล้ว = ได้ที่นั่งแล้ว --}}
        <div class="callout callout--warn">
            @include('intake.icon', ['name' => 'alert'])
            <div>
                <strong>การกรอกฟอร์มนี้ยังไม่ใช่การจอง</strong>
                ที่นั่งจะถูกกันให้เมื่อทีมงานยืนยันกับคุณอีกครั้ง
            </div>
        </div>
    @endif

    {{-- ลิงก์จอยทริปไม่มีขั้นตอนเลือกจุดขึ้นรถ ถ้าไม่บอกไว้ ลูกค้าที่คิดว่ามีรถมารับ
         จะรู้ตัวอีกทีตอนถึงวันเดินทาง — เรื่องนี้ต้องชัดตั้งแต่บรรทัดแรก --}}
    @if ($isJoinLink)
        <div class="callout callout--ok">
            @include('intake.icon', ['name' => 'bus'])
            <div>
                <strong>ลิงก์นี้สำหรับจอยทริป (เดินทางไปเอง)</strong>
                ไปเจอกันที่จุดหมาย ไม่มีรถของทริปไปรับ — ถ้าคุณอยากไปกับรถ
                บอกทีมงานในช่อง "ฝากบอกทีมงาน" ด้านล่างได้เลย
            </div>
        </div>
    @endif

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

        @if ($scheduleOptions->isNotEmpty())
            <div class="f">
                <label class="field" for="schedule_id">
                    {{ ($closed ?? false) ? 'ย้ายไปรอบอื่นของทริปนี้' : 'เลือกรอบเดินทาง' }}
                </label>
                <select id="schedule_id" name="schedule_id">
                    <option value="">
                        {{ ($closed ?? false) ? 'ยังไม่เปลี่ยนรอบ — รอที่ว่างรอบนี้' : 'ยังไม่ระบุ — ให้ทีมงานแนะนำ' }}
                    </option>
                    @foreach ($scheduleOptions as $option)
                        <option value="{{ $option->id }}" @selected((int) old('schedule_id') === $option->id)>
                            {{ $option->trip->title }} · {{ $option->departureLabelShort() }}
                            ({{ $option->is_charter ? 'รอบเหมา' : 'เหลือ '.$option->bookable_seats.' ที่' }})
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        @if ($mayChooseType ?? false)
            @include('intake.booking-type')
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

        @include('intake.person-fields', [
                'isInternational' => (bool) $trip?->isInternational(),
                'pickupPoints' => $pickupPoints ?? collect(),
            ])

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
                <span>{{ \App\Models\CustomerIntakePerson::CONSENT_TEXT }}</span>
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
