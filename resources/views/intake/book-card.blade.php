{{--
    การ์ด "จองและชำระเงินเลย" บนหน้ากลุ่ม (/g/{token})

    เหตุผลที่มีการ์ดนี้: เดิมกลุ่มที่กรอกครบต้องรอทีมงานเปิดใบจองแล้วส่ง QR ในแชท
    ระหว่างรอ ที่นั่งไม่ได้ถูกกันไว้ คนที่จองในแอปแล้วจ่ายก่อนจึงได้ไป ตอนนี้กลุ่มกด
    จองเองได้ ที่นั่งถูกกันไว้ตามหน้าต่างชำระเงินเดียวกับแอป

    ต้องส่งเข้ามา: $intake, $groupBooking, $bookBlock, $estimate, $bookingTerms
--}}
@php
    $windowMinutes = \App\Models\Booking::PENDING_TTL_MINUTES;
    $bookErrors = $errors->getBag('book');
@endphp

@if ($groupBooking && $groupBooking->status === 'confirmed')
    <div class="book book--done">
        <div class="book-head">
            <span class="book-ic">@include('intake.icon', ['name' => 'check-circle'])</span>
            <div>
                <h2>จองสำเร็จแล้ว</h2>
                <p>เลขที่จอง {{ $groupBooking->booking_ref }} · ดูรายละเอียดทริป ห้องแชท และ QR ขึ้นรถได้ในแอปลุยเลเขา</p>
            </div>
        </div>
        <a class="btn" href="{{ $intake->payUrl() }}">@include('intake.icon', ['name' => 'check']) ดูการจองของกลุ่มนี้</a>
    </div>
@elseif ($groupBooking && $groupBooking->status === 'pending')
    <div class="book book--waiting">
        <div class="book-head">
            <span class="book-ic">@include('intake.icon', ['name' => 'clock'])</span>
            <div>
                <h2>รอชำระเงินเพื่อยืนยันที่นั่ง</h2>
                <p>
                    @if ($groupBooking->slip_ocr_status !== null)
                        ได้รับสลิปแล้ว ทีมงานกำลังตรวจสอบยอดโอน ที่นั่งยังกันไว้ให้กลุ่มนี้
                    @else
                        เปิดการจอง {{ $groupBooking->booking_ref }} ไว้แล้ว ชำระเงินให้ทันเวลา ไม่งั้นระบบจะคืนที่นั่ง
                    @endif
                </p>
            </div>
        </div>
        <a class="btn" href="{{ $intake->payUrl() }}">@include('intake.icon', ['name' => 'send']) ไปหน้าชำระเงิน</a>
    </div>
