@extends('intake.layout')

@section('title', __('ข้อมูลผู้เดินทางของกลุ่ม'))

@php
    $heroImage = $trip?->cover_image ? url($trip->cover_image) : null;
    $heroTitle = $trip?->tr('title') ?: __('ข้อมูลผู้เดินทาง');
    $heroEyebrow = __('ข้อมูลผู้เดินทางของกลุ่ม');
    $heroEyebrowIcon = 'users';
    $filledCount = count($filled);
    $partySize = max($intake->party_size, $filledCount);
    $percent = $partySize > 0 ? min(100, (int) round($filledCount / $partySize * 100)) : 0;
    $canBook = $intake->acceptsSubmissions() && ! ($bookBlock ?? 'x');
    $heroSub = match (true) {
        ($groupBooking ?? null)?->status === 'confirmed' => __('กลุ่มนี้จองสำเร็จแล้ว'),
        ($groupBooking ?? null)?->status === 'pending' => __('รอชำระเงินเพื่อยืนยันที่นั่ง'),
        ! $intake->acceptsSubmissions() => __('ทีมงานเปิดการจองให้กลุ่มนี้เรียบร้อยแล้ว'),
        ($closed ?? false) => __('รอบนี้เต็มแล้ว ทีมงานจะติดต่อกลับเรื่องรอบอื่นหรือคิวรอที่นั่ง'),
        $canBook && $intake->isComplete() => __('กรอกครบทุกคนแล้ว กดจองและชำระเงินด้านล่างได้เลย'),
        $intake->isComplete() => __('กรอกครบทุกคนแล้ว ทีมงานจะติดต่อกลับเพื่อยืนยันที่นั่ง'),
        default => __('ส่งลิงก์นี้ให้เพื่อนในกลุ่ม แล้วแต่ละคนกรอกข้อมูลของตัวเองได้เลย'),
    };
    $heroChips = [];
    if ($trip && $schedule) {
        $heroChips[] = ['icon' => 'calendar', 'text' => $schedule->dateRangeLabel()];
    }
    $heroChips[] = ['icon' => 'users', 'text' => __('กรอกแล้ว ').$filledCount.' / '.$partySize.__(' คน')];

    // ลิงก์นี้ลูกค้าเป็นคนส่งต่อในแชทกลุ่มเอง การ์ดจึงต้องอธิบายแทนเขาได้
    // ห้ามมีชื่อใครในนี้ — แชทกลุ่มมีคนอ่านมากกว่าคนในทริป
    $ogTitle = __('กรอกข้อมูลผู้เดินทางของกลุ่ม').($trip ? ' · '.$trip->title : '');
    $ogDescription = ($schedule ? $schedule->dateRangeLabel().' — ' : '')
        .__('แต่ละคนกรอกข้อมูลของตัวเองได้ คนละเวลา ใช้เวลาประมาณ 2 นาที');
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

    @if (($closed ?? false) && $intake->acceptsSubmissions())
        <div class="callout callout--warn">
            @include('intake.icon', ['name' => 'alert'])
            <div>
                <strong>{{ __('รอบนี้ปิดรับแล้ว') }}</strong>
                {{ __('เพื่อนที่ยังไม่ได้กรอกยังกรอกต่อได้ ข้อมูลไม่หาย — ทีมงานจะติดต่อกลับ เรื่องรอบอื่นหรือคิวรอที่นั่งให้ทั้งกลุ่ม') }}
            </div>
        </div>
    @endif

    {{-- เพื่อนที่กดลิงก์กลุ่มตามมาไม่ได้เห็นลิงก์แรกที่คนเปิดกลุ่มกดเข้ามา จึงไม่รู้
         ว่ากลุ่มนี้ตกลงกันว่าไปเอง — ต้องบอกซ้ำที่นี่ ไม่งั้นจะมายืนรอรถ --}}
    @if ($intake->isJoinTrip())
        <div class="callout callout--ok">
            @include('intake.icon', ['name' => 'bus'])
            <div>
                <strong>{{ __('กลุ่มนี้เป็นจอยทริป (เดินทางไปเอง)') }}</strong>
                {{ __('ไปเจอกันที่จุดหมาย ไม่มีรถของทริปไปรับ จึงไม่ต้องเลือกจุดขึ้นรถ') }}
            </div>
        </div>
    @endif

    @if ($justFilled)
        <div class="callout callout--ok">
            @include('intake.icon', ['name' => 'check-circle'])
            <div>
                <strong>{{ __('บันทึกข้อมูลของ :justfilled เรียบร้อยแล้ว', ['justfilled' => $justFilled]) }}</strong>
                @if ($intake->isComplete() && $canBook)
                    {{ __('ครบทุกคนแล้ว กดจองและชำระเงินด้านล่างได้เลย ไม่ต้องรอทีมงาน') }}
                @elseif ($intake->isComplete())
                    {{ __('ครบทุกคนแล้ว ทีมงานจะติดต่อกลับเพื่อยืนยันที่นั่ง') }}
                @else
                    {{ __('ยังรอเพื่อนอีก :missingcount คน ส่งลิงก์ด้านล่างให้เขาได้เลย', ['missingcount' => $intake->missingCount()]) }}
                @endif
            </div>
        </div>
    @endif

    {{-- กลุ่มที่กรอกครบมาก่อนเห็นปุ่มจองก่อนลิงก์แชร์ — นั่นคือสิ่งที่เขาต้องทำต่อ
         ส่วนกลุ่มที่ยังรอเพื่อน ลิงก์แชร์สำคัญกว่า การ์ดจึงไปอยู่ใต้ความคืบหน้า --}}
    @if (($groupBooking ?? null) || $intake->isComplete() || ! $intake->acceptsSubmissions())
        @include('intake.book-card')
    @endif

    <div class="share">
        @if ($intake->acceptsSubmissions())
            <h2>{{ __('ส่งลิงก์นี้ให้เพื่อนกรอกข้อมูลของตัวเอง') }}</h2>
            <p>
                {{ __('แต่ละคนกรอกของตัวเองได้ คนละเวลา ข้อมูลที่กรอกไปแล้วจะถูกเก็บไว้จนกว่าจะครบ ไม่ต้องรีบกรอกให้เสร็จในครั้งเดียว') }}
            </p>

            <input class="share-url" type="text" id="share-url" readonly
                   value="{{ $intake->groupUrl() }}" aria-label="{{ __('ลิงก์ของกลุ่ม') }}" onclick="this.select()">

            <div class="share-actions">
                <button class="btn" type="button" id="share-btn" hidden>
                    @include('intake.icon', ['name' => 'share']) {{ __('ส่งต่อให้เพื่อน') }}
                </button>
                <button class="btn btn--ghost" type="button" id="copy-btn">
                    @include('intake.icon', ['name' => 'copy']) <span>{{ __('คัดลอกลิงก์') }}</span>
                </button>
            </div>
        @else
            {{-- ปิดรับแล้ว ส่งลิงก์ต่อไปก็กรอกไม่ได้ เหลือไว้แค่สรุปให้ดู --}}
            <h2>{{ __('สรุปข้อมูลของกลุ่ม') }}</h2>
        @endif

        <div class="progress @if (! $intake->acceptsSubmissions()) progress--bare @endif">
            <div class="progress-head">
                <span>{{ __('ความคืบหน้าของกลุ่ม') }}</span>
                <b>{{ __(':filledcount / :partysize คน', ['filledcount' => $filledCount, 'partysize' => $partySize]) }}</b>
            </div>
            <div class="progress-track" role="img" aria-label="{{ __('กรอกแล้ว :filledcount จาก :partysize คน', ['filledcount' => $filledCount, 'partysize' => $partySize]) }}">
                <div class="progress-fill" style="width: {{ $percent }}%"></div>
            </div>

            <ul class="roster">
                @foreach ($filled as $label)
                    <li>
                        <span class="avatar">{{ mb_substr($label, 0, 1) }}</span>
                        <span>{{ $label }}</span>
                        <span class="state state--done">@include('intake.icon', ['name' => 'check']) {{ __('กรอกแล้ว') }}</span>
                    </li>
                @endforeach
                @for ($i = 0; $i < $intake->missingCount(); $i++)
                    <li>
                        <span class="avatar avatar--wait">?</span>
                        <span class="waiting">{{ __('รอเพื่อนกรอก') }}</span>
                        <span class="state">@include('intake.icon', ['name' => 'clock']) {{ __('ยังไม่ได้กรอก') }}</span>
                    </li>
                @endfor
            </ul>
        </div>
    </div>

    @unless (($groupBooking ?? null) || $intake->isComplete() || ! $intake->acceptsSubmissions())
        @include('intake.book-card')
    @endunless

    @if ($errors->any())
        <div class="callout callout--error">
            @include('intake.icon', ['name' => 'alert'])
            <div>
                <strong>{{ __('กรุณาตรวจสอบข้อมูลอีกครั้ง') }}</strong>
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
            {{ __('ถ้าคุณเพิ่งเปิดลิงก์นี้จากเพื่อน กรอกข้อมูลของ') }} <strong>{{ __('ตัวคุณเอง') }}</strong> {{ __('ด้านล่างได้เลย กรอกซ้ำด้วยเบอร์เดิมจะเป็นการแก้ไขของเดิม ไม่ใช่เพิ่มคนใหม่') }}
        </p>

        <form method="POST" action="{{ route('public.intake.group.submit', $intake->token) }}" enctype="multipart/form-data" data-guard>
            @csrf

            @include('intake.person-fields', [
                'docMode' => $trip?->travelDocumentMode(),
                'pickupPoints' => $pickupPoints ?? collect(),
                'seatMap' => $seatMap ?? null,
            ])

            <div class="form-actions">
                <label class="check check--consent" style="margin-bottom:14px">
                    <input type="checkbox" name="consent" value="1" required @checked(old('consent'))>
                    <span>{{ \App\Models\CustomerIntakePerson::CONSENT_TEXT }}</span>
                </label>

                <button type="submit" class="btn">
                    @include('intake.icon', ['name' => 'send']) {{ __('บันทึกข้อมูลของฉัน') }}
                </button>
            </div>
        </form>
    @include('partials.travel-document-toggle')
    @elseif (! ($groupBooking ?? null))
        {{-- ทีมงานดึงไปเปิดใบจองเองแล้ว (การ์ดจองด้านบนพูดแทนเมื่อมีใบที่ยังรอ/จ่ายแล้ว) --}}
        <div class="callout callout--ok">
            @include('intake.icon', ['name' => 'check-circle'])
            <div>
                <strong>{{ __('ทีมงานเปิดการจองให้กลุ่มนี้เรียบร้อยแล้ว') }}</strong>
                {{ __('หากต้องการแก้ไขข้อมูล กรุณาทักหาทีมงานได้เลย') }}
            </div>
        </div>
    @endif

    <div class="privacy">
        @include('intake.icon', ['name' => 'lock'])
        <span>
            {{ __('ลิงก์นี้เห็นได้เฉพาะชื่อเล่นว่าใครกรอกไปแล้วบ้าง ไม่แสดงเบอร์โทร เลขบัตรประชาชน หรือข้อมูลสุขภาพของใครทั้งสิ้น') }}
        </span>
    </div>
@endsection

@push('scripts')
<script>
    (function () {
        var field = document.getElementById('share-url');
        var copyBtn = document.getElementById('copy-btn');
        var shareBtn = document.getElementById('share-btn');
        var shareText = @json(($trip?->tr('title') ? $trip->tr('title').' — ' : '').__('กรอกข้อมูลผู้เดินทางของคุณที่ลิงก์นี้ได้เลย'));

        function flash(button, message) {
            var label = button.querySelector('span') || button;
            var original = label.textContent;
            label.textContent = message;
            setTimeout(function () { label.textContent = original; }, 2000);
        }

        function copy() {
            // เบราว์เซอร์ในแอปแชทบางตัวไม่มี navigator.clipboard — ถอยไปใช้ execCommand
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(field.value).then(function () { flash(copyBtn, @json(__('คัดลอกแล้ว ✓'))); });
            } else {
                field.select();
                field.setSelectionRange(0, 99999);
                document.execCommand('copy');
                flash(copyBtn, @json(__('คัดลอกแล้ว ✓')));
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
