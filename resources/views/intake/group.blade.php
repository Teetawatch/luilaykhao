@extends('intake.layout')

@section('title', 'ข้อมูลผู้เดินทางของกลุ่ม')

@php
    $heroImage = $trip?->cover_image ? url($trip->cover_image) : null;
    $heroTitle = $trip?->title ?: 'ข้อมูลผู้เดินทาง';
    $heroEyebrow = 'ข้อมูลผู้เดินทางของกลุ่ม';
    $heroEyebrowIcon = 'users';
    $filledCount = count($filled);
    $partySize = max($intake->party_size, $filledCount);
    $percent = $partySize > 0 ? min(100, (int) round($filledCount / $partySize * 100)) : 0;
    $heroSub = match (true) {
        ! $intake->acceptsSubmissions() => 'ทีมงานเปิดการจองให้กลุ่มนี้เรียบร้อยแล้ว',
        $intake->isComplete() => 'กรอกครบทุกคนแล้ว ทีมงานจะติดต่อกลับเพื่อยืนยันที่นั่ง',
        default => 'ส่งลิงก์นี้ให้เพื่อนในกลุ่ม แล้วแต่ละคนกรอกข้อมูลของตัวเองได้เลย',
    };
    $heroChips = [];
    if ($trip && $schedule) {
        $heroChips[] = ['icon' => 'calendar', 'text' => $schedule->dateRangeLabelThai()];
    }
    $heroChips[] = ['icon' => 'users', 'text' => 'กรอกแล้ว '.$filledCount.' / '.$partySize.' คน'];
@endphp