@elseif ($intake->acceptsSubmissions())
    <div class="book" id="book">
        @if ($bookErrors->any())
            <div class="callout callout--error">
                @include('intake.icon', ['name' => 'alert'])
                <div><strong>จองไม่สำเร็จ</strong>{{ $bookErrors->first() }}</div>
            </div>
        @endif

        @if ($bookBlock)
            <div class="book-head">
                <span class="book-ic book-ic--muted">@include('intake.icon', ['name' => 'info'])</span>
                <div>
                    <h2>ยังกดจองเองไม่ได้</h2>
                    <p>{{ $bookBlock }}</p>
                </div>
            </div>
        @else
            <div class="book-head">
                <span class="book-ic">@include('intake.icon', ['name' => 'sparkle'])</span>
                <div>
                    <h2>จองและชำระเงินได้เลย</h2>
                    <p>ไม่ต้องรอทีมงานส่ง QR — ที่นั่งเป็นของกลุ่มนี้ทันทีที่ชำระเงิน</p>
                </div>
            </div>

            @if ($estimate)
                <dl class="book-sum">
                    <div><dt>ผู้เดินทาง</dt><dd>{{ $estimate['people'] }} คน</dd></div>
                    <div><dt>ยอดรวมโดยประมาณ</dt><dd>฿{{ number_format($estimate['total']) }}</dd></div>
                    <div>
                        <dt>วิธีชำระ</dt>
                        <dd>{{ $estimate['deposit_enabled'] ? 'จ่ายเต็ม หรือมัดจำก่อนก็ได้' : 'ชำระเต็มจำนวน' }}</dd>
                    </div>
                </dl>
            @endif

            @if ($intake->missingCount() > 0)
                <div class="callout callout--warn">
                    @include('intake.icon', ['name' => 'alert'])
                    <div>
                        <strong>ยังรอเพื่อนอีก {{ $intake->missingCount() }} คน</strong>
                        ถ้ากดจองตอนนี้ จะจองให้เฉพาะ {{ $estimate['people'] ?? 0 }} คนที่กรอกแล้ว
                        เพื่อนที่ยังไม่กรอกจะเพิ่มเข้าการจองนี้ไม่ได้ ต้องจองแยกอีกใบ
                    </div>
                </div>
            @endif

            <p class="book-timer">
                @include('intake.icon', ['name' => 'clock'])
                <span>กดจองแล้วมีเวลาชำระเงิน {{ $windowMinutes }} นาที ระหว่างนี้ที่นั่งถูกกันไว้ให้
                    ถ้าไม่ชำระทัน ระบบจะคืนที่นั่งให้คนอื่นอัตโนมัติ</span>
            </p>

            <form method="POST" action="{{ route('public.intake.book', $intake->token) }}" data-guard>
                @csrf
                <input type="hidden" name="terms_version" value="{{ config('legal.terms_version') }}">

                <details class="book-terms">
                    <summary>อ่านเงื่อนไขการจอง</summary>
                    <ol>
                        @foreach ($bookingTerms as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ol>
                </details>

                <label class="check check--consent">
                    <input type="checkbox" name="accepted_terms" value="1" required>
                    <span>อ่านและยอมรับเงื่อนไขการจองแล้ว</span>
                </label>

                <button type="submit" class="btn">
                    @include('intake.icon', ['name' => 'send']) จองและไปหน้าชำระเงิน
                </button>
            </form>
        @endif
    </div>
@endif

@once
    @push('styles')
    <style>
        .book { border: 1px solid var(--canopy); border-radius: 16px; padding: 16px; margin-bottom: 20px; background: #fff; }
        .book--waiting { border-color: var(--amber-line); background: var(--amber-bg); }
        .book--done { border-color: var(--tint-line); background: var(--tint); }
        .book-head { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 14px; }
        .book-head h2 { font-size: 18px; font-weight: 700; color: var(--ink); line-height: 1.3; }
        .book-head p { font-size: 15px; color: var(--body); margin-top: 3px; line-height: 1.5; }
        .book-ic {
            flex: 0 0 38px; width: 38px; height: 38px; border-radius: 12px;
            display: grid; place-items: center; background: var(--canopy); color: #fff;
        }
        .book-ic .ic { width: 20px; height: 20px; }
        .book-ic--muted { background: var(--tint); color: var(--canopy-dark); border: 1px solid var(--tint-line); }
        .book--waiting .book-ic { background: var(--amber); }
        .book-sum { border: 1px solid var(--line); border-radius: 12px; margin-bottom: 14px; }
        .book-sum > div { display: flex; justify-content: space-between; gap: 12px; padding: 10px 13px; font-size: 15px; }
        .book-sum > div + div { border-top: 1px solid var(--line); }
        .book-sum dt { color: var(--muted); }
        .book-sum dd { color: var(--ink); font-weight: 700; text-align: right; }
        .book-timer { display: flex; gap: 8px; align-items: flex-start; font-size: 14.5px; color: var(--body); line-height: 1.5; margin-bottom: 14px; }
        .book-timer .ic { margin-top: 2px; color: var(--canopy); }
        .book-terms { border: 1px solid var(--line); border-radius: 12px; padding: 10px 13px; margin-bottom: 12px; font-size: 14.5px; }
        .book-terms summary { cursor: pointer; font-weight: 700; color: var(--canopy-dark); }
        .book-terms ol { margin: 10px 0 2px 18px; display: grid; gap: 6px; color: var(--body); line-height: 1.5; }
        .book .check--consent { margin-bottom: 14px; }
    </style>
    @endpush
@endonce
