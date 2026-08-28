@extends('passenger-fill.layout')

@section('title', 'ข้อมูลผู้เดินทางของกลุ่ม')

@push('styles')
<style>
    .ok { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46;
          border-radius: 12px; padding: 13px 15px; font-size: 14px; margin-bottom: 18px; }
    .share { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px;
             padding: 16px; margin-bottom: 20px; }
    .share h2 { font-size: 15px; margin-bottom: 6px; }
    .share p { font-size: 13px; color: #64748b; margin-bottom: 10px; }
    .share-url { display: block; width: 100%; border: 1px solid #cbd5e1; border-radius: 10px;
                 padding: 11px; font-size: 13px; background: #fff; color: #0f172a;
                 font-family: inherit; margin-bottom: 10px; }
    .btn-copy { display: block; width: 100%; border: 1px solid #0B6E5F; border-radius: 10px;
                padding: 11px; background: #fff; color: #0B6E5F; font-size: 14px;
                font-weight: 700; cursor: pointer; font-family: inherit; }
    .roster { list-style: none; margin: 10px 0 0; }
    .roster li { font-size: 14px; color: #334155; padding: 6px 0; border-top: 1px solid #e2e8f0; }
    .roster li:first-child { border-top: none; }
    .roster .waiting { color: #94a3b8; }
    .progress { font-size: 13px; color: #475569; font-weight: 600; }
</style>
@endpush

@section('content')
<div class="card">
    <div class="card-header">
        <div class="brand">ลุยเลเขา</div>
        <h1>ข้อมูลผู้เดินทาง</h1>
        @if ($trip)
            <p>{{ $trip->title }} · {{ $schedule->departureLabelThai() }}</p>
        @else
            <p>คุณ{{ $intake->contact_name }} และเพื่อนร่วมทาง</p>
        @endif
    </div>

    <div class="card-body">
        @if ($justFilled)
            <div class="ok">
                บันทึกข้อมูลของ {{ $justFilled }} เรียบร้อยแล้ว
                @if (! $intake->isComplete())
                    — ยังรอเพื่อนอีก {{ $intake->missingCount() }} คน
                @endif
            </div>
        @endif

        <div class="share">
            <h2>ส่งลิงก์นี้ให้เพื่อนกรอกข้อมูลของตัวเอง</h2>
            <p>
                แต่ละคนกรอกของตัวเองได้ คนละเวลา ข้อมูลที่กรอกไปแล้วจะถูกเก็บไว้จนกว่าจะครบ
                ไม่ต้องรีบกรอกให้เสร็จในครั้งเดียว
            </p>
            <input class="share-url" type="text" id="share-url" readonly
                   value="{{ $intake->groupUrl() }}" onclick="this.select()">
            <button class="btn-copy" type="button" id="copy-btn">คัดลอกลิงก์</button>

            <ul class="roster">
                @foreach ($filled as $label)
                    <li>✅ {{ $label }} — กรอกแล้ว</li>
                @endforeach
                @for ($i = 0; $i < $intake->missingCount(); $i++)
                    <li class="waiting">⏳ ยังไม่ได้กรอก</li>
                @endfor
            </ul>
            <p class="progress" style="margin-top:10px">
                กรอกแล้ว {{ count($filled) }} / {{ $intake->party_size }} คน
            </p>
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

        @if ($intake->acceptsSubmissions())
            <div class="section-label">เพิ่มข้อมูลของคุณ</div>
            <p class="lead">
                ถ้าคุณเพิ่งเปิดลิงก์นี้จากเพื่อน กรอกข้อมูลของ <strong>ตัวคุณเอง</strong> ด้านล่างได้เลย
                กรอกซ้ำด้วยเบอร์เดิมจะเป็นการแก้ไขของเดิม ไม่ใช่เพิ่มคนใหม่
            </p>

            <form method="POST" action="{{ route('public.intake.group.submit', $intake->token) }}">
                @csrf

                @include('intake.person-fields', ['isInternational' => (bool) $trip?->isInternational()])

                <label class="check">
                    <input type="checkbox" name="consent" value="1" required @checked(old('consent'))>
                    ยินยอมให้เก็บข้อมูลนี้เพื่อจัดการการเดินทางและทำประกัน
                </label>

                <button type="submit" class="btn">บันทึกข้อมูลของฉัน</button>
            </form>
        @else
            <div class="ok">
                ทีมงานเปิดการจองให้กลุ่มนี้เรียบร้อยแล้ว หากต้องการแก้ไขข้อมูล กรุณาทักหาทีมงานได้เลย
            </div>
        @endif

        <p class="privacy">
            ลิงก์นี้เห็นได้เฉพาะชื่อเล่นว่าใครกรอกไปแล้วบ้าง ไม่แสดงเบอร์โทร เลขบัตรประชาชน
            หรือข้อมูลสุขภาพของใครทั้งสิ้น
        </p>
    </div>
</div>

<script>
    document.getElementById('copy-btn')?.addEventListener('click', function () {
        var field = document.getElementById('share-url');
        var done = function () {
            var button = document.getElementById('copy-btn');
            button.textContent = 'คัดลอกแล้ว ✓';
            setTimeout(function () { button.textContent = 'คัดลอกลิงก์'; }, 2000);
        };
        // เบราว์เซอร์ในแอปแชทบางตัวไม่มี navigator.clipboard — ถอยไปใช้ execCommand
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(field.value).then(done);
        } else {
            field.select();
            document.execCommand('copy');
            done();
        }
    });
</script>
@endsection