@push('styles')
<style>
    .share { border: 1px solid var(--tint-line); background: var(--tint); border-radius: 16px; padding: 16px; margin-bottom: 20px; }
    .share h2 { font-size: 17px; font-weight: 700; color: var(--ink); line-height: 1.35; }
    .share > p { font-size: 15px; color: var(--body); margin-top: 6px; line-height: 1.55; }
    .share-url {
        width: 100%; border: 1px solid var(--line-mid); border-radius: 11px; padding: 11px 12px;
        font-family: inherit; font-size: 15px; background: #fff; color: var(--body);
        margin: 12px 0 10px; text-overflow: ellipsis;
    }
    .share-actions { display: grid; gap: 8px; }
    .share-actions .btn { padding: 13px; font-size: 16.5px; }

    .progress { margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--tint-line); }
    .progress--bare { margin-top: 12px; padding-top: 0; border-top: none; }
    .progress-head { display: flex; align-items: baseline; justify-content: space-between; font-size: 15px; color: var(--body); }
    .progress-head b { font-size: 16px; color: var(--canopy-dark); font-weight: 700; }
    .progress-track { height: 8px; border-radius: 999px; background: #DCEAE2; overflow: hidden; margin-top: 8px; }
    .progress-fill { height: 100%; background: var(--canopy); border-radius: 999px; }

    .roster { list-style: none; display: grid; gap: 9px; margin-top: 14px; }
    .roster li { display: flex; align-items: center; gap: 10px; font-size: 15.5px; color: var(--ink); }
    .roster .avatar {
        flex: 0 0 32px; width: 32px; height: 32px; border-radius: 50%;
        display: grid; place-items: center; font-size: 15px; font-weight: 700;
        background: var(--canopy); color: #fff;
    }
    .roster .avatar--wait { background: #fff; border: 1px dashed var(--line-mid); color: var(--muted); }
    .roster .state { margin-left: auto; font-size: 14px; color: var(--muted); display: inline-flex; align-items: center; gap: 5px; }
    .roster .state--done { color: var(--canopy); }
    .roster .state .ic { width: 15px; height: 15px; }
    .roster .waiting { color: var(--muted); }
</style>
@endpush

@section('content')
    @if ($schedule)
        @include('intake.round', ['schedule' => $schedule, 'roundLabel' => 'รอบเดินทางของกลุ่มนี้'])
    @endif

    @if ($justFilled)
        <div class="callout callout--ok">
            @include('intake.icon', ['name' => 'check-circle'])
            <div>
                <strong>บันทึกข้อมูลของ {{ $justFilled }} เรียบร้อยแล้ว</strong>
                @if ($intake->isComplete())
                    ครบทุกคนแล้ว ทีมงานจะติดต่อกลับเพื่อยืนยันที่นั่ง
                @else
                    ยังรอเพื่อนอีก {{ $intake->missingCount() }} คน ส่งลิงก์ด้านล่างให้เขาได้เลย
                @endif
            </div>
        </div>
    @endif

    <div class="share">
        @if ($intake->acceptsSubmissions())
            <h2>ส่งลิงก์นี้ให้เพื่อนกรอกข้อมูลของตัวเอง</h2>
            <p>
                แต่ละคนกรอกของตัวเองได้ คนละเวลา ข้อมูลที่กรอกไปแล้วจะถูกเก็บไว้จนกว่าจะครบ
                ไม่ต้องรีบกรอกให้เสร็จในครั้งเดียว
            </p>

            <input class="share-url" type="text" id="share-url" readonly
                   value="{{ $intake->groupUrl() }}" aria-label="ลิงก์ของกลุ่ม" onclick="this.select()">

            <div class="share-actions">
                <button class="btn" type="button" id="share-btn" hidden>
                    @include('intake.icon', ['name' => 'share']) ส่งต่อให้เพื่อน
                </button>
                <button class="btn btn--ghost" type="button" id="copy-btn">
                    @include('intake.icon', ['name' => 'copy']) <span>คัดลอกลิงก์</span>
                </button>
            </div>
        @else
            {{-- ปิดรับแล้ว ส่งลิงก์ต่อไปก็กรอกไม่ได้ เหลือไว้แค่สรุปให้ดู --}}
            <h2>สรุปข้อมูลของกลุ่ม</h2>
        @endif

        <div class="progress @if (! $intake->acceptsSubmissions()) progress--bare @endif">
            <div class="progress-head">
                <span>ความคืบหน้าของกลุ่ม</span>
                <b>{{ $filledCount }} / {{ $partySize }} คน</b>
            </div>
            <div class="progress-track" role="img" aria-label="กรอกแล้ว {{ $filledCount }} จาก {{ $partySize }} คน">
                <div class="progress-fill" style="width: {{ $percent }}%"></div>
            </div>

            <ul class="roster">
                @foreach ($filled as $label)
                    <li>
                        <span class="avatar">{{ mb_substr($label, 0, 1) }}</span>
                        <span>{{ $label }}</span>
                        <span class="state state--done">@include('intake.icon', ['name' => 'check']) กรอกแล้ว</span>
                    </li>
                @endforeach
                @for ($i = 0; $i < $intake->missingCount(); $i++)
                    <li>
                        <span class="avatar avatar--wait">?</span>
                        <span class="waiting">รอเพื่อนกรอก</span>
                        <span class="state">@include('intake.icon', ['name' => 'clock']) ยังไม่ได้กรอก</span>
                    </li>
                @endfor
            </ul>
        </div>
    </div>

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

    @if ($intake->acceptsSubmissions())
        <p class="lead">
            ถ้าคุณเพิ่งเปิดลิงก์นี้จากเพื่อน กรอกข้อมูลของ <strong>ตัวคุณเอง</strong> ด้านล่างได้เลย
            กรอกซ้ำด้วยเบอร์เดิมจะเป็นการแก้ไขของเดิม ไม่ใช่เพิ่มคนใหม่
        </p>

        <form method="POST" action="{{ route('public.intake.group.submit', $intake->token) }}" data-guard>
            @csrf

            @include('intake.person-fields', ['isInternational' => (bool) $trip?->isInternational()])

            <div class="form-actions">
                <label class="check check--consent" style="margin-bottom:14px">
                    <input type="checkbox" name="consent" value="1" required @checked(old('consent'))>
                    <span>ยินยอมให้เก็บข้อมูลนี้เพื่อจัดการการเดินทางและทำประกัน</span>
                </label>

                <button type="submit" class="btn">
                    @include('intake.icon', ['name' => 'send']) บันทึกข้อมูลของฉัน
                </button>
            </div>
        </form>
    @else
        <div class="callout callout--ok">
            @include('intake.icon', ['name' => 'check-circle'])
            <div>
                <strong>ทีมงานเปิดการจองให้กลุ่มนี้เรียบร้อยแล้ว</strong>
                หากต้องการแก้ไขข้อมูล กรุณาทักหาทีมงานได้เลย
            </div>
        </div>
    @endif

    <div class="privacy">
        @include('intake.icon', ['name' => 'lock'])
        <span>
            ลิงก์นี้เห็นได้เฉพาะชื่อเล่นว่าใครกรอกไปแล้วบ้าง ไม่แสดงเบอร์โทร เลขบัตรประชาชน
            หรือข้อมูลสุขภาพของใครทั้งสิ้น
        </span>
    </div>
@endsection

@push('scripts')
<script>
    (function () {
        var field = document.getElementById('share-url');
        var copyBtn = document.getElementById('copy-btn');
        var shareBtn = document.getElementById('share-btn');
        var shareText = @json(($trip?->title ? $trip->title.' — ' : '').'กรอกข้อมูลผู้เดินทางของคุณที่ลิงก์นี้ได้เลย');

        function flash(button, message) {
            var label = button.querySelector('span') || button;
            var original = label.textContent;
            label.textContent = message;
            setTimeout(function () { label.textContent = original; }, 2000);
        }

        function copy() {
            // เบราว์เซอร์ในแอปแชทบางตัวไม่มี navigator.clipboard — ถอยไปใช้ execCommand
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(field.value).then(function () { flash(copyBtn, 'คัดลอกแล้ว ✓'); });
            } else {
                field.select();
                field.setSelectionRange(0, 99999);
                document.execCommand('copy');
                flash(copyBtn, 'คัดลอกแล้ว ✓');
            }
        }

        copyBtn?.addEventListener('click', copy);

        if (navigator.share && shareBtn) {
            shareBtn.hidden = false;
            shareBtn.addEventListener('click', function () {
                navigator.share({ title: 'ลุยเลเขา', text: shareText, url: field.value }).catch(function () {});
            });
        }
    })();
</script>
@endpush
